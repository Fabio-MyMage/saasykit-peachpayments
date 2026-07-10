<?php

namespace App\Console\Commands;

use App\Client\PeachPaymentsClient;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PeachPaymentsChargeRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:peachpayments-charge-renewals';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Charge due Peach Payments-managed subscription renewals via the recurring (MIT) card API.';

    private int $processedCount = 0;

    private int $renewedCount = 0;

    private int $failedCount = 0;

    private int $canceledCount = 0;

    private int $pendingCount = 0;

    private int $skippedCount = 0;

    public function __construct(
        private PeachPaymentsClient $peachPaymentsClient,
        private SubscriptionService $subscriptionService,
        private TransactionService $transactionService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $paymentProvider = PaymentProvider::where('slug', PaymentProviderConstants::PEACH_PAYMENTS_SLUG)->first();

        if (! $paymentProvider) {
            $this->error('Peach Payments payment provider is not configured. Aborting.');

            return self::FAILURE;
        }

        Subscription::where('payment_provider_id', $paymentProvider->id)
            ->where('type', SubscriptionType::PAYMENT_PROVIDER_MANAGED)
            ->whereIn('status', [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::PAST_DUE->value])
            ->where('ends_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($paymentProvider) {
                foreach ($subscriptions as $subscription) {
                    $this->processSubscription($subscription, $paymentProvider);
                }
            });

        $this->info(sprintf(
            'Done. processed=%d renewed=%d failed=%d canceled=%d pending=%d skipped=%d',
            $this->processedCount,
            $this->renewedCount,
            $this->failedCount,
            $this->canceledCount,
            $this->pendingCount,
            $this->skippedCount,
        ));

        return self::SUCCESS;
    }

    private function processSubscription(Subscription $subscription, PaymentProvider $paymentProvider): void
    {
        $this->processedCount++;

        try {
            // Serialize per-subscription processing: re-fetch under a row lock so two
            // overlapping runs cannot both see the subscription as due and double-charge it.
            DB::transaction(function () use ($subscription, $paymentProvider) {
                $subscription = Subscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail();

                $this->chargeIfDue($subscription, $paymentProvider);
            });
        } catch (Throwable $e) {
            Log::error('PeachPaymentsChargeRenewals: exception while processing subscription.', [
                'subscription_uuid' => $subscription->uuid,
                'exception' => $e->getMessage(),
            ]);

            $this->error(sprintf('[%s] exception: %s', $subscription->uuid, $e->getMessage()));
        }
    }

    private function chargeIfDue(Subscription $subscription, PaymentProvider $paymentProvider): void
    {
        // Re-check due-ness under the lock: a concurrent run may have renewed or
        // canceled this subscription between the outer query and acquiring the lock.
        if (! in_array($subscription->status, [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::PAST_DUE->value], true)
            || $subscription->ends_at === null
            || Carbon::parse($subscription->ends_at)->isFuture()
        ) {
            $this->skippedCount++;

            return;
        }

        // Cancellation requested for end of cycle and the cycle has now ended: stop billing.
        if ($subscription->is_canceled_at_end_of_cycle) {
            $this->subscriptionService->updateSubscription($subscription, [
                'status' => SubscriptionStatus::CANCELED->value,
            ]);

            $this->canceledCount++;
            $this->info(sprintf('[%s] canceled at end of cycle.', $subscription->uuid));

            return;
        }

        $extra = $subscription->extra_payment_provider_data ?? [];

        // Daily retry cadence for subscriptions already flagged PAST_DUE.
        if ($subscription->status === SubscriptionStatus::PAST_DUE->value && ! empty($extra['last_renewal_attempt_at'])) {
            $lastAttemptAt = Carbon::parse($extra['last_renewal_attempt_at']);

            // Absolute diff: Carbon 3 returns signed values by default, which would
            // make this comparison always true for past timestamps.
            if (now()->diffInHours($lastAttemptAt, true) < 24) {
                $this->skippedCount++;
                $this->info(sprintf('[%s] retry not due yet (last attempt %s).', $subscription->uuid, $lastAttemptAt->toIso8601String()));

                return;
            }
        }

        // Idempotency guard: if a successful transaction was already recorded for/after the
        // current period end, the renewal already happened and only the `ends_at` bump failed
        // to persist (or this run is racing a previous one) — skip to avoid double-charging.
        $alreadyRenewed = $subscription->transactions()
            ->where('status', TransactionStatus::SUCCESS->value)
            ->where('created_at', '>=', $subscription->ends_at)
            ->exists();

        if ($alreadyRenewed) {
            $this->skippedCount++;
            $this->info(sprintf('[%s] already has a successful renewal transaction, skipping.', $subscription->uuid));

            return;
        }

        $registrationId = $extra['registration_id'] ?? $subscription->payment_provider_subscription_id ?? null;

        if (! $registrationId) {
            $this->skippedCount++;
            $this->error(sprintf('[%s] no registration id available, cannot charge. Skipping.', $subscription->uuid));
            Log::error('PeachPaymentsChargeRenewals: missing registration id.', ['subscription_uuid' => $subscription->uuid]);

            return;
        }

        $currency = $subscription->currency;
        $currencyCode = $currency->code;

        $amountMinor = (int) $subscription->price;
        $discountMinor = 0;

        if (isset($extra['discount']) && is_array($extra['discount'])) {
            $discount = $extra['discount'];

            if (($discount['type'] ?? null) === 'percentage') {
                $discountMinor = (int) round($amountMinor * ((float) ($discount['amount'] ?? 0) / 100));
            } elseif (isset($discount['amount'])) {
                $discountMinor = (int) $discount['amount'];
            }
        }

        $chargeAmountMinor = max(0, $amountMinor - $discountMinor);

        // Format the decimal amount directly from integer minor units — no float division.
        $chargeAmount = sprintf('%d.%02d', intdiv($chargeAmountMinor, 100), $chargeAmountMinor % 100);

        // 'r' + base36 subscription id + '-' + base36 timestamp: collision-free and
        // comfortably within Peach's 8-16 char merchantTransactionId limit for decades.
        $merchantTransactionId = sprintf(
            'r%s-%s',
            base_convert((string) $subscription->id, 10, 36),
            base_convert((string) now()->timestamp, 10, 36)
        );

        $chargeExtra = ['merchantTransactionId' => $merchantTransactionId];

        // Peach requires the initial (CIT) transaction id on every subsequent MIT charge.
        // Omit it if unknown rather than sending a wrong value (it is NOT the registration id).
        if (! empty($extra['initial_transaction_id'])) {
            $chargeExtra['standingInstruction.initialTransactionId'] = $extra['initial_transaction_id'];
        }

        $response = $this->peachPaymentsClient->chargeRegistration(
            $registrationId,
            $chargeAmount,
            $currencyCode,
            $chargeExtra
        );

        $resultCode = (string) ($response['result']['code'] ?? '');
        $paymentId = (string) ($response['id'] ?? $merchantTransactionId);

        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[12]0)/', $resultCode)) {
            $this->handleSuccessfulRenewal($subscription, $paymentProvider, $currency, $chargeAmountMinor, $discountMinor, $paymentId, $resultCode, $extra);

            return;
        }

        if (preg_match('/^(000\.200)/', $resultCode)) {
            // Pending: not a definitive outcome yet, leave subscription state untouched and
            // just record the transaction/status so a future run (or a webhook, if configured) can settle it.
            $this->transactionService->createForSubscription(
                $subscription,
                $chargeAmountMinor,
                0,
                $discountMinor,
                0,
                $currency,
                $paymentProvider,
                $paymentId,
                $resultCode,
                TransactionStatus::PENDING,
            );

            $this->pendingCount++;
            $this->info(sprintf('[%s] renewal charge pending (result_code=%s).', $subscription->uuid, $resultCode));

            return;
        }

        $this->handleFailedRenewal($subscription, $paymentProvider, $currency, $chargeAmountMinor, $discountMinor, $paymentId, $resultCode, $extra);
    }

    private function handleSuccessfulRenewal(
        Subscription $subscription,
        PaymentProvider $paymentProvider,
        $currency,
        int $chargeAmountMinor,
        int $discountMinor,
        string $paymentId,
        string $resultCode,
        array $extra,
    ): void {
        $this->transactionService->createForSubscription(
            $subscription,
            $chargeAmountMinor,
            0,
            $discountMinor,
            0,
            $currency,
            $paymentProvider,
            $paymentId,
            $resultCode,
            TransactionStatus::SUCCESS,
        );

        $interval = $subscription->interval()->firstOrFail();
        $currentEndsAt = Carbon::parse($subscription->ends_at);
        $candidateEndsAt = $currentEndsAt->copy()->add($interval->date_identifier, $subscription->interval_count);

        // If the plan-relative end date is still in the past (e.g. the command missed several
        // runs), base the new period off "now" instead, to avoid an ever-growing catch-up drift.
        $newEndsAt = $candidateEndsAt->isPast() ? now()->add($interval->date_identifier, $subscription->interval_count) : $candidateEndsAt;

        $extra['renewal_attempts'] = 0;
        unset($extra['last_renewal_attempt_at']);

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => $newEndsAt,
            'payment_provider_status' => $resultCode,
            'extra_payment_provider_data' => $extra,
        ]);

        $this->renewedCount++;
        $this->info(sprintf('[%s] renewed successfully, new ends_at=%s.', $subscription->uuid, $newEndsAt->toIso8601String()));
    }

    private function handleFailedRenewal(
        Subscription $subscription,
        PaymentProvider $paymentProvider,
        $currency,
        int $chargeAmountMinor,
        int $discountMinor,
        string $paymentId,
        string $resultCode,
        array $extra,
    ): void {
        $this->transactionService->createForSubscription(
            $subscription,
            $chargeAmountMinor,
            0,
            $discountMinor,
            0,
            $currency,
            $paymentProvider,
            $paymentId,
            $resultCode,
            TransactionStatus::FAILED,
        );

        $this->subscriptionService->handleInvoicePaymentFailed($subscription);

        $maxAttempts = (int) config('services.peachpayments.max_renewal_retries', 3);
        $attempts = (int) ($extra['renewal_attempts'] ?? 0) + 1;

        $extra['renewal_attempts'] = $attempts;
        $extra['last_renewal_attempt_at'] = now()->toIso8601String();

        $newStatus = $attempts >= $maxAttempts
            ? SubscriptionStatus::CANCELED->value
            : SubscriptionStatus::PAST_DUE->value;

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => $newStatus,
            'payment_provider_status' => $resultCode,
            'extra_payment_provider_data' => $extra,
        ]);

        $this->failedCount++;

        if ($newStatus === SubscriptionStatus::CANCELED->value) {
            Log::warning('PeachPaymentsChargeRenewals: subscription canceled after exhausting renewal retries.', [
                'subscription_uuid' => $subscription->uuid,
                'attempts' => $attempts,
            ]);
            $this->error(sprintf('[%s] renewal failed, max retries (%d) reached, canceled.', $subscription->uuid, $maxAttempts));
        } else {
            $this->error(sprintf('[%s] renewal failed (result_code=%s), attempt %d/%d, marked past_due.', $subscription->uuid, $resultCode, $attempts, $maxAttempts));
        }
    }
}
