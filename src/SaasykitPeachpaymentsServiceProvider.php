<?php

namespace MyMage\SaasykitPeachPayments;

use Illuminate\Support\ServiceProvider;
use App\Services\PaymentProviders\PeachPayments\PeachPaymentsProvider;

class SaasykitPeachPaymentsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->tag([
            PeachPaymentsProvider::class,
        ], 'payment-providers');
    }

    public function boot()
    {
        $this->publishes([
            __DIR__ . '/public/images/payment-providers/peachpayments.png' => public_path('images/payment-providers/peachpayments.png'),
        ], 'public-images');

        $this->publishes([
            __DIR__ . '/app/Http/Controllers/PaymentProviders/PeachPaymentsController.php' =>
                app_path('Http/Controllers/PaymentProviders/PeachPaymentsController.php'),
        ], 'app-http-controllers-payment-providers');

        $this->publishes([
            __DIR__ . '/app/Services/PaymentProviders/PeachPayments' =>
                app_path('Services/PaymentProviders/PeachPayments'),
        ], 'app-services-payment-providers-peachpayments');

        $this->publishes([
            __DIR__ . '/app/Livewire/Filament/PeachPaymentsSettings.php' => app_path('Livewire/Filament/PeachPaymentsSettings.php'),
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
            __DIR__ . '/app/Filament/Admin/Resources/PaymentProviderResource/Pages/PeachPaymentSettings.php' =>
                app_path('Filament/Admin/Resources/PaymentProviderResource/Pages/PeachPaymentSettings.php'),
        ], 'app-filament-admin-resources-payment-provider-resource-pages');
    }
}
