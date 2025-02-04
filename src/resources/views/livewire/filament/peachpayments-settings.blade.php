<div>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="pt-4 flex gap-4">
            <x-filament::button type="submit">
                <x-filament::loading-indicator class="h-5 w-5 inline" wire:loading />
                {{ __('Save Peachpayments Settings') }}
            </x-filament::button>

            <x-filament::button tag="a" href="{{ \App\Filament\Admin\Resources\PaymentProviderResource\Pages\ListPaymentProviders::getUrl() }}" color="gray">
                {{ __('Cancel') }}
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</div>