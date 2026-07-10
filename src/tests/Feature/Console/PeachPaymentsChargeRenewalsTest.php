<?php

namespace Tests\Feature\Console;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\Currency;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class PeachPaymentsChargeRenewalsTest extends FeatureTest
{
    private PaymentProvider $paymentProvider;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.peachpayments.recurring_entity_id', 'recurring-entity-123');
        config()->set('services.peachpayments.recurring_access_token', 'recurring-access-token-123');
        config()->set('services.peachpayments.test_mode', true);
        config()->set('services.peachpayments.max_renewal_retries', 3);

        $this->paymentProvider = PaymentProvider::where('slug', PaymentProviderConstants::PEACH_PAYMENTS_SLUG)->firstOrFail();
        $this->currency = Currency::where('code', 'USD')->firstOrFail();
    }

    public function test_due_active_subscription_with_registration_id_is_renewed_on_success(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => Carbon::now()->subDay()->startOfSecond(),
            'payment_provider_subscription_id' => 'reg-success-1',
            'extra_payment_provider_data' => [
                'registration_id' => 'reg-success-1',
                'initial_transaction_id' => 'init-cit-1',
            ],
            'price' => 1999,
        ]);
        $expectedNewEndsAt = Carbon::parse($subscription->ends_at)->addMonth();

        Http::fake([
            'https://sandbox-card.peachpayments.com/v1/registrations/*' => Http::response([
                'id' => 'pay-renew-success-1',
                'result' => ['code' => '000.000.000'],
            ], 200),
        ]);

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertTrue($expectedNewEndsAt->equalTo(Carbon::parse($subscription->ends_at)));
        $this->assertEquals(0, $subscription->extra_payment_provider_data['renewal_attempts'] ?? null);

        $this->assertDatabaseHas('transactions', [
            'subscription_id' => $subscription->id,
            'status' => TransactionStatus::SUCCESS->value,
            'payment_provider_transaction_id' => 'pay-renew-success-1',
        ]);

        // The MIT charge must carry the stored initial (CIT) transaction id.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/registrations/reg-success-1/payments')
                && ($request['standingInstruction.initialTransactionId'] ?? null) === 'init-cit-1';
        });
    }

    public function test_due_subscription_is_marked_past_due_on_first_failure(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => Carbon::now()->subDay()->startOfSecond(),
            'payment_provider_subscription_id' => 'reg-fail-1',
            'extra_payment_provider_data' => ['registration_id' => 'reg-fail-1'],
            'price' => 1999,
        ]);

        Http::fake([
            'https://sandbox-card.peachpayments.com/v1/registrations/*' => Http::response([
                'id' => 'pay-renew-fail-1',
                'result' => ['code' => '800.100.155'],
            ], 400),
        ]);

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::PAST_DUE->value, $subscription->status);
        $this->assertEquals(1, $subscription->extra_payment_provider_data['renewal_attempts']);
        $this->assertArrayHasKey('last_renewal_attempt_at', $subscription->extra_payment_provider_data);

        $this->assertDatabaseHas('transactions', [
            'subscription_id' => $subscription->id,
            'status' => TransactionStatus::FAILED->value,
            'payment_provider_transaction_id' => 'pay-renew-fail-1',
        ]);
    }

    public function test_third_consecutive_failure_cancels_subscription(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => Carbon::now()->subDay()->startOfSecond(),
            'payment_provider_subscription_id' => 'reg-fail-final',
            'extra_payment_provider_data' => [
                'registration_id' => 'reg-fail-final',
                'renewal_attempts' => 2,
                'last_renewal_attempt_at' => Carbon::now()->subHours(30)->toIso8601String(),
            ],
            'price' => 1999,
        ]);

        Http::fake([
            'https://sandbox-card.peachpayments.com/v1/registrations/*' => Http::response([
                'id' => 'pay-renew-fail-final',
                'result' => ['code' => '800.100.155'],
            ], 400),
        ]);

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::CANCELED->value, $subscription->status);
        $this->assertEquals(3, $subscription->extra_payment_provider_data['renewal_attempts']);
    }

    public function test_subscription_canceled_at_end_of_cycle_is_canceled_without_charging(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => Carbon::now()->subDay()->startOfSecond(),
            'payment_provider_subscription_id' => 'reg-cancel-1',
            'extra_payment_provider_data' => ['registration_id' => 'reg-cancel-1'],
            'is_canceled_at_end_of_cycle' => true,
            'price' => 1999,
        ]);

        Http::fake();

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::CANCELED->value, $subscription->status);

        Http::assertNothingSent();
    }

    public function test_subscription_not_due_is_left_untouched(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => Carbon::now()->addDays(10)->startOfSecond(),
            'payment_provider_subscription_id' => 'reg-not-due',
            'extra_payment_provider_data' => ['registration_id' => 'reg-not-due'],
            'price' => 1999,
        ]);
        $originalEndsAt = Carbon::parse($subscription->ends_at);

        Http::fake();

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::ACTIVE->value, $subscription->status);
        $this->assertTrue($originalEndsAt->equalTo(Carbon::parse($subscription->ends_at)));

        Http::assertNothingSent();
    }

    public function test_past_due_subscription_with_recent_last_attempt_is_skipped(): void
    {
        $user = $this->createUser();
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::PAST_DUE->value,
            'ends_at' => Carbon::now()->subDay()->startOfSecond(),
            'payment_provider_subscription_id' => 'reg-recent-attempt',
            'extra_payment_provider_data' => [
                'registration_id' => 'reg-recent-attempt',
                'renewal_attempts' => 1,
                'last_renewal_attempt_at' => Carbon::now()->subHour()->toIso8601String(),
            ],
            'price' => 1999,
        ]);

        Http::fake();

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $subscription->refresh();
        $this->assertEquals(SubscriptionStatus::PAST_DUE->value, $subscription->status);

        Http::assertNothingSent();
    }
}
