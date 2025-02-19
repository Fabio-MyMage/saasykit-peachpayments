<?php

namespace App\Services\PaymentProviders\Peachpayments;

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
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Filament\Dashboard\Resources\SubscriptionResource;
use Peachpayments\Checkout\CheckoutAPI;
use App\Services\CalculationManager;
use App\Services\DiscountManager;
use App\Services\OneTimeProductManager;
use App\Services\PaymentProviders\PaymentProviderInterface;
use App\Services\PlanManager;
use App\Services\SubscriptionManager;

class PeachpaymentsProvider implements PaymentProviderInterface
{
    private $client;

    public function getSlug(): string
    {
        return 'peachpayments';
    }

    public function getName(): string
    {
        return 'Peachpayments';
    }

    public function createSubscriptionCheckoutRedirectLink(Plan $plan, Subscription $subscription, ?Discount $discount = null): string
    {
        // Implement the logic to create a subscription checkout redirect link
    }

    public function createProductCheckoutRedirectLink(Order $order, ?Discount $discount = null): string
    {
        // Implement the logic to create a product checkout redirect link
    }

    public function initSubscriptionCheckout(Plan $plan, Subscription $subscription, ?Discount $discount = null): array
    {
        // Implement the logic to initialize a subscription checkout
    }

    public function initProductCheckout(Order $order, ?Discount $discount = null): array
    {
        // Implement the logic to initialize a product checkout
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
