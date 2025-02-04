<?php

namespace App\Filament\Admin\Resources\PaymentProviderResource\Pages;

use Filament\Resources\Pages\SettingsPage;
use App\Filament\Admin\Resources\PaymentProviderResource;

class PeachpaymentsSettings extends SettingsPage
{
    protected static string $resource = PaymentProviderResource::class;

    protected function getFormSchema(): array
    {
        return [
            // Define the form schema for Peachpayments settings
        ];
    }
}