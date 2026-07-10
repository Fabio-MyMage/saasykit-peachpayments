<?php

namespace Tests\Feature\Services\PaymentProviders\PeachPayments;

use App\Client\PeachPaymentsClient;
use App\Constants\OrderStatus;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\Currency;
use App\Models\Order;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\Feature\FeatureTest;

class PeachPaymentsWebhookHandlerTest extends FeatureTest
{
    private PeachPaymentsClient $client;

    private PaymentProvider $paymentProvider;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.peachpayments.secret_token', 'secret-token-123');
        config()->set('services.peachpayments.entity_id', 'entity-123');

        $this->client = app(PeachPaymentsClient::class);
        $this->paymentProvider = PaymentProvider::where('slug', PaymentProviderConstants::PEACH_PAYMENTS_SLUG)->firstOrFail();
        $this->currency = Currency::where('code', 'USD')->firstOrFail();
    }

    public function test_json_configuration_ping_returns_200_without_signature(): void
    {
        $response = $this->postJson(route('payments-providers.peachpayments.webhook'), []);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);
    }

    public function test_invalid_signature_returns_401_and_does_not_change_state(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
        ]);

        $payload = $this->buildOrderPayload($order->uuid, '000.000.000', 'DB');
        $payload['signature'] = 'not-a-real-signature';

        $response = $this->postWebhook($payload);

        $response->assertStatus(401);

        $order->refresh();
        $this->assertEquals('new', $order->status);
        $this->assertDatabaseMissing('transactions', [
            'payment_provider_transaction_id' => $payload['id'],
        ]);
    }

    public function test_order_success_webhook_sets_order_and_transaction_success(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
        ]);

        $payload = $this->signedOrderPayload($order->uuid, '000.000.000', 'DB');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(OrderStatus::SUCCESS->value, $order->status);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => TransactionStatus::SUCCESS->value,
            'payment_provider_transaction_id' => $payload['id'],
        ]);
    }

    public function test_order_failed_webhook_sets_order_and_transaction_failed(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
        ]);

        $payload = $this->signedOrderPayload($order->uuid, '800.100.155', 'DB');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(OrderStatus::FAILED->value, $order->status);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => TransactionStatus::FAILED->value,
            'payment_provider_transaction_id' => $payload['id'],
        ]);
    }

    public function test_order_refund_webhook_sets_order_and_transaction_refunded(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'status' => OrderStatus::SUCCESS->value,
        ]);

        $transaction = $order->transactions()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'amount' => 10000,
            'status' => TransactionStatus::SUCCESS->value,
            'payment_provider_id' => $this->paymentProvider->id,
            'payment_provider_status' => '000.000.000',
            'payment_provider_transaction_id' => 'original-payment-id-'.Str::random(6),
        ]);

        $payload = $this->signedOrderPayload($order->uuid, '000.000.000', 'RF');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(OrderStatus::REFUNDED->value, $order->status);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => TransactionStatus::REFUNDED->value,
        ]);
    }

    public function test_partial_refund_webhook_leaves_order_unchanged(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'status' => OrderStatus::SUCCESS->value,
        ]);

        $transaction = $order->transactions()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'amount' => 10000,
            'status' => TransactionStatus::SUCCESS->value,
            'payment_provider_id' => $this->paymentProvider->id,
            'payment_provider_status' => '000.000.000',
            'payment_provider_transaction_id' => 'original-payment-id-'.Str::random(6),
        ]);

        // 10.00 refunded of a 100.00 transaction -> partial, must be a no-op locally.
        $payload = $this->buildOrderPayload($order->uuid, '000.000.000', 'RF');
        $payload['amount'] = '10.00';
        $payload['signature'] = $this->client->sign($payload);

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(OrderStatus::SUCCESS->value, $order->status);

        $this->assertDatabaseHas('transactions', [
            'id' => $transaction->id,
            'status' => TransactionStatus::SUCCESS->value,
        ]);
    }

    public function test_replayed_order_success_webhook_does_not_create_duplicate_transaction(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
        ]);

        $payload = $this->signedOrderPayload($order->uuid, '000.000.000', 'DB');

        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)->assertStatus(200);

        $order->refresh();
        $this->assertEquals(OrderStatus::SUCCESS->value, $order->status);

        $this->assertEquals(
            1,
            Transaction::where('payment_provider_transaction_id', $payload['id'])->count()
        );
    }

    public function test_subscription_initial_payment_success_activates_subscription(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'status' => 'new',
            'payment_provider_subscription_id' => null,
            'extra_payment_provider_data' => null,
        ]);

        $payload = $this->signedSubscriptionPayload($subscription->uuid, '000.000.000', 'DB', 'reg-abc-123');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertEquals(SubscriptionType::PAYMENT_PROVIDER_MANAGED, $subscription->type);
        $this->assertEquals('reg-abc-123', $subscription->payment_provider_subscription_id);
        $this->assertArrayHasKey('registration_id', $subscription->extra_payment_provider_data);
        $this->assertEquals('reg-abc-123', $subscription->extra_payment_provider_data['registration_id']);
        $this->assertArrayHasKey('checkout_id', $subscription->extra_payment_provider_data);
        // Initial (CIT) transaction id stored for later MIT charges.
        $this->assertEquals($payload['id'], $subscription->extra_payment_provider_data['initial_transaction_id']);
        $this->assertTrue(Carbon::parse($subscription->ends_at)->isFuture());

        $this->assertDatabaseHas('transactions', [
            'subscription_id' => $subscription->id,
            'status' => TransactionStatus::SUCCESS->value,
            'payment_provider_transaction_id' => $payload['id'],
        ]);
    }

    public function test_subscription_initial_payment_failed_does_not_activate_subscription(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'status' => 'new',
            'payment_provider_subscription_id' => null,
            'extra_payment_provider_data' => null,
        ]);

        $payload = $this->signedSubscriptionPayload($subscription->uuid, '800.100.155', 'DB', 'reg-xyz-999');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);

        $subscription->refresh();
        $this->assertNotEquals(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertNull($subscription->payment_provider_subscription_id);

        $this->assertDatabaseHas('transactions', [
            'subscription_id' => $subscription->id,
            'status' => TransactionStatus::FAILED->value,
            'payment_provider_transaction_id' => $payload['id'],
        ]);
    }

    public function test_replayed_subscription_success_webhook_does_not_extend_ends_at_twice(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'status' => 'new',
            'payment_provider_subscription_id' => null,
            'extra_payment_provider_data' => null,
        ]);

        $payload = $this->signedSubscriptionPayload($subscription->uuid, '000.000.000', 'DB', 'reg-replay-1');

        $this->postWebhook($payload)->assertStatus(200);

        $subscription->refresh();
        $endsAtAfterFirst = Carbon::parse($subscription->ends_at);

        $this->postWebhook($payload)->assertStatus(200);

        $subscription->refresh();
        $this->assertTrue($endsAtAfterFirst->equalTo(Carbon::parse($subscription->ends_at)));

        $this->assertEquals(
            1,
            Transaction::where('payment_provider_transaction_id', $payload['id'])->count()
        );
    }

    /**
     * Posts a webhook the way Peach really does: a raw form-urlencoded body (which
     * the handler reads via getContent() for signature verification) plus the parsed
     * params (which PHP would populate on $request->all()).
     */
    private function postWebhook(array $payload)
    {
        $raw = http_build_query($payload, '', '&', PHP_QUERY_RFC1738);

        return $this->call(
            'POST',
            route('payments-providers.peachpayments.webhook'),
            $payload,
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $raw,
        );
    }

    public function test_late_failed_webhook_after_activation_does_not_regress_subscription(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'status' => 'new',
            'payment_provider_subscription_id' => null,
            'extra_payment_provider_data' => null,
        ]);

        $success = $this->signedSubscriptionPayload($subscription->uuid, '000.000.000', 'DB', 'reg-late-1');
        $this->postWebhook($success)->assertStatus(200);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $transactionCount = Transaction::where('subscription_id', $subscription->id)->count();

        // A stale FAILED retry (30-day webhook retry window) lands after activation:
        // it must not create a failed transaction nor trigger payment-failed handling.
        $lateFailed = $this->signedSubscriptionPayload($subscription->uuid, '800.100.155', 'DB', 'reg-late-1');
        $this->postWebhook($lateFailed)->assertStatus(200);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertEquals($transactionCount, Transaction::where('subscription_id', $subscription->id)->count());
        $this->assertDatabaseMissing('transactions', [
            'subscription_id' => $subscription->id,
            'status' => TransactionStatus::FAILED->value,
        ]);
    }

    public function test_late_pending_webhook_after_activation_creates_no_orphan_transaction(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'status' => 'new',
            'payment_provider_subscription_id' => null,
            'extra_payment_provider_data' => null,
        ]);

        $checkoutId = 'chk-'.Str::random(8);

        // Pending (no payment id) -> success (real id) -> the pending row is reconciled.
        $pending = [
            'checkoutId' => $checkoutId,
            'merchantTransactionId' => 's-000000',
            'result_code' => '000.200.100',
            'paymentType' => 'DB',
            'amount' => '19.99',
            'currency' => 'USD',
            'customParameters' => ['subscription_uuid' => $subscription->uuid],
        ];
        $pending['signature'] = $this->client->sign($pending);
        $this->postWebhook($pending)->assertStatus(200);

        $success = [
            'checkoutId' => $checkoutId,
            'merchantTransactionId' => 's-000000',
            'id' => 'pay-'.Str::random(12),
            'result_code' => '000.100.110',
            'paymentType' => 'DB',
            'amount' => '19.99',
            'currency' => 'USD',
            'registrationId' => 'reg-late-2',
            'customParameters' => ['subscription_uuid' => $subscription->uuid],
        ];
        $success['signature'] = $this->client->sign($success);
        $this->postWebhook($success)->assertStatus(200);

        // A duplicate of the original PENDING event replays after activation: it must
        // not resurrect a pending transaction keyed on the checkoutId.
        $this->postWebhook($pending)->assertStatus(200);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertEquals(1, Transaction::where('subscription_id', $subscription->id)->count());
        $this->assertDatabaseMissing('transactions', [
            'payment_provider_transaction_id' => $checkoutId,
        ]);
    }

    public function test_pending_then_success_subscription_webhooks_reconcile_to_single_transaction(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'status' => 'new',
            'payment_provider_subscription_id' => null,
            'extra_payment_provider_data' => null,
        ]);

        $checkoutId = 'chk-'.Str::random(8);

        // 1. Pending webhook: Peach's early checkout event carries NO payment id, so the
        //    transaction is keyed on the checkoutId.
        $pending = [
            'checkoutId' => $checkoutId,
            'merchantTransactionId' => 's-000000',
            'result_code' => '000.200.100',
            'paymentType' => 'DB',
            'amount' => '19.99',
            'currency' => 'USD',
            'customParameters' => ['subscription_uuid' => $subscription->uuid],
        ];
        $pending['signature'] = $this->client->sign($pending);
        $this->postWebhook($pending)->assertStatus(200);

        // 2. Success webhook: real payment id + registration. Must reconcile the pending
        //    transaction onto the real id rather than create a second one.
        $realPaymentId = 'pay-'.Str::random(12);
        $success = [
            'checkoutId' => $checkoutId,
            'merchantTransactionId' => 's-000000',
            'id' => $realPaymentId,
            'result_code' => '000.100.110',
            'paymentType' => 'DB',
            'amount' => '19.99',
            'currency' => 'USD',
            'registrationId' => 'reg-reconcile-1',
            'customParameters' => ['subscription_uuid' => $subscription->uuid],
        ];
        $success['signature'] = $this->client->sign($success);
        $this->postWebhook($success)->assertStatus(200);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::ACTIVE->value, $subscription->status);

        // Exactly one transaction, now keyed on the real payment id and marked success.
        $this->assertEquals(1, Transaction::where('subscription_id', $subscription->id)->count());
        $this->assertDatabaseHas('transactions', [
            'subscription_id' => $subscription->id,
            'status' => TransactionStatus::SUCCESS->value,
            'payment_provider_transaction_id' => $realPaymentId,
        ]);
        $this->assertDatabaseMissing('transactions', [
            'payment_provider_transaction_id' => $checkoutId,
        ]);
    }

    public function test_order_webhook_with_peach_dotted_field_names_verifies_and_succeeds(): void
    {
        $user = $this->createUser();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
        ]);

        // Peach signs the ORIGINAL wire field names, which use dot notation
        // (result.code, card.bin, merchant.name) and bracket notation
        // (customParameters[order_uuid]). PHP would rewrite these on $request->all(),
        // so this payload only verifies when the signature is computed from the raw
        // body — this is the exact shape a live Peach Checkout webhook sends.
        $fields = [
            'amount' => '25.00',
            'card.bin' => '420000',
            'card.last4Digits' => '0000',
            'checkoutId' => 'chk-'.Str::random(8),
            'currency' => 'USD',
            'customParameters[order_uuid]' => $order->uuid,
            'eventType' => 'checkout',
            'id' => 'pay-'.Str::random(12),
            'merchant.name' => 'SB MyMage',
            'merchantTransactionId' => 'o-000000',
            'paymentBrand' => 'VISA',
            'paymentType' => 'DB',
            'result.code' => '000.100.110',
            'result.description' => "Request successfully processed in 'Merchant in Integrator Test Mode'",
            'timestamp' => '2026-07-10T06:53:21Z',
        ];

        // Compute the signature exactly as Peach does: sort by key, concat key.value, HMAC-SHA256.
        ksort($fields, SORT_STRING);
        $concat = '';
        foreach ($fields as $k => $v) {
            $concat .= $k.$v;
        }
        $signature = hash_hmac('sha256', $concat, 'secret-token-123');

        $raw = http_build_query($fields + ['signature' => $signature], '', '&', PHP_QUERY_RFC1738);

        // Mirror a real request: PHP populates the parsed params (dots -> underscores,
        // brackets -> nested) while the raw body keeps the original signed field names.
        parse_str($raw, $parsed);

        $response = $this->call(
            'POST',
            route('payments-providers.peachpayments.webhook'),
            $parsed,
            [],
            [],
            ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            $raw,
        );

        $response->assertStatus(200);

        $order->refresh();
        $this->assertEquals(OrderStatus::SUCCESS->value, $order->status);
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => TransactionStatus::SUCCESS->value,
            'payment_provider_transaction_id' => $fields['id'],
        ]);
    }

    private function buildOrderPayload(string $orderUuid, string $resultCode, string $paymentType): array
    {
        return [
            'checkoutId' => 'chk-'.Str::random(8),
            'merchantTransactionId' => 'o-000000',
            'id' => 'pay-'.Str::random(12),
            'result_code' => $resultCode,
            'result_description' => 'Test result',
            'paymentType' => $paymentType,
            'amount' => '100.00',
            'currency' => 'USD',
            'customParameters' => [
                'order_uuid' => $orderUuid,
            ],
        ];
    }

    private function signedOrderPayload(string $orderUuid, string $resultCode, string $paymentType): array
    {
        $payload = $this->buildOrderPayload($orderUuid, $resultCode, $paymentType);
        $payload['signature'] = $this->client->sign($payload);

        return $payload;
    }

    private function signedSubscriptionPayload(string $subscriptionUuid, string $resultCode, string $paymentType, string $registrationId): array
    {
        $payload = [
            'checkoutId' => 'chk-'.Str::random(8),
            'merchantTransactionId' => 's-000000',
            'id' => 'pay-'.Str::random(12),
            'result_code' => $resultCode,
            'result_description' => 'Test result',
            'paymentType' => $paymentType,
            'amount' => '19.99',
            'currency' => 'USD',
            'registrationId' => $registrationId,
            'customParameters' => [
                'subscription_uuid' => $subscriptionUuid,
            ],
        ];

        $payload['signature'] = $this->client->sign($payload);

        return $payload;
    }
}
