<?php

namespace App\Services\PaymentProviders\PeachPayments;

use App\Client\PeachPaymentsClient;
use App\Constants\OrderStatus;
use App\Constants\OrderStatusConstants;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\OrderService;
use App\Services\SubscriptionService;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PeachPaymentsWebhookHandler
{
    public function __construct(
        private SubscriptionService $subscriptionService,
        private TransactionService $transactionService,
        private OrderService $orderService,
        private PeachPaymentsClient $client,
    ) {}

    public function handleWebhook(Request $request): JsonResponse
    {
        // Peach sends a one-off JSON "configuration ping" the first time a webhook
        // URL is registered/tested. It carries no signature and must be answered 200.
        if ($request->isJson()) {
            return response()->json(['status' => 'ok']);
        }

        // Verify against the raw body: Peach signs the original wire field names
        // (dot/bracket notation) which $request->all() would have rewritten.
        if (! $this->client->verifyRawSignature($request->getContent())) {
            Log::warning('PeachPayments webhook: invalid signature', [
                'checkoutId' => $request->input('checkoutId'),
                'merchantTransactionId' => $request->input('merchantTransactionId'),
            ]);

            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $paymentProvider = PaymentProvider::where('slug', PaymentProviderConstants::PEACH_PAYMENTS_SLUG)->firstOrFail();

        $checkoutId = (string) $request->input('checkoutId');
        $merchantTransactionId = (string) $request->input('merchantTransactionId');
        $paymentId = (string) ($request->input('id') ?: $checkoutId);
        $resultCode = (string) $request->input('result_code');
        $resultDescription = (string) $request->input('result_description', '');
        $paymentType = (string) $request->input('paymentType', '');
        $amount = (int) round(((float) $request->input('amount', 0)) * 100);
        $currencyCode = strtoupper((string) $request->input('currency', ''));

        $status = $this->mapResultCodeToStatus($resultCode);
        $customParameters = $this->extractCustomParameters($request);

        // Refunds are only meaningful in the context of an order (a Peach "RF" webhook
        // references the original payment/checkout, not a new one) - correlate to an
        // order only and short-circuit before the subscription branches below. Branch on
        // the payment type regardless of outcome so a pending/failed refund event cannot
        // fall through and be misprocessed as an order payment.
        if ($paymentType === 'RF') {
            if ($status !== TransactionStatus::SUCCESS) {
                Log::info('PeachPayments webhook: ignoring non-successful refund event', [
                    'checkoutId' => $checkoutId,
                    'result_code' => $resultCode,
                ]);

                return response()->json(['status' => 'ignored']);
            }

            $order = $this->resolveOrder($customParameters, $paymentProvider, $checkoutId, $merchantTransactionId);

            if ($order) {
                return $this->handleOrderPayment(
                    $order,
                    $paymentProvider,
                    $checkoutId,
                    $status,
                    $amount,
                    $currencyCode,
                    $paymentId,
                    $resultCode,
                    $resultDescription,
                    true,
                );
            }

            Log::warning('PeachPayments webhook: could not correlate refund to an order', [
                'checkoutId' => $checkoutId,
                'merchantTransactionId' => $merchantTransactionId,
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // 1. Explicit correlation via customParameters (set by us at checkout creation time).
        if (! empty($customParameters['pm_change_subscription_uuid'])) {
            $subscription = $this->findSubscriptionByUuid($customParameters['pm_change_subscription_uuid']);

            if ($subscription) {
                return $this->handlePaymentMethodChange($subscription, $paymentProvider, $checkoutId, $request, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription);
            }
        }

        if (! empty($customParameters['subscription_uuid'])) {
            $subscription = $this->findSubscriptionByUuid($customParameters['subscription_uuid']);

            if ($subscription) {
                return $this->handleSubscriptionInitialPayment($subscription, $paymentProvider, $checkoutId, $request, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription);
            }
        }

        if (! empty($customParameters['order_uuid'])) {
            $order = $this->findOrderByUuid($customParameters['order_uuid']);

            if ($order) {
                return $this->handleOrderPayment($order, $paymentProvider, $checkoutId, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription, false);
            }
        }

        // 2. Fallback: lookup by the checkoutId we stored when the checkout was created.
        $order = $this->orderService->findByPaymentProviderOrderId($paymentProvider, $checkoutId);

        if ($order) {
            return $this->handleOrderPayment($order, $paymentProvider, $checkoutId, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription, false);
        }

        $subscription = Subscription::where('payment_provider_id', $paymentProvider->id)
            ->where('extra_payment_provider_data->checkout_id', $checkoutId)
            ->first();

        if ($subscription) {
            // If a registration is already attached, this checkout can only have been a
            // payment-method-change / verification checkout, not the initial payment.
            if ($subscription->payment_provider_subscription_id) {
                return $this->handlePaymentMethodChange($subscription, $paymentProvider, $checkoutId, $request, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription);
            }

            return $this->handleSubscriptionInitialPayment($subscription, $paymentProvider, $checkoutId, $request, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription);
        }

        // 3. Last resort: parse the o-{id} / s-{id} merchantTransactionId we generated.
        if (preg_match('/^o-0*(\d+)$/', $merchantTransactionId, $matches)) {
            $order = Order::find((int) $matches[1]);

            if ($order) {
                return $this->handleOrderPayment($order, $paymentProvider, $checkoutId, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription, false);
            }
        }

        if (preg_match('/^s-0*(\d+)$/', $merchantTransactionId, $matches)) {
            $subscription = Subscription::find((int) $matches[1]);

            if ($subscription) {
                if ($subscription->payment_provider_subscription_id) {
                    return $this->handlePaymentMethodChange($subscription, $paymentProvider, $checkoutId, $request, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription);
                }

                return $this->handleSubscriptionInitialPayment($subscription, $paymentProvider, $checkoutId, $request, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription);
            }
        }

        // Signature was valid but we genuinely can't correlate this to anything of ours
        // (e.g. a stray/test webhook). Answer 200 so Peach doesn't retry for up to 30 days.
        Log::warning('PeachPayments webhook: could not correlate payload to an order or subscription', [
            'checkoutId' => $checkoutId,
            'merchantTransactionId' => $merchantTransactionId,
        ]);

        return response()->json(['status' => 'ignored']);
    }

    private function resolveOrder(array $customParameters, PaymentProvider $paymentProvider, string $checkoutId, string $merchantTransactionId): ?Order
    {
        if (! empty($customParameters['order_uuid'])) {
            $order = $this->findOrderByUuid($customParameters['order_uuid']);

            if ($order) {
                return $order;
            }
        }

        $order = $this->orderService->findByPaymentProviderOrderId($paymentProvider, $checkoutId);

        if ($order) {
            return $order;
        }

        if (preg_match('/^o-0*(\d+)$/', $merchantTransactionId, $matches)) {
            return Order::find((int) $matches[1]);
        }

        return null;
    }

    private function handleOrderPayment(
        Order $order,
        PaymentProvider $provider,
        string $checkoutId,
        TransactionStatus $status,
        int $amount,
        string $currencyCode,
        string $paymentId,
        string $resultCode,
        string $resultDescription,
        bool $isRefund,
    ): JsonResponse {
        return DB::transaction(function () use ($order, $provider, $checkoutId, $status, $amount, $currencyCode, $paymentId, $resultCode, $resultDescription, $isRefund) {
            // Webhooks can retry for up to 30 days and can arrive out of order, so we
            // lock the row to keep processing sequential (Stripe handler pattern).
            $order = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($isRefund) {
                $this->refundOrder($order, $resultCode, $amount);

                return response()->json();
            }

            $orderStatus = match ($status) {
                TransactionStatus::SUCCESS => OrderStatus::SUCCESS,
                TransactionStatus::FAILED => OrderStatus::FAILED,
                TransactionStatus::PENDING => OrderStatus::PENDING,
                default => null,
            };

            $currentStatus = OrderStatus::tryFrom($order->status);

            // Never move an order out of a final state on a late/duplicate webhook.
            if ($currentStatus && in_array($currentStatus, OrderStatusConstants::FINAL_STATUSES, true)) {
                return response()->json();
            }

            $transaction = $this->resolveExistingTransaction($paymentId, $checkoutId);

            if ($transaction) {
                $this->transactionService->updateTransaction(
                    $transaction,
                    $resultCode,
                    $status,
                    $status === TransactionStatus::FAILED ? $resultDescription : null,
                );
            } else {
                $currency = Currency::where('code', $currencyCode)->first();

                if ($currency) {
                    $this->transactionService->createForOrder(
                        $order,
                        $amount ?: (int) $order->total_amount_after_discount,
                        0,
                        (int) ($order->total_discount_amount ?? 0),
                        0,
                        $currency,
                        $provider,
                        $paymentId,
                        $resultCode,
                        $status,
                    );
                }
            }

            if ($orderStatus !== null) {
                $this->orderService->updateOrder($order, [
                    'status' => $orderStatus->value,
                    'payment_provider_order_id' => $checkoutId,
                    'payment_provider_id' => $provider->id,
                ]);
            }

            return response()->json();
        });
    }

    private function refundOrder(Order $order, string $resultCode, int $refundAmountMinor): void
    {
        // The RF webhook's own `id` belongs to the refund event, not the original
        // payment, so we look up the order's existing successful transaction instead
        // of trying to correlate by payment id.
        $transaction = $order->transactions()
            ->where('status', TransactionStatus::SUCCESS->value)
            ->latest('id')
            ->first();

        // Partial refunds have no local representation in SaasyKit: marking the whole
        // order REFUNDED for a 10% refund would be wrong, so log and leave state alone.
        if ($transaction && $refundAmountMinor > 0 && $refundAmountMinor < (int) $transaction->amount) {
            Log::warning('PeachPayments webhook: partial refund received - not supported, order left unchanged', [
                'order_uuid' => $order->uuid,
                'transaction_amount' => (int) $transaction->amount,
                'refund_amount' => $refundAmountMinor,
            ]);

            return;
        }

        if ($transaction) {
            $this->transactionService->updateTransaction($transaction, $resultCode, TransactionStatus::REFUNDED);
        }

        if ($order->status !== OrderStatus::REFUNDED->value) {
            $this->orderService->updateOrder($order, [
                'status' => OrderStatus::REFUNDED->value,
            ]);
        }
    }

    private function handleSubscriptionInitialPayment(
        Subscription $subscription,
        PaymentProvider $provider,
        string $checkoutId,
        Request $request,
        TransactionStatus $status,
        int $amount,
        string $currencyCode,
        string $paymentId,
        string $resultCode,
        string $resultDescription,
    ): JsonResponse {
        return DB::transaction(function () use ($subscription, $provider, $checkoutId, $request, $status, $amount, $currencyCode, $paymentId, $resultCode) {
            $subscription = Subscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();

            // Never regress an already-active subscription on a late/out-of-order
            // FAILED or PENDING event (webhooks retry for up to 30 days): it would
            // send a bogus payment-failed email or create an orphan pending
            // transaction for a payment that has already been reconciled.
            if ($subscription->status === SubscriptionStatus::ACTIVE->value && $status !== TransactionStatus::SUCCESS) {
                Log::info('PeachPayments webhook: ignoring late non-success event for active subscription', [
                    'subscription_id' => $subscription->id,
                    'checkoutId' => $checkoutId,
                    'result_code' => $resultCode,
                ]);

                return response()->json();
            }

            if ($status === TransactionStatus::FAILED) {
                $this->recordSubscriptionTransaction($subscription, $provider, $amount, $currencyCode, $checkoutId, $paymentId, $resultCode, TransactionStatus::FAILED);
                $this->subscriptionService->handleInvoicePaymentFailed($subscription);

                return response()->json();
            }

            if ($status === TransactionStatus::PENDING) {
                $this->recordSubscriptionTransaction($subscription, $provider, $amount, $currencyCode, $checkoutId, $paymentId, $resultCode, TransactionStatus::PENDING);

                return response()->json();
            }

            // SUCCESS - idempotency guard: if this payment id is already recorded as a
            // successful transaction and the subscription is active, this is a retried
            // webhook - it must not extend ends_at or create a duplicate transaction.
            $extraData = $subscription->extra_payment_provider_data ?? [];
            $existingTransaction = $this->transactionService->getTransactionByPaymentProviderTxId($paymentId);

            if ($subscription->status === SubscriptionStatus::ACTIVE->value
                && $existingTransaction
                && $existingTransaction->status == TransactionStatus::SUCCESS->value
            ) {
                return response()->json();
            }

            [$registrationId, $checkoutStatus] = $this->resolveRegistration($request, $checkoutId);

            $extraData = $this->mergeCardMetadata($extraData, $checkoutId, $registrationId, $checkoutStatus);

            // Store the initial (CIT) transaction id: Peach requires it as
            // standingInstruction.initialTransactionId on every subsequent MIT charge.
            $extraData['initial_transaction_id'] = $paymentId;

            $endsAt = now()->add($subscription->interval->date_identifier, $subscription->interval_count);

            $this->subscriptionService->updateSubscription($subscription, [
                'status' => SubscriptionStatus::ACTIVE->value,
                'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
                'ends_at' => $endsAt,
                'payment_provider_subscription_id' => $registrationId,
                'payment_provider_status' => $resultCode,
                'payment_provider_id' => $provider->id,
                'extra_payment_provider_data' => $extraData,
            ]);

            $this->recordSubscriptionTransaction($subscription, $provider, $amount, $currencyCode, $checkoutId, $paymentId, $resultCode, TransactionStatus::SUCCESS);

            return response()->json();
        });
    }

    private function handlePaymentMethodChange(
        Subscription $subscription,
        PaymentProvider $provider,
        string $checkoutId,
        Request $request,
        TransactionStatus $status,
        int $amount,
        string $currencyCode,
        string $paymentId,
        string $resultCode,
        string $resultDescription,
    ): JsonResponse {
        return DB::transaction(function () use ($subscription, $provider, $checkoutId, $request, $status, $amount, $currencyCode, $paymentId, $resultCode) {
            $subscription = Subscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();

            if ($status !== TransactionStatus::SUCCESS) {
                Log::info('PeachPayments: payment method change checkout was not successful', [
                    'subscription_id' => $subscription->id,
                    'result_code' => $resultCode,
                ]);

                return response()->json();
            }

            [$registrationId, $checkoutStatus] = $this->resolveRegistration($request, $checkoutId);

            if (empty($registrationId)) {
                return response()->json();
            }

            $extraData = $subscription->extra_payment_provider_data ?? [];

            // Idempotency guard: replaying the same successful webhook must not
            // re-trigger anything once the registration has already been swapped.
            if (($extraData['registration_id'] ?? null) === $registrationId) {
                return response()->json();
            }

            $extraData = $this->mergeCardMetadata($extraData, $checkoutId, $registrationId, $checkoutStatus);

            // The stored card was replaced, so the MIT lineage for the new credential
            // restarts here: record this verification charge as the new initial (CIT)
            // transaction. Only when an actual charge occurred (real payment id).
            if ($paymentId !== '' && $paymentId !== $checkoutId) {
                $extraData['initial_transaction_id'] = $paymentId;
            }

            // Only the registration id / card metadata changes here - status, type
            // and ends_at are untouched.
            $this->subscriptionService->updateSubscription($subscription, [
                'payment_provider_subscription_id' => $registrationId,
                'extra_payment_provider_data' => $extraData,
            ]);

            if ($amount > 0) {
                $this->recordSubscriptionTransaction($subscription, $provider, $amount, $currencyCode, $checkoutId, $paymentId, $resultCode, TransactionStatus::SUCCESS);
            }

            return response()->json();
        });
    }

    private function recordSubscriptionTransaction(
        Subscription $subscription,
        PaymentProvider $provider,
        int $amount,
        string $currencyCode,
        string $checkoutId,
        string $paymentId,
        string $resultCode,
        TransactionStatus $status,
    ): void {
        $transaction = $this->resolveExistingTransaction($paymentId, $checkoutId);

        if ($transaction) {
            $this->transactionService->updateTransaction($transaction, $resultCode, $status);

            return;
        }

        $currency = Currency::where('code', $currencyCode)->first();

        if (! $currency) {
            return;
        }

        $this->transactionService->createForSubscription(
            $subscription,
            $amount,
            0,
            0,
            0,
            $currency,
            $provider,
            $paymentId,
            $resultCode,
            $status,
        );
    }

    /**
     * Finds the transaction this webhook refers to, reconciling Peach's transient
     * "pending" checkout event. That first event carries no payment id, so its
     * transaction was keyed on the checkoutId; when the definitive success/failed
     * event later arrives with the real payment id, we re-key that same row onto the
     * payment id instead of creating a duplicate.
     */
    private function resolveExistingTransaction(string $paymentId, string $checkoutId): ?Transaction
    {
        $transaction = $this->transactionService->getTransactionByPaymentProviderTxId($paymentId);

        if ($transaction) {
            return $transaction;
        }

        if ($checkoutId !== '' && $checkoutId !== $paymentId) {
            $pending = $this->transactionService->getTransactionByPaymentProviderTxId($checkoutId);

            if ($pending) {
                $pending->update(['payment_provider_transaction_id' => $paymentId]);

                return $pending;
            }
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: array} [registrationId, raw checkout-status body (empty if not fetched)]
     */
    private function resolveRegistration(Request $request, string $checkoutId): array
    {
        $registrationId = $request->input('registrationId');

        if (! empty($registrationId)) {
            return [$registrationId, []];
        }

        try {
            $checkoutStatus = $this->client->getCheckoutStatus($checkoutId);
        } catch (Throwable $e) {
            Log::warning('PeachPayments: failed to fetch checkout status for registration lookup', [
                'checkoutId' => $checkoutId,
                'error' => $e->getMessage(),
            ]);

            return [null, []];
        }

        $registrationId = $checkoutStatus['registrationId'] ?? ($checkoutStatus['registration']['id'] ?? null);

        return [$registrationId, $checkoutStatus];
    }

    private function mergeCardMetadata(array $extraData, string $checkoutId, ?string $registrationId, array $checkoutStatus): array
    {
        $card = $checkoutStatus['card'] ?? [];

        return array_merge($extraData, array_filter([
            'checkout_id' => $checkoutId,
            'registration_id' => $registrationId,
            'card_brand' => $checkoutStatus['paymentBrand'] ?? $card['brand'] ?? ($extraData['card_brand'] ?? null),
            'card_last4' => $card['last4Digits'] ?? ($extraData['card_last4'] ?? null),
        ], fn ($value) => $value !== null));
    }

    private function findOrderByUuid(?string $uuid): ?Order
    {
        if (! $uuid) {
            return null;
        }

        try {
            return $this->orderService->findByUuidOrFail($uuid);
        } catch (Throwable) {
            return null;
        }
    }

    private function findSubscriptionByUuid(?string $uuid): ?Subscription
    {
        if (! $uuid) {
            return null;
        }

        try {
            return $this->subscriptionService->findByUuidOrFail($uuid);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @see https://developer.peachpayments.com/docs/dashboard-response-codes
     */
    private function mapResultCodeToStatus(string $resultCode): TransactionStatus
    {
        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[12]0)/', $resultCode)) {
            return TransactionStatus::SUCCESS;
        }

        if (preg_match('/^(000\.200)/', $resultCode)) {
            return TransactionStatus::PENDING;
        }

        return TransactionStatus::FAILED;
    }

    /**
     * customParameters can arrive either as a genuinely nested array (the common case
     * for form-urlencoded bodies with `customParameters[key]=value` fields, which PHP
     * parses into a nested array automatically) or, defensively, as literal flat keys
     * named `customParameters[key]` (e.g. if the payload was JSON-encoded upstream).
     */
    private function extractCustomParameters(Request $request): array
    {
        $custom = $request->input('customParameters');

        if (is_array($custom)) {
            return $custom;
        }

        $result = [];

        foreach ($request->all() as $key => $value) {
            if (is_string($key) && preg_match('/^customParameters\[(.+)\]$/', $key, $matches)) {
                $result[$matches[1]] = $value;
            }
        }

        return $result;
    }
}
