<?php

namespace App\Filament\Admin\Resources\PaymentProviderResource\Pages;

use App\Filament\Admin\Resources\PaymentProviderResource;
use Filament\Resources\Pages\Page;

class PeachPaymentsSettings extends Page
{
    protected static string $resource = PaymentProviderResource::class;

    protected static string $view = 'filament.admin.resources.payment-provider-resource.pages.peach-payments-settings';

    public function mount(): void
    {
        static::authorizeResourceAccess();
    }
}
