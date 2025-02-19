# Saasykit PeachPayments Package

This package provides integration with PeachPayments for SaasyKit.

## Work in progress!

## Installation Instructions

<details>
To install the PeachPayments package, follow these steps:

### 1. Composer installtion

```
composer require mymage/saasykit-peachpayments
```

### 2. Laravel file publishing

```
php artisan vendor:publish --provider="MyMage\SaasykitPeachPayments\SaasykitPeachPaymentsServiceProvider"
```

### 3. Update `AppServiceProvider.php`

Ensure `use` statement is included:

```php
use App\Services\PaymentProviders\PeachPayments\PeachPaymentsProvider;
```

Then add `PeachPaymentsProvider` class:

```php
$this->app->tag([
    StripeProvider::class,
    PaddleProvider::class,
    LemonSqueezyProvider::class,
    PeachPaymentsProvider::class, // <----- Add this line
], 'payment-providers');
```

### 4. Update `PaymentProviderResource.php`

Ensure the settings page is included:

```php
public static function getPages(): array
{
    return [
        'index' => Pages\ListPaymentProviders::route('/'),
        'edit' => Pages\EditPaymentProvider::route('/{record}/edit'),
        'stripe-settings' => Pages\StripeSettings::route('/stripe-settings'),
        'paddle-settings' => Pages\PaddleSettings::route('/paddle-settings'),
        'lemon-squeezy-settings' => Pages\LemonSqueezySettings::route('/lemon-squeezy-settings'),
        'peach-payments-settings' => Pages\PeachPaymentsSettings::route('/peach-payments-settings'),
    ];
}
```

### 5. Update `PaymentProvidersSeeder.php`, `PaymentProviderConstants.php` and `ConfigConstants.php`

Ensure the entry for the seeder is added in `PaymentProvidersSeeder.php`:

```php
[
    'name' => 'Peach Payments',
    'slug' => PaymentProviderConstants::PEACHPAYMENTS_SLUG,
    'type' => 'multi',
    'created_at' => now()->format('Y-m-d H:i:s'),
    'updated_at' => now()->format('Y-m-d H:i:s'),
],
```

Ensure the slug constant is added in `PaymentProviderConstants.php`

```php
public const PEACHPAYMENTS_SLUG = 'peach-payments';
```

Ensure the following array values are defined in both `ENCRYPTED_CONFIGS` and `OVERRIDABLE_CONFIGS` constants in `ConfigConstants.php`:

```php
'services.peachpayments.entity_id',
'services.peachpayments.secret_token',
```

### 6. Run Laravel DB Seeders

```
php artisan db:seed --class=PaymentProvidersSeeder"
```

### 7. Add the webhook Route

Ensure the following route is defined in `routes/web.php`

```
// PeachPayments hosted checkout webhook
Route::post('/pp-hosted/secure/webhook', [
    App\Http\Controllers\PaymentProviders\PeachPaymentsController::class,
    'handleWebhook',
])->name('payments-providers.peachpayments.webhook');
```

</details>
