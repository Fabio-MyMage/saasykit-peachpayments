<?php

namespace Tests\Feature\Console;

use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Events\Subscription\InvoicePaymentFailed;
use App\Models\Currency;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
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

    /** A Peach-managed subscription whose period ended yesterday. */
    private function dueSubscription(array $extra = [], array $attributes = []): Subscription
    {
        return Subscription::factory()->create([
            'user_id' => $this->createUser()->id,
            'currency_id' => $this->currency->id,
            'payment_provider_id' => $this->paymentProvider->id,
            'type' => SubscriptionType::PAYMENT_PROVIDER_MANAGED,
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => Carbon::now()->subDay()->startOfSecond(),
            'payment_provider_subscription_id' => 'reg-due',
            'extra_payment_provider_data' => ['registration_id' => 'reg-due', ...$extra],
            'price' => 2000,
            ...$attributes,
        ]);
    }

    private function fakeCharge(string $code, string $id = 'pay-1'): void
    {
        Http::fake(['https://sandbox-card.peachpayments.com/v1/registrations/*' => Http::response(['id' => $id, 'result' => ['code' => $code]], 200)]);
    }

    public function test_a_card_change_charge_after_the_period_end_is_not_mistaken_for_the_renewal(): void
    {
        $subscription = $this->dueSubscription();
        $this->app->make(TransactionService::class)->createForSubscription($subscription, 100, 0, 0, 0, $this->currency,
            $this->paymentProvider, 'card-change-1', '000.000.000', TransactionStatus::SUCCESS);
        $this->fakeCharge('000.000.000', 'pay-renewal');

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $this->assertTrue(Carbon::parse($subscription->fresh()->ends_at)->isFuture());
        $this->assertDatabaseHas('transactions', ['subscription_id' => $subscription->id, 'payment_provider_transaction_id' => 'pay-renewal']);
    }

    public function test_a_pending_renewal_is_never_charged_again_until_an_operator_settles_it(): void
    {
        $subscription = $this->dueSubscription();
        $this->fakeCharge('000.200.000', 'pay-pending');

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);
        $this->travel(25)->hours();
        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('transactions', ['payment_provider_transaction_id' => 'pay-pending', 'status' => TransactionStatus::PENDING->value]);

        $this->artisan('app:peachpayments-charge-renewals', ['--settle' => $subscription->uuid])->assertExitCode(0);

        $subscription->refresh();
        $this->assertTrue(Carbon::parse($subscription->ends_at)->isFuture());
        $this->assertArrayNotHasKey('renewal_in_flight', $subscription->extra_payment_provider_data);
        $this->assertDatabaseHas('transactions', ['payment_provider_transaction_id' => 'pay-pending', 'status' => TransactionStatus::SUCCESS->value]);
    }

    public function test_a_charge_whose_response_is_lost_is_never_repeated_until_an_operator_releases_it(): void
    {
        $subscription = $this->dueSubscription();
        $attempts = 0;
        $lost = true;
        Http::fake(['https://sandbox-card.peachpayments.com/v1/registrations/*' => function () use (&$attempts, &$lost) {
            $attempts++;
            if ($lost) {
                throw new ConnectionException('timed out');
            }

            return Http::response(['id' => 'pay-after-release', 'result' => ['code' => '000.000.000']], 200);
        }]);

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);
        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $this->assertSame(1, $attempts);
        $this->assertNotEmpty($subscription->fresh()->extra_payment_provider_data['renewal_in_flight']);

        $this->artisan('app:peachpayments-charge-renewals', ['--release' => $subscription->uuid])->assertExitCode(0);
        $lost = false;
        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $this->assertDatabaseHas('transactions', ['payment_provider_transaction_id' => 'pay-after-release', 'status' => TransactionStatus::SUCCESS->value]);
    }

    public function test_a_one_off_discount_applies_to_one_renewal_and_an_expired_one_to_none(): void
    {
        $once = $this->dueSubscription(['discount' => ['type' => 'percentage', 'amount' => 50, 'is_recurring' => false, 'duration_in_months' => null]]);
        $expired = $this->dueSubscription(['discount' => ['type' => 'percentage', 'amount' => 50, 'is_recurring' => true, 'duration_in_months' => 1,
            'valid_until' => now()->subDay()->toIso8601String()]]);
        $forever = $this->dueSubscription(['discount' => ['type' => 'fixed', 'amount' => 500, 'is_recurring' => true, 'duration_in_months' => null]]);
        $this->fakeCharge('000.000.000');

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $charged = fn (Subscription $subscription) => Transaction::where('subscription_id', $subscription->id)->value('amount');
        $this->assertSame([1000, 2000, 1500], [(int) $charged($once), (int) $charged($expired), (int) $charged($forever)]);
        $this->assertArrayNotHasKey('discount', $once->fresh()->extra_payment_provider_data);
        $this->assertArrayNotHasKey('discount', $expired->fresh()->extra_payment_provider_data);
        $this->assertArrayHasKey('discount', $forever->fresh()->extra_payment_provider_data);
    }

    public function test_the_failed_payment_event_sees_the_updated_subscription(): void
    {
        $subscription = $this->dueSubscription();
        $seen = null;
        Event::listen(InvoicePaymentFailed::class, function ($event) use (&$seen) {
            $seen = Subscription::find($event->subscription->id)->status;
        });
        $this->fakeCharge('800.100.151');

        $this->artisan('app:peachpayments-charge-renewals')->assertExitCode(0);

        $this->assertSame(SubscriptionStatus::PAST_DUE->value, $seen);
    }
}
