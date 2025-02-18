<?php

namespace App\Livewire\Filament;

use App\Services\ConfigManager;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Livewire\Component;

class PeachpaymentsSettings extends Component implements HasForms
{
    private ConfigManager $configManager;

    use InteractsWithForms;

    public ?array $data = [];

    public function boot(ConfigManager $configManager): void
    {
        $this->configManager = $configManager;
    }

    public function render()
    {
        return view('livewire.filament.peachpayments-settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'entity_id' => $this->configManager->get('services.peachpayments.entity_id'),
            'access_token' => $this->configManager->get('services.peachpayments.access_token'),
            'webhook_signing_secret' => $this->configManager->get('services.peachpayments.webhook_signing_secret'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        TextInput::make('entity_id')
                            ->label(__('Entity ID'))
                            ->helperText(new HtmlString(__('The peachpayments Entity ID key is used to authenticate requests for peachpayments Hosted checked. Check out the <strong><a href="https://developer.peachpayments.com/docs/checkout-hosted" target="_blank">peachpayments documentation</a></strong> for more information.'))),
                        TextInput::make('access_token')
                            ->label(__('Access Token'))
                            ->password()
                            ->helperText(new HtmlString(__('The peachpayments Access token is used to authenticate requests to the peachpayments API. Check out the <strong><a href="https://developer.peachpayments.com/docs/checkout-hosted" target="_blank">peachpayments documentation</a></strong> for more information.'))),
                        TextInput::make('webhook_signing_secret')
                            ->label(__('Webhook Signing Secret'))
                            ->helperText(new HtmlString(__('The peachpayments webhook signing secret is used to verify that incoming webhooks are from peachpayments. Check out the <strong><a href="https://developer.peachpayments.com/docs/reference-webhooks" target="_blank">peachpayments documentation</a></strong> for more information.'))),
                    ])->columnSpan([
                        'sm' => 6,
                        'xl' => 8,
                        '2xl' => 8,
                    ]),
                Section::make()->schema([
                    ViewField::make('how-to')
                        ->label(__('peachpayments Settings'))
                        ->view('filament.admin.resources.payment-provider-resource.pages.partials.peachpayments-how-to'),
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

        $this->configManager->set('services.peachpayments.entity_id', $data['entity_id']);
        $this->configManager->set('services.peachpayments.access_token', $data['access_token']);
        $this->configManager->set('services.peachpayments.webhook_signing_secret', $data['webhook_signing_secret']);

        Notification::make()
            ->title(__('Settings Saved'))
            ->success()
            ->send();
    }
}
