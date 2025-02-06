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
        $this->publishes([
            __DIR__ . '/config/peachpayments.php' => config_path('peachpayments.php'),
        ], 'config');

        $this->publishes([
            __DIR__ . '/public/images/payment-providers/peachpayments.png' => public_path('images/payment-providers/peachpayments.png'),
        ], 'public-images');

        $this->publishes([
            __DIR__ . '/app/Http/Controllers/PaymentProviders/PeachpaymentsController.php' =>
                app_path('Http/Controllers/PaymentProviders/PeachpaymentsController.php'),
        ], 'app-http-controllers-payment-providers');

        $this->publishes([
            __DIR__ . '/app/Livewire/Filament/PeachpaymentsSettings.php' => app_path('Livewire/Filament/PeachpaymentsSettings.php'),
        ], 'app-livewire-filament');

        $this->publishes([
            __DIR__ . '/resources/views/livewire/filament/peachpayments-settings.blade.php' =>
                resource_path('views/livewire/filament/peachpayments-settings.blade.php'),
            __DIR__ . '/resources/views/filament/admin/resources/payment-provider-resource/pages/peachpayments-settings.blade.php' =>
                resource_path('views/filament/admin/resources/payment-provider-resource/pages/peachpayments-settings.blade.php'),
            __DIR__ . '/resources/views/filament/admin/resources/payment-provider-resource/pages/partials/peachpayments-how-to.blade.php' =>
                resource_path('views/filament/admin/resources/payment-provider-resource/pages/partials/peachpayments-how-to.blade.php'),
        ], 'resources-views');

        $this->publishes([
            __DIR__ . '/app/Filament/Admin/Resources/PaymentProviderResource/Pages/PeachpaymentSettings.php' =>
                app_path('Filament/Admin/Resources/PaymentProviderResource/Pages/PeachpaymentSettings.php'),
        ], 'app-filament-admin-resources-payment-provider-resource-pages');
    }
}
