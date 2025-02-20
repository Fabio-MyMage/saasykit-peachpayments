<?php

namespace App\Services\PaymentProviders\PeachPayments;

use Carbon\Carbon;
use App\Models\Discount;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Subscription;
use App\Constants\DiscountConstants;
use App\Constants\PaymentProviderConstants;
use App\Constants\PaymentProviderPlanPriceType;
use App\Constants\PlanMeterConstants;
use App\Constants\PlanPriceTierConstants;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Constants\SubscriptionType;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Services\CalculationManager;
use App\Services\DiscountManager;
use App\Services\OneTimeProductManager;
use App\Services\PaymentProviders\PaymentProviderInterface;
use App\Services\PlanManager;
use App\Services\SubscriptionManager;
use PeachPayments\Checkout\CheckoutAPI;
use PeachPayments\Checkout\CheckoutOptions;
use PeachPayments\Checkout\Currency;
use PeachPayments\CheckoutClient;
use App\Filament\Dashboard\Resources\SubscriptionResource;


class PeachPaymentsProvider implements PaymentProviderInterface
{
    public function __construct(
        private SubscriptionManager $subscriptionManager,
        private PlanManager $planManager,
        private CalculationManager $calculationManager,
        private DiscountManager $discountManager,
        private OneTimeProductManager $oneTimeProductManager,
    ) {}

    public function getSlug(): string
    {
        return 'peach-payments';
    }

    public function getName(): string
    {
        return 'Peach Payments';
    }

    public function createSubscriptionCheckoutRedirectLink(Plan $plan, Subscription $subscription, ?Discount $discount = null): string
    {
        // Implement the logic to create a subscription checkout redirect link
    }

    public function createProductCheckoutRedirectLink(Order $order, ?Discount $discount = null): string
    {
        $orderAmount = floatval(number_format($order->total_amount_after_discount / 100, 2));
        $currencyCode = $order->currency()->firstOrFail()->code;
        $supportedCurrencies = [
            Currency::ZAR,
            Currency::USD,
            Currency::KES,
            Currency::MUR,
            Currency::GBP,
            Currency::EUR,
        ];

        try {
            if (!in_array($currencyCode, $supportedCurrencies)) {
                throw new \Exception('Unsupported currency: ' . $currencyCode);
            }

            $client = new CheckoutClient(config('services.peachpayments.entity_id'), config('services.peachpayments.secret_token'));
            if (!app()->environment('production')) {
                $client->enableTestMode();
            }

            $options = new CheckoutOptions($order->id, $currencyCode, $orderAmount, 'https://httpbin.org/post'); //TODO replace with actual URL
            $response = $client->checkout->initiateSession($options, url('/'));

            if ($response->code == 201) {
                return $response->body->redirectUrl;
            }
            else {
                dd($response->body);
                throw new \Exception($response->body->message);
            }
        }
        catch (\Exception $e) {
            Log::error('Failed to create Peach Payments checkout session: '.$e->getMessage());
            throw new \Exception('Failed to create Peach Payments checkout redirect link: '.$e->getMessage());
        }


    }

    public function initSubscriptionCheckout(Plan $plan, Subscription $subscription, ?Discount $discount = null): array
    {
        // Implement the logic to initialize a subscription checkout
        return [];
    }

    public function initProductCheckout(Order $order, ?Discount $discount = null): array
    {
        // Implement the logic to initialize a product checkout
        return [];
    }

    public function isRedirectProvider(): bool
    {
        return true;
    }

    public function isOverlayProvider(): bool
    {
        return false;
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, bool $withProration = false): bool
    {
        // Implement the logic to change a subscription plan
    }

    public function cancelSubscription(Subscription $subscription): bool
    {
        // Implement the logic to cancel a subscription
    }

    public function discardSubscriptionCancellation(Subscription $subscription): bool
    {
        // Implement the logic to discard a subscription cancellation
    }

    public function getChangePaymentMethodLink(Subscription $subscription): string
    {
        // Implement the logic to get the change payment method link
    }

    public function addDiscountToSubscription(Subscription $subscription, Discount $discount): bool
    {
        // Implement the logic to add a discount to a subscription
    }

    public function getSupportedPlanTypes(): array
    {
        return [
            PlanType::FLAT_RATE->value,
            PlanType::USAGE_BASED->value,
        ];
    }

    public function reportUsage(Subscription $subscription, int $unitCount): bool
    {
        // Implement the logic to report usage
    }

    public function supportsSkippingTrial(): bool
    {
        return true;
    }
}
