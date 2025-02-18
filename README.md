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
php artisan vendor:publish --provider=\"MyMage\\SaasykitPeachpayments\\SaasykitPeachpaymentsServiceProvider\"
```

### 3. Update `AppServiceProvider.php`

Ensure `use` statement is included:

```php
use MyMage\SaasykitPeachpayments\PaymentProviders\PeachpaymentsProvider;
```

Then add `PeachpaymentsProvider` class:

```php
$this->app->tag([
    StripeProvider::class,
    PaddleProvider::class,
    LemonSqueezyProvider::class,
    PeachpaymentsProvider::class, // <----- Add this line
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
        'peachpayments-settings' => Pages\PeachpaymentsSettings::route('/peachpayments-settings'), // <----- Add this line
    ];
}
```

### 5. Update `PaymentProvidersSeeder.php`, `PaymentProviderConstants.php` and `ConfigConstants.php`

Ensure the entry for the seeder is added in `PaymentProvidersSeeder.php`:

```php
[
    'name' => 'Peachpayments',
    'slug' => PaymentProviderConstants::PEACHPAYMENTS_SLUG,
    'type' => 'multi',
    'created_at' => now()->format('Y-m-d H:i:s'),
    'updated_at' => now()->format('Y-m-d H:i:s'),
],
```

Ensure the slug constant is added in `PaymentProviderConstants.php`

```php
public const PEACHPAYMENTS_SLUG = 'peachpayments';
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
// Peachpayments hosted checkout webhook
Route::post('/pp-hosted/secure/webhook', [
    App\Http\Controllers\PaymentProviders\PeachpaymentsController::class,
    'handleWebhook',
])->name('payments-providers.peachpayments.webhook');
```

</details>
