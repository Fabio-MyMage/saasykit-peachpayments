<?php

namespace App\Services\PaymentProviders\PeachPayments;

use App\Client\PeachPaymentsClient;
use App\Constants\DiscountConstants;
use App\Constants\PaymentProviderConstants;
use App\Constants\PlanType;
use App\Constants\SubscriptionType;
use App\Models\Discount;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\CalculationService;
use App\Services\OrderService;
use App\Services\PaymentProviders\PaymentProviderInterface;
use App\Services\SubscriptionService;
use Exception;
use Illuminate\Support\Facades\Log;

class PeachPaymentsProvider implements PaymentProviderInterface
{
    /**
     * Peach Payments (Hosted Checkout V2) only supports a fixed set of currencies.
     *
     * @see https://developer.peachpayments.com
     */
    private const SUPPORTED_CURRENCIES = ['ZAR', 'USD', 'KES', 'MUR', 'GBP', 'EUR'];

    public function __construct(
        private PeachPaymentsClient $client,
        private SubscriptionService $subscriptionService,
        private OrderService $orderService,
        private CalculationService $calculationService,
    ) {}

    public function getSlug(): string
    {
        return PaymentProviderConstants::PEACH_PAYMENTS_SLUG;
    }

    public function getName(): string
    {
        return PaymentProvider::where('slug', $this->getSlug())->firstOrFail()->name;
    }

    public function isRedirectProvider(): bool
    {
        return true;
    }

    public function isOverlayProvider(): bool
    {
        return false;
    }

    public function initSubscriptionCheckout(Plan $plan, Subscription $subscription, ?Discount $discount = null): array
    {
        // Peach Payments does not need any initialization before the hosted checkout redirect.
        return [];
    }

    public function initProductCheckout(Order $order, ?Discount $discount = null): array
    {
        // Peach Payments does not need any initialization before the hosted checkout redirect.
        return [];
    }

    public function createProductCheckoutRedirectLink(Order $order, ?Discount $discount = null): string
    {
        $paymentProvider = $this->assertProviderIsActive();

        $currencyCode = $order->currency()->firstOrFail()->code;
        $this->assertSupportedCurrency($currencyCode);

        // total_amount_after_discount is already the fully discounted amount computed by
        // SaasyKit when the order was created, so we do not need to re-apply $discount here.
        $amount = (int) $order->total_amount_after_discount;

        $payload = [
            'merchantTransactionId' => $this->buildOrderMerchantTransactionId($order),
            'amount' => $this->toDecimalAmount($amount),
            'currency' => $currencyCode,
            'shopperResultUrl' => route('payments-providers.peachpayments.checkout-result'),
            'notificationUrl' => route('payments-providers.peachpayments.webhook'),
            'cancelUrl' => route('checkout.product'),
            'customParameters' => [
                'order_uuid' => $order->uuid,
            ],
        ];

        try {
            $response = $this->client->createCheckout($payload);
        } catch (Exception $e) {
            Log::error('Failed to create Peach Payments product checkout: '.$e->getMessage());

            throw $e;
        }

        $this->orderService->updateOrder($order, [
            'payment_provider_order_id' => $response['checkoutId'],
            'payment_provider_id' => $paymentProvider->id,
        ]);

        return $response['redirectUrl'];
    }

