<?php

namespace Tests\Feature\Filament\Admin\Resources;

use App\Filament\Admin\Resources\PaymentProviders\PaymentProviderResource;
use App\Livewire\Filament\PeachPaymentsSettings;
use App\Services\ConfigService;
use Livewire\Livewire;
use Tests\Feature\FeatureTest;

class PeachPaymentsSettingsTest extends FeatureTest
{
    public function test_settings_page_renders(): void
    {
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $response = $this->get(PaymentProviderResource::getUrl('peach-payments-settings', [], true, 'admin'));

        $response->assertStatus(200);
    }

    public function test_settings_component_saves_config_values(): void
    {
        $user = $this->createAdminUser();
        $this->actingAs($user);

        Livewire::test(PeachPaymentsSettings::class)
            ->fillForm([
                'entity_id' => 'entity-test-1',
                'secret_token' => 'secret-test-1',
                'client_id' => 'client-test-1',
                'client_secret' => 'client-secret-test-1',
                'merchant_id' => 'merchant-test-1',
                'recurring_entity_id' => 'recurring-entity-test-1',
                'recurring_access_token' => 'recurring-token-test-1',
                'test_mode' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $configService = app(ConfigService::class);
        $this->assertEquals('entity-test-1', $configService->get('services.peachpayments.entity_id'));
        $this->assertEquals('secret-test-1', $configService->get('services.peachpayments.secret_token'));
    }
}
