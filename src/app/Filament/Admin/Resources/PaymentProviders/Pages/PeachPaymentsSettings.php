<?php

namespace App\Filament\Admin\Resources\PaymentProviders\Pages;

use App\Filament\Admin\Resources\PaymentProviders\PaymentProviderResource;
use App\Services\ConfigService;
use Filament\Resources\Pages\Page;

class PeachPaymentsSettings extends Page
{
    protected static string $resource = PaymentProviderResource::class;

    protected string $view = 'filament.admin.resources.payment-provider-resource.pages.peach-payments-settings';

    public function mount(): void
    {
        static::authorizeResourceAccess();
    }

    public static function canAccess(array $parameters = []): bool
    {
        $configService = app()->make(ConfigService::class);

        return $configService->isAdminSettingsEnabled();
    }
}