    public function createSubscriptionCheckoutRedirectLink(Plan $plan, Subscription $subscription, ?Discount $discount = null): string
    {
        $paymentProvider = $this->assertProviderIsActive();

        $currencyCode = $subscription->currency()->firstOrFail()->code;
        $this->assertSupportedCurrency($currencyCode);

        $trialDays = 0;
        if ($plan->has_trial) {
            $trialDays = $this->subscriptionService->calculateSubscriptionTrialDays($plan);
        }

        $shouldSkipTrial = $this->subscriptionService->shouldSkipTrial($subscription);

        if (! $shouldSkipTrial && $trialDays > 0) {
            // TODO: verify in the Peach sandbox whether a zero-amount registration-only checkout
            // is accepted; if so, trial subscriptions can tokenize the card now and be charged
            // by the renewal command once the trial ends. Until that is verified, we fail fast
            // instead of silently charging the customer during the trial period.
            throw new Exception('Peach Payments does not yet support trial checkouts');
        }

        $planPrice = $this->calculationService->getPlanPrice($plan);

        // First-period charge: subscription price (already resolved for the chosen plan/currency)
        // plus the one-off setup fee (folded into the first charge, see supportsSetupFees()),
        // discounted locally since Peach Payments has no native coupon/discount concept.
        $amount = (int) $subscription->price + (int) ($planPrice->setup_fee ?? 0);
        $amount = $this->applyDiscount($amount, $discount);

        $payload = [
            'merchantTransactionId' => $this->buildSubscriptionMerchantTransactionId($subscription),
            'amount' => $this->toDecimalAmount($amount),
            'currency' => $currencyCode,
            'shopperResultUrl' => route('payments-providers.peachpayments.checkout-result'),
            'notificationUrl' => route('payments-providers.peachpayments.webhook'),
            'cancelUrl' => $this->getSubscriptionCheckoutCancelUrl($plan, $subscription),
            'createRegistration' => true,
            'customParameters' => [
                'subscription_uuid' => $subscription->uuid,
            ],
        ];

        try {
            $response = $this->client->createCheckout($payload);
        } catch (Exception $e) {
            Log::error('Failed to create Peach Payments subscription checkout: '.$e->getMessage());

            throw $e;
        }

        $extraProviderData = array_merge($subscription->extra_payment_provider_data ?? [], [
            'checkout_id' => $response['checkoutId'],
        ]);

        $this->subscriptionService->updateSubscription($subscription, [
            'payment_provider_id' => $paymentProvider->id,
            'extra_payment_provider_data' => $extraProviderData,
        ]);

        return $response['redirectUrl'];
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, bool $withProration = false): bool
    {
        $this->assertProviderIsActive();

        if ($withProration) {
            // Peach Payments' recurring (MIT) charges are not prorated: the new plan price only
            // takes effect on the next scheduled renewal charge.
            Log::warning('Peach Payments does not support prorated plan changes; change will apply on next renewal for subscription: '.$subscription->uuid);
        }

        $planPrice = $this->calculationService->getPlanPrice($newPlan);

        $this->subscriptionService->updateSubscription($subscription, [
            'plan_id' => $newPlan->id,
            'price' => $planPrice->price,
            'currency_id' => $planPrice->currency_id,
            'interval_id' => $newPlan->interval_id,
            'interval_count' => $newPlan->interval_count,
        ]);

        return true;
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        $this->assertProviderIsActive();

        // Purely local: the renewal command will stop charging the registered card once
        // is_canceled_at_end_of_cycle is set and ends_at is reached.
        $this->subscriptionService->updateSubscription($subscription, [
            'is_canceled_at_end_of_cycle' => true,
        ]);

        return true;
    }

    public function discardSubscriptionCancellation(Subscription $subscription): bool
    {
        $this->assertProviderIsActive();

        $this->subscriptionService->updateSubscription($subscription, [
            'is_canceled_at_end_of_cycle' => false,
        ]);

        return true;
    }

    public function getChangePaymentMethodLink(Subscription $subscription): string
    {
        $this->assertProviderIsActive();

        $currencyCode = $subscription->currency()->firstOrFail()->code;
        $this->assertSupportedCurrency($currencyCode);

        // TODO: verify in the Peach sandbox whether a zero-amount registration-only checkout is
        // accepted for card-verification-only flows; until then we use a small nominal amount.
        $payload = [
            'merchantTransactionId' => $this->buildPaymentMethodChangeMerchantTransactionId($subscription),
            'amount' => $this->toDecimalAmount(100),
            'currency' => $currencyCode,
            'shopperResultUrl' => route('payments-providers.peachpayments.checkout-result'),
            'notificationUrl' => route('payments-providers.peachpayments.webhook'),
            'cancelUrl' => $this->getSubscriptionCheckoutCancelUrl($subscription->plan, $subscription),
            'createRegistration' => true,
            'customParameters' => [
                'pm_change_subscription_uuid' => $subscription->uuid,
            ],
        ];

        try {
            $response = $this->client->createCheckout($payload);
        } catch (Exception $e) {
            Log::error('Failed to create Peach Payments change-payment-method checkout: '.$e->getMessage());

            throw $e;
        }

        return $response['redirectUrl'];
    }

