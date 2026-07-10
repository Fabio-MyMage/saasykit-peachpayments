# SaasyKit Peach Payments Package

Adds [Peach Payments](https://www.peachpayments.com/) as a payment provider to
[SaasyKit](https://saasykit.com/) — one-time purchases via Hosted Checkout V2, and
subscriptions via card tokenization + a scheduled recurring (MIT) charge command.

## Requirements

- SaasyKit v4.4+ (Laravel 13, Filament 5 — the release where `*Manager` services were renamed to `*Service`)
- PHP 8.2+
- A Peach Payments account with the **Checkout** and **Recurring Payments** products enabled

This is a **publish-into-app** package: it copies its `app/`, `resources/views/`, and
`public/` files directly into your SaasyKit installation and requires a short manual
wiring step (below), matching how the built-in providers (Stripe/Paddle/Polar/LemonSqueezy)
live inside SaasyKit itself.

## Install

### 1. Require the package

```bash
composer require mymage/saasykit-peachpayments
```

(Or, while developing locally, add a `path`/VCS repository entry pointing at this repo
and `composer require mymage/saasykit-peachpayments:@dev`.)

### 2. Publish the files

```bash
php artisan vendor:publish --tag=saasykit-peachpayments
```

This copies:

- `app/Client/PeachPaymentsClient.php`
- `app/Services/PaymentProviders/PeachPayments/{PeachPaymentsProvider,PeachPaymentsWebhookHandler}.php`
- `app/Http/Controllers/PaymentProviders/PeachPaymentsController.php`
- `app/Console/Commands/PeachPaymentsChargeRenewals.php`
- `app/Livewire/Filament/PeachPaymentsSettings.php`
- `app/Filament/Admin/Resources/PaymentProviders/Pages/PeachPaymentsSettings.php`
- `resources/views/livewire/filament/peach-payments-settings.blade.php`
- `resources/views/filament/admin/resources/payment-provider-resource/pages/{peach-payments-settings,partials/peach-payments-how-to}.blade.php`
- `public/images/payment-providers/peach-payments.png`

### 3. Wire it into the host app

The package intentionally does **not** register anything in the container — do this
explicitly so it is visible in your app's own code.

**(a) Tag the provider — `app/Providers/AppServiceProvider.php`**

```php
use App\Services\PaymentProviders\PeachPayments\PeachPaymentsProvider;

// inside register(), extend the existing tag() call:
$this->app->tag([
    StripeProvider::class,
    PaddleProvider::class,
    // ... existing providers ...
    PeachPaymentsProvider::class, // <-- add this
], 'payment-providers');
```

**(b) Add the slug constant — `app/Constants/PaymentProviderConstants.php`**

```php
public const PEACH_PAYMENTS_SLUG = 'peach-payments';
```

**(c) Seed the provider row — `database/seeders/PaymentProvidersSeeder.php`**

```php
[
    'name' => 'Peach Payments',
    'slug' => PaymentProviderConstants::PEACH_PAYMENTS_SLUG,
    'type' => 'multi',
    'created_at' => now()->format('Y-m-d H:i:s'),
    'updated_at' => now()->format('Y-m-d H:i:s'),
],
```

```bash
php artisan db:seed --class=PaymentProvidersSeeder
```

**(d) Add the config block — `config/services.php`**

```php
'peachpayments' => [
    'entity_id' => env('PEACHPAYMENTS_ENTITY_ID'),
    'secret_token' => env('PEACHPAYMENTS_SECRET_TOKEN'),
    'client_id' => env('PEACHPAYMENTS_CLIENT_ID'),
    'client_secret' => env('PEACHPAYMENTS_CLIENT_SECRET'),
    'merchant_id' => env('PEACHPAYMENTS_MERCHANT_ID'),
    'recurring_entity_id' => env('PEACHPAYMENTS_RECURRING_ENTITY_ID'),
    'recurring_access_token' => env('PEACHPAYMENTS_RECURRING_ACCESS_TOKEN'),
    'test_mode' => env('PEACHPAYMENTS_TEST_MODE', true),
    'max_renewal_retries' => env('PEACHPAYMENTS_MAX_RENEWAL_RETRIES', 3),
],
```

**(e) Whitelist the config keys — `app/Constants/ConfigConstants.php`**

Add to `OVERRIDABLE_CONFIGS`:

```php
'services.peachpayments.entity_id',
'services.peachpayments.secret_token',
'services.peachpayments.client_id',
'services.peachpayments.client_secret',
'services.peachpayments.merchant_id',
'services.peachpayments.recurring_entity_id',
'services.peachpayments.recurring_access_token',
'services.peachpayments.test_mode',
```

Add the **secret** ones to `ENCRYPTED_CONFIGS` (identifiers like entity/client/merchant ID
are not secrets and are intentionally left out):

```php
'services.peachpayments.secret_token',
'services.peachpayments.client_secret',
'services.peachpayments.recurring_access_token',
```

**(f) Register the settings page — `app/Filament/Admin/Resources/PaymentProviders/PaymentProviderResource.php`**

```php
use App\Filament\Admin\Resources\PaymentProviders\Pages\PeachPaymentsSettings;

public static function getPages(): array
{
    return [
        // ... existing pages ...
        'peach-payments-settings' => PeachPaymentsSettings::route('/peach-payments-settings'), // <-- add this
    ];
}
```

**(g) Add the routes — `routes/api.php`**

```php
use App\Http\Controllers\PaymentProviders\PeachPaymentsController;

Route::post('/payments-providers/peach-payments/webhook', [PeachPaymentsController::class, 'handleWebhook'])
    ->name('payments-providers.peachpayments.webhook');

Route::post('/payments-providers/peach-payments/checkout-result', [PeachPaymentsController::class, 'checkoutResult'])
    ->name('payments-providers.peachpayments.checkout-result');
```

Both routes must stay outside CSRF protection (the `api` group already is) since they're
called by Peach Payments' servers / a cross-site browser POST-back, not your own app.

**(h) Schedule renewal charges — `routes/console.php`**

```php
Schedule::command('app:peachpayments-charge-renewals')->hourly()->withoutOverlapping();
```

(`withoutOverlapping()` plus per-subscription row locking inside the command guard against
double-charging if runs ever overlap.)

**(i) Use the settings page**

Visit **Admin → Payment Providers → Peach Payments Settings** to enter your credentials
(Entity ID, Secret Token, Client ID/Secret, Merchant ID, Recurring Entity ID/Access Token,
Test Mode toggle). The page's "how to" panel walks through where to find each value in the
Peach Payments dashboard.

**(j) Peach Payments dashboard setup**

- Allowlist your application's domain under Checkout settings (required — Hosted Checkout
  requests are otherwise rejected).
- Set the webhook/notification URL to the `payments-providers.peachpayments.webhook` route
  above. The first request Peach sends is a JSON configuration ping — the integration
  answers it automatically.
- Ask Peach Payments to enable "Recurring Payments" on your account to get the recurring
  entity ID + access token needed for subscription renewals.
- Use the sandbox dashboard/hosts while `test_mode` is on; switch both together at go-live.

## How subscriptions work

Peach Payments has no native subscription object. The first checkout tokenizes the card
(`createRegistration=true` → `registrationId`), which is stored on
`subscriptions.extra_payment_provider_data`. The `app:peachpayments-charge-renewals`
command (scheduled hourly) charges that token via Peach's recurring/MIT API whenever a
subscription's `ends_at` is due, then advances `ends_at` by one billing interval. Failed
renewal charges are retried daily (default 3 attempts, `max_renewal_retries`) with the
subscription marked `past_due`, then canceled.

Notes and current limitations:

- **No proration** — a plan change takes effect at the next renewal charge.
- **Trials** — plans with an active trial are currently rejected at checkout
  (skip-trial flows work). Zero-amount tokenization-only checkouts need to be verified
  in the Peach sandbox before trial-then-charge can be enabled.
- **Change payment method** — implemented as a small verification checkout
  (currently a nominal 1.00 charge; switch to zero-amount once sandbox-verified).
- **Refunds** — initiate refunds from the Peach dashboard; the webhook marks the local
  transaction and order as refunded. Only **full** refunds are supported — a partial
  refund is logged and ignored locally (SaasyKit has no partial-refund state).

## Testing

The package ships a PHPUnit test suite (client signature scheme, webhook handler
including replay/refund/partial-refund cases, and the renewal command's
success/dunning/cancellation paths). Publish it into your app's `tests/` directory and
run it:

```bash
php artisan vendor:publish --tag=saasykit-peachpayments-tests
php artisan test --filter=PeachPayments
```

For end-to-end verification, use Peach's sandbox credentials and hosts (`test_mode` enabled) end-to-end: run a checkout,
confirm the webhook and checkout-result routes fire (webhooks need a publicly reachable
URL — use a tunnel for local dev, or simulate with signed curl POSTs), then force a
subscription's `ends_at` into the past and run
`php artisan app:peachpayments-charge-renewals` to observe a sandbox MIT charge.
