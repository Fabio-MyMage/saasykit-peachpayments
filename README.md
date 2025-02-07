# Saasykit PeachPayments Package

This package provides integration with PeachPayments for SaasyKit.

## Work in progress!

## Installation Instructions

<details>
To install the PeachPayments package, follow these steps:

### 1. Composer installtion

<details>
```
composer require mymage/saasykit-peachpayments
```
</details>

### 2. Laravel file publishing

<details>
```
php artisan vendor:publish --provider=\"MyMage\\SaasykitPeachpayments\\SaasykitPeachpaymentsServiceProvider\"
```
</details>

### 3. Update `AppServiceProvider.php`

<details>
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

</details>

### 4. Update `PaymentProviderResource.php`

<details>
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

</details>

### 5. Update `PaymentProvidersSeeder.php`, `PaymentProviderConstants.php` and `ConfigConstants.php`

<details>
Ensure the entry in the seeder is added in `PaymentProvidersSeeder.php`:

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
'services.peachpayments.access_token',
'services.peachpayments.webhook_signing_secret',
```

</details>

### 6. Run Laravel DB Seeders

<details>
```
php artisan db:seed --class=PaymentProvidersSeeder"
```
</details>
</details>