    public function addDiscountToSubscription(Subscription $subscription, Discount $discount): bool
    {
        $this->assertProviderIsActive();

        // Peach Payments has no native discount concept: store the discount locally so the
        // renewal command can compute a reduced recurring charge.
        $extraProviderData = array_merge($subscription->extra_payment_provider_data ?? [], [
            'discount' => [
                'id' => $discount->id,
                'type' => $discount->type,
                'amount' => $discount->amount,
                'is_recurring' => $discount->is_recurring,
                'duration_in_months' => $discount->duration_in_months,
            ],
        ]);

        $this->subscriptionService->updateSubscription($subscription, [
            'extra_payment_provider_data' => $extraProviderData,
        ]);

        return true;
    }

    public function supportsPlan(Plan $plan): bool
    {
        return $plan->type === PlanType::FLAT_RATE->value;
    }

    public function reportUsage(Subscription $subscription, int $unitCount): bool
    {
        Log::warning('Peach Payments does not support usage-based billing; ignoring reportUsage call for subscription: '.$subscription->uuid);

        return false;
    }

    public function supportsSkippingTrial(): bool
    {
        return true;
    }

    public function supportsOneTimePurchaseProductQuantity(): bool
    {
        return true;
    }

    public function supportsSetupFees(): bool
    {
        return true;
    }

    private function assertProviderIsActive(): PaymentProvider
    {
        $paymentProvider = PaymentProvider::where('slug', $this->getSlug())->firstOrFail();

        if ($paymentProvider->is_active === false) {
            throw new Exception('Payment provider is not active: '.$this->getSlug());
        }

        return $paymentProvider;
    }

    private function assertSupportedCurrency(string $currencyCode): void
    {
        if (! in_array($currencyCode, self::SUPPORTED_CURRENCIES, true)) {
            throw new Exception('Peach Payments does not support the currency: '.$currencyCode);
        }
    }

    /**
     * Converts an integer minor-unit amount (e.g. cents) into the decimal string format
     * Peach Payments expects (e.g. 1999 -> "19.99").
     */
    private function toDecimalAmount(int $minorUnitAmount): string
    {
        $minorUnitAmount = max(0, $minorUnitAmount);

        // Format directly from integer minor units - no float division.
        return sprintf('%d.%02d', intdiv($minorUnitAmount, 100), $minorUnitAmount % 100);
    }

    private function applyDiscount(int $amount, ?Discount $discount): int
    {
        if ($discount === null) {
            return $amount;
        }

        if ($discount->type === DiscountConstants::TYPE_FIXED) {
            return max(0, $amount - (int) $discount->amount);
        }

        if ($discount->type === DiscountConstants::TYPE_PERCENTAGE) {
            return max(0, $amount - (int) round($amount * ($discount->amount / 100)));
        }

        return $amount;
    }

    /**
     * merchantTransactionId must be 8-16 characters; 'o-' + 6 zero-padded digits keeps IDs
     * within range for orders up to 999,999 while remaining stable/predictable for correlation.
     */
    private function buildOrderMerchantTransactionId(Order $order): string
    {
        return sprintf('o-%06d', $order->id);
    }

    private function buildSubscriptionMerchantTransactionId(Subscription $subscription): string
    {
        return sprintf('s-%06d', $subscription->id);
    }

    private function buildPaymentMethodChangeMerchantTransactionId(Subscription $subscription): string
    {
        return sprintf('pmc-%06d', $subscription->id);
    }

    private function getSubscriptionCheckoutCancelUrl(Plan $plan, Subscription $subscription): string
    {
        if ($subscription->type === SubscriptionType::LOCALLY_MANAGED) {
            return route('checkout.convert-local-subscription', ['subscriptionUuid' => $subscription->uuid]);
        }

        return route('checkout.subscription', ['planSlug' => $plan->slug]);
    }
}
