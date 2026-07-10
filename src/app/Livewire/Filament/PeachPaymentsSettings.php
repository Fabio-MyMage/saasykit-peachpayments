<?php

namespace App\Livewire\Filament;

use App\Services\ConfigService;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Livewire\Component;

class PeachPaymentsSettings extends Component implements HasForms
{
    private ConfigService $configService;

    use InteractsWithForms;

    public ?array $data = [];

    public function boot(ConfigService $configService): void
    {
        $this->configService = $configService;
    }

    public function render()
    {
        return view('livewire.filament.peach-payments-settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'entity_id' => $this->configService->get('services.peachpayments.entity_id'),
            'secret_token' => $this->configService->get('services.peachpayments.secret_token'),
            'client_id' => $this->configService->get('services.peachpayments.client_id'),
            'client_secret' => $this->configService->get('services.peachpayments.client_secret'),
            'merchant_id' => $this->configService->get('services.peachpayments.merchant_id'),
            'recurring_entity_id' => $this->configService->get('services.peachpayments.recurring_entity_id'),
            'recurring_access_token' => $this->configService->get('services.peachpayments.recurring_access_token'),
            'test_mode' => filter_var($this->configService->get('services.peachpayments.test_mode', '0'), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextInput::make('entity_id')
                            ->label(__('Entity ID'))
                            ->helperText(new HtmlString(__('The Peach Payments Entity ID used to authenticate Hosted Checkout requests. Check out the <strong><a href="https://developer.peachpayments.com/docs/checkout-hosted" target="_blank">Peach Payments documentation</a></strong> for more information.'))),
                        TextInput::make('secret_token')
                            ->label(__('Secret Token'))
                            ->password()
                            ->revealable()
                            ->helperText(__('Used to verify the signature of incoming webhooks and checkout redirects.')),
                        TextInput::make('client_id')
                            ->label(__('Client ID'))
                            ->helperText(__('Used together with the Client Secret to obtain an OAuth access token for the Checkout V2 API.')),
                        TextInput::make('client_secret')
                            ->label(__('Client Secret'))
                            ->password()
                            ->revealable(),
                        TextInput::make('merchant_id')
                            ->label(__('Merchant ID')),
                        TextInput::make('recurring_entity_id')
                            ->label(__('Recurring Entity ID'))
                            ->helperText(__('The Entity ID for the "Recurring payments" product, used for automatic renewal (MIT) charges.')),
                        TextInput::make('recurring_access_token')
                            ->label(__('Recurring Access Token'))
                            ->password()
                            ->revealable()
                            ->helperText(__('The Bearer token used to authenticate recurring (MIT) charge requests.')),
                        Toggle::make('test_mode')
                            ->label(__('Test Mode'))
                            ->helperText(__('When enabled, requests are sent to the Peach Payments sandbox hosts instead of production.')),
                    ])->columnSpan([
                        'sm' => 6,
                        'xl' => 8,
                        '2xl' => 8,
                    ]),
                Section::make()->schema([
                    ViewField::make('how-to')
                        ->label(__('Peach Payments Settings'))
                        ->view('filament.admin.resources.payment-provider-resource.pages.partials.peach-payments-how-to'),
                ])->columnSpan([
                    'sm' => 6,
                    'xl' => 4,
                    '2xl' => 4,
                ]),
            ])->columns(12)
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $this->configService->set('services.peachpayments.entity_id', $data['entity_id']);
        $this->configService->set('services.peachpayments.secret_token', $data['secret_token']);
        $this->configService->set('services.peachpayments.client_id', $data['client_id']);
        $this->configService->set('services.peachpayments.client_secret', $data['client_secret']);
        $this->configService->set('services.peachpayments.merchant_id', $data['merchant_id']);
        $this->configService->set('services.peachpayments.recurring_entity_id', $data['recurring_entity_id']);
        $this->configService->set('services.peachpayments.recurring_access_token', $data['recurring_access_token']);
        $this->configService->set('services.peachpayments.test_mode', (bool) $data['test_mode']);

        Notification::make()
            ->title(__('Settings Saved'))
            ->success()
            ->send();
    }
}
