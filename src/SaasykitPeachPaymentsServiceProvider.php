<?php

namespace MyMage\SaasykitPeachPayments;

use Illuminate\Support\ServiceProvider;

class SaasykitPeachPaymentsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/public/images/payment-providers/peach-payments.png' => public_path('images/payment-providers/peach-payments.png'),
            __DIR__.'/app/Client/PeachPaymentsClient.php' => app_path('Client/PeachPaymentsClient.php'),
            __DIR__.'/app/Services/PaymentProviders/PeachPayments' => app_path('Services/PaymentProviders/PeachPayments'),
            __DIR__.'/app/Http/Controllers/PaymentProviders/PeachPaymentsController.php' => app_path('Http/Controllers/PaymentProviders/PeachPaymentsController.php'),
            __DIR__.'/app/Console/Commands/PeachPaymentsChargeRenewals.php' => app_path('Console/Commands/PeachPaymentsChargeRenewals.php'),
            __DIR__.'/app/Livewire/Filament/PeachPaymentsSettings.php' => app_path('Livewire/Filament/PeachPaymentsSettings.php'),
            __DIR__.'/app/Filament/Admin/Resources/PaymentProviders/Pages/PeachPaymentsSettings.php' => app_path('Filament/Admin/Resources/PaymentProviders/Pages/PeachPaymentsSettings.php'),
            __DIR__.'/resources/views/livewire/filament/peach-payments-settings.blade.php' => resource_path('views/livewire/filament/peach-payments-settings.blade.php'),
            __DIR__.'/resources/views/filament/admin/resources/payment-provider-resource/pages/peach-payments-settings.blade.php' => resource_path('views/filament/admin/resources/payment-provider-resource/pages/peach-payments-settings.blade.php'),
            __DIR__.'/resources/views/filament/admin/resources/payment-provider-resource/pages/partials/peach-payments-how-to.blade.php' => resource_path('views/filament/admin/resources/payment-provider-resource/pages/partials/peach-payments-how-to.blade.php'),
        ], 'saasykit-peachpayments');

        $this->publishes([
            __DIR__.'/tests' => base_path('tests'),
        ], 'saasykit-peachpayments-tests');
    }
}
