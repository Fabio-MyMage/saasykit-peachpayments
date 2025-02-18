<?php

namespace App\Services\PaymentProviders\Peachpayments;

use App\Constants\DiscountConstants;
use App\Constants\PaymentProviderConstants;
use App\Constants\PaymentProviderPlanPriceType;
use App\Constants\PlanMeterConstants;
use App\Constants\PlanPriceTierConstants;
use App\Constants\PlanPriceType;
use App\Constants\PlanType;
use App\Constants\SubscriptionType;
use App\Filament\Dashboard\Resources\SubscriptionResource;
use App\Models\Discount;
use App\Models\OneTimeProduct;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\CalculationManager;
use App\Services\DiscountManager;
use App\Services\OneTimeProductManager;
use App\Services\PaymentProviders\PaymentProviderInterface;
use App\Services\PlanManager;
use App\Services\SubscriptionManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Exception\ApiErrorException;

class PeachpaymentsProvider implements PaymentProviderInterface
{
    public function __construct(
        private SubscriptionManager $subscriptionManager,
        private CalculationManager $calculationManager,
        private PlanManager $planManager,
        private DiscountManager $discountManager,
        private OneTimeProductManager $oneTimeProductManager,
    ) {}

    // TODO
}
