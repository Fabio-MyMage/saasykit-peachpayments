<?php

namespace MyMage\SaasykitPeachpayments;

use Illuminate\Support\ServiceProvider;
use MyMage\SaasykitPeachpayments\PaymentProviders\PeachpaymentsProvider;

class SaasykitPeachpaymentsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/config/peachpayments.php', 'peachpayments');

        $this->app->tag([
            PeachpaymentsProvider::class,
        ], 'payment-providers');
    }

    public function boot()
    {
        // Publish configuration file
        $this->publishes([
            __DIR__ . '/config/peachpayments.php' => config_path('peachpayments.php'),
        ], 'config');

        // Publish Peachpayments logo to the public images folder
        $this->publishes([
            __DIR__ . '/images/peachpayments.png' => public_path('images/payment-providers/peachpayments.png'),
        ], 'public');

        // Publish the Peachpayments settings view
        $this->publishes([
            __DIR__ . '/resources/views/livewire/filament/peachpayments-settings.blade.php' =>
                resource_path('views/livewire/filament/peachpayments-settings.blade.php'),
        ], 'views');

        // Publish the Peachpayments settings view
        $this->publishes([
            __DIR__ . '/resources/views/filament/admin/resources/payment-provider-resource/pages/peachpayments-settings.blade.php' =>
                resource_path('views/filament/admin/resources/payment-provider-resource/pages/peachpayments-settings.blade.php'),
        ], 'views');
    }
}