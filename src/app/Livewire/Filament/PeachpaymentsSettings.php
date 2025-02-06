<?php

namespace MyMage\SaasykitPeachpayments\Livewire\Filament;

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
        return view('saasykit-peachpayments::livewire.filament.peachpayments-settings');
    }

    public function mount(): void
    {
        $this->form->fill([
            'secret_key' => $this->configManager->get('services.peachpayments.secret_key'),
            'publishable_key' => $this->configManager->get('services.peachpayments.publishable_key'),
            'webhook_signing_secret' => $this->configManager->get('services.peachpayments.webhook_signing_secret'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make()
                    ->schema([
                        TextInput::make('publishable_key')
                            ->label(__('Publishable Key'))
                            ->helperText(new HtmlString(__('The peachpayments publishable key is used to authenticate requests from the peachpayments JavaScript library. Check out the <strong><a href="https://peachpayments.com/docs/keys" target="_blank">peachpayments documentation</a></strong> for more information.'))),
                        TextInput::make('secret_key')
                            ->label(__('Secret Key'))
                            ->password()
                            ->helperText(new HtmlString(__('The peachpayments secret key is used to authenticate requests to the peachpayments API. Check out the <strong><a href="https://peachpayments.com/docs/keys" target="_blank">peachpayments documentation</a></strong> for more information.'))),
                        TextInput::make('webhook_signing_secret')
                            ->label(__('Webhook Signing Secret'))
                            ->helperText(new HtmlString(__('The peachpayments webhook signing secret is used to verify that incoming webhooks are from peachpayments. Check out the <strong><a href="https://peachpayments.com/docs/webhooks/signatures" target="_blank">peachpayments documentation</a></strong> for more information.'))),
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

        $this->configManager->set('services.peachpayments.secret_key', $data['secret_key']);
        $this->configManager->set('services.peachpayments.publishable_key', $data['publishable_key']);
        $this->configManager->set('services.peachpayments.webhook_signing_secret', $data['webhook_signing_secret']);

        Notification::make()
            ->title(__('Settings Saved'))
            ->success()
            ->send();
    }
}
