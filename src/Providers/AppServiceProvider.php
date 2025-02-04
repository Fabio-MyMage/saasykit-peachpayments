<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use MyMage\SaasykitPeachpayments\PaymentProviders\PeachpaymentsProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Other service registrations...

        $this->app->tag([
            PeachpaymentsProvider::class,
        ], 'payment-providers');
    }

    public function boot()
    {
        // Other boot logic...
    }
}