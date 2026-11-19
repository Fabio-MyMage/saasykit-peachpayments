<?php

namespace App\Console\Commands;

use App\Client\PeachPaymentsClient;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\Currency;
use App\Models\PaymentProvider;
use App\Models\Subscription;
use App\Services\SubscriptionService;
use App\Services\TransactionService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PeachPaymentsChargeRenewals extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:peachpayments-charge-renewals
        {--settle= : UUID of a subscription whose renewal outcome is unknown, once Peach shows the charge succeeded: records it and starts the next period}
        {--release= : UUID of a subscription whose renewal outcome is unknown, once Peach shows no charge was taken: the next run charges it again}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Charge due Peach Payments-managed subscription renewals via the recurring (MIT) card API.';

    private int $processedCount = 0;

    private int $renewedCount = 0;

    private int $failedCount = 0;

    private int $canceledCount = 0;

    private int $pendingCount = 0;

    private int $skippedCount = 0;

    private int $unresolvedCount = 0;

    public function __construct(
        private PeachPaymentsClient $peachPaymentsClient,
        private SubscriptionService $subscriptionService,
        private TransactionService $transactionService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $paymentProvider = PaymentProvider::where('slug', PaymentProviderConstants::PEACH_PAYMENTS_SLUG)->first();

        if (! $paymentProvider) {
            $this->error('Peach Payments payment provider is not configured. Aborting.');

            return self::FAILURE;
        }

        if ($this->option('settle') || $this->option('release')) {
            return $this->resolve($paymentProvider, (string) ($this->option('settle') ?: $this->option('release')), (bool) $this->option('settle'));
        }

        Subscription::where('payment_provider_id', $paymentProvider->id)
            ->where('type', SubscriptionType::PAYMENT_PROVIDER_MANAGED)
            ->whereIn('status', [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::PAST_DUE->value])
            ->where('ends_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($paymentProvider) {
                foreach ($subscriptions as $subscription) {
                    $this->processSubscription($subscription, $paymentProvider);
                }
            });

        $this->info(sprintf(
            'Done. processed=%d renewed=%d failed=%d canceled=%d pending=%d skipped=%d unresolved=%d',
            $this->processedCount,
            $this->renewedCount,
            $this->failedCount,
            $this->canceledCount,
            $this->pendingCount,
            $this->skippedCount,
            $this->unresolvedCount,
        ));

        return self::SUCCESS;
    }

    /**
     * Three steps, so a charge is never repeated: (1) under the row lock, decide and record the attempt before any
     * money moves; (2) charge the card with no database transaction open, so an error after Peach took the money
     * cannot roll back that record; (3) under the lock again, record the outcome. A charge whose outcome is unknown
     * (pending, a timeout, a server error) keeps its record, and no run charges that subscription again until an
     * operator checks Peach and runs --settle or --release.
     */
    private function processSubscription(Subscription $subscription, PaymentProvider $paymentProvider): void
    {
        $this->processedCount++;

        try {
            $attempt = DB::transaction(function () use ($subscription) {
                return $this->prepareCharge(Subscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail());
            });
            if ($attempt === null) {
                return;
            }

            try {
                $response = $this->peachPaymentsClient->chargeRegistration(
                    $attempt['registration_id'],
                    $attempt['amount'],
                    $attempt['currency_code'],
                    $attempt['charge_extra'],
                );
            } catch (Throwable $e) {
                $this->unresolvedCount++;
                Log::error('PeachPaymentsChargeRenewals: renewal outcome unknown, the subscription waits for an operator.', [
                    'subscription_uuid' => $subscription->uuid,
                    'merchant_transaction_id' => $attempt['merchant_transaction_id'],
                    'exception' => $e->getMessage(),
                ]);
                $this->error(sprintf('[%s] renewal outcome unknown (%s). Check Peach for %s, then run with --settle or --release.',
                    $subscription->uuid, $e->getMessage(), $attempt['merchant_transaction_id']));

                return;
            }

            DB::transaction(function () use ($subscription, $paymentProvider, $attempt, $response) {
                $this->recordOutcome(Subscription::where('id', $subscription->id)->lockForUpdate()->firstOrFail(), $paymentProvider, $attempt, $response);
            });
        } catch (Throwable $e) {
            Log::error('PeachPaymentsChargeRenewals: exception while processing subscription.', [
                'subscription_uuid' => $subscription->uuid,
                'exception' => $e->getMessage(),
            ]);

            $this->error(sprintf('[%s] exception: %s', $subscription->uuid, $e->getMessage()));
        }
    }

    /**
     * Decides whether a locked subscription is charged now and, if so, records the attempt on it.
     *
     * @return array{registration_id: string, amount: string, currency_code: string, charge_extra: array<string, string>, amount_minor: int, discount_minor: int, discount_used_up: bool, merchant_transaction_id: string}|null
     */
    private function prepareCharge(Subscription $subscription): ?array
    {
        // Re-check due-ness under the lock: a concurrent run may have renewed or
        // canceled this subscription between the outer query and acquiring the lock.
        if (! in_array($subscription->status, [SubscriptionStatus::ACTIVE->value, SubscriptionStatus::PAST_DUE->value], true)
            || $subscription->ends_at === null
            || Carbon::parse($subscription->ends_at)->isFuture()
        ) {
            $this->skippedCount++;

            return null;
        }

        // Cancellation requested for end of cycle and the cycle has now ended: stop billing.
        if ($subscription->is_canceled_at_end_of_cycle) {
            $this->subscriptionService->updateSubscription($subscription, [
                'status' => SubscriptionStatus::CANCELED->value,
            ]);

            $this->canceledCount++;
            $this->info(sprintf('[%s] canceled at end of cycle.', $subscription->uuid));

            return null;
        }

        $extra = $subscription->extra_payment_provider_data ?? [];

        // An earlier charge whose outcome is unknown: charging again could take the money twice.
        if (! empty($extra['renewal_in_flight'])) {
            $this->unresolvedCount++;
            $this->error(sprintf('[%s] an earlier renewal (%s, since %s) has no known outcome. Check Peach, then run with --settle or --release.',
                $subscription->uuid, $extra['renewal_in_flight']['merchant_transaction_id'] ?? '?', $extra['renewal_in_flight']['started_at'] ?? '?'));

            return null;
        }

        // Daily retry cadence for subscriptions already flagged PAST_DUE.
        if ($subscription->status === SubscriptionStatus::PAST_DUE->value && ! empty($extra['last_renewal_attempt_at'])) {
            $lastAttemptAt = Carbon::parse($extra['last_renewal_attempt_at']);

            // Absolute diff: Carbon 3 returns signed values by default, which would
            // make this comparison always true for past timestamps.
            if (now()->diffInHours($lastAttemptAt, true) < 24) {
                $this->skippedCount++;
                $this->info(sprintf('[%s] retry not due yet (last attempt %s).', $subscription->uuid, $lastAttemptAt->toIso8601String()));

                return null;
            }
        }

        $registrationId = $extra['registration_id'] ?? $subscription->payment_provider_subscription_id ?? null;

        if (! $registrationId) {
            $this->skippedCount++;
            $this->error(sprintf('[%s] no registration id available, cannot charge. Skipping.', $subscription->uuid));
            Log::error('PeachPaymentsChargeRenewals: missing registration id.', ['subscription_uuid' => $subscription->uuid]);

            return null;
        }

        $amountMinor = (int) $subscription->price;
        [$discountMinor, $discountUsedUp] = $this->renewalDiscount($extra, $amountMinor);
        $chargeAmountMinor = max(0, $amountMinor - $discountMinor);

        // 'r' + base36 subscription id + '-' + base36 timestamp: collision-free and
        // comfortably within Peach's 8-16 char merchantTransactionId limit for decades.
        $merchantTransactionId = sprintf(
            'r%s-%s',
            base_convert((string) $subscription->id, 10, 36),
            base_convert((string) now()->timestamp, 10, 36)
        );

        $chargeExtra = ['merchantTransactionId' => $merchantTransactionId];

        // Peach requires the initial (CIT) transaction id on every subsequent MIT charge.
        // Omit it if unknown rather than sending a wrong value (it is NOT the registration id).
        if (! empty($extra['initial_transaction_id'])) {
            $chargeExtra['standingInstruction.initialTransactionId'] = $extra['initial_transaction_id'];
        }

        // Recorded before any money moves, so a charge whose response is lost is never repeated.
        $extra['renewal_in_flight'] = [
            'merchant_transaction_id' => $merchantTransactionId,
            'amount_minor' => $chargeAmountMinor,
            'discount_minor' => $discountMinor,
            'started_at' => now()->toIso8601String(),
        ];
        $this->subscriptionService->updateSubscription($subscription, ['extra_payment_provider_data' => $extra]);

        return [
            'registration_id' => (string) $registrationId,
            // Format the decimal amount directly from integer minor units — no float division.
            'amount' => sprintf('%d.%02d', intdiv($chargeAmountMinor, 100), $chargeAmountMinor % 100),
            'currency_code' => $subscription->currency->code,
            'charge_extra' => $chargeExtra,
            'amount_minor' => $chargeAmountMinor,
            'discount_minor' => $discountMinor,
            'discount_used_up' => $discountUsedUp,
            'merchant_transaction_id' => $merchantTransactionId,
        ];
    }

    /**
     * The discount this renewal gets, in minor units, and whether it is used up afterwards. Like Stripe's coupons: a
     * duration applies until valid_until, recurring without one applies forever, otherwise it applies once.
     *
     * @param  array<string, mixed>  $extra
     * @return array{0: int, 1: bool}
     */
    private function renewalDiscount(array $extra, int $amountMinor): array
    {
        $discount = $extra['discount'] ?? null;
        if (! is_array($discount)) {
            return [0, false];
        }
        if (! empty($discount['valid_until']) && now()->gte(Carbon::parse($discount['valid_until']))) {
            return [0, true];
        }
        $minor = ($discount['type'] ?? null) === 'percentage'
            ? (int) round($amountMinor * ((float) ($discount['amount'] ?? 0) / 100))
            : (int) ($discount['amount'] ?? 0);

        return [min($amountMinor, max(0, $minor)), empty($discount['is_recurring']) && empty($discount['duration_in_months'])];
    }

    /**
     * @param  array{amount_minor: int, discount_minor: int, discount_used_up: bool, merchant_transaction_id: string}  $attempt
     * @param  array<string, mixed>  $response
     */
    private function recordOutcome(Subscription $subscription, PaymentProvider $paymentProvider, array $attempt, array $response): void
    {
        $resultCode = (string) ($response['result']['code'] ?? '');
        $paymentId = (string) ($response['id'] ?? $attempt['merchant_transaction_id']);
        $currency = $subscription->currency;

        if (preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[12]0)/', $resultCode)) {
            $this->handleSuccessfulRenewal($subscription, $paymentProvider, $currency, $attempt['amount_minor'], $attempt['discount_minor'], $paymentId, $resultCode, $attempt['discount_used_up']);

            return;
        }

        if (preg_match('/^(000\.200)/', $resultCode)) {
            // Pending: not a definitive outcome yet. The attempt record stays, so no run charges again; an operator
            // settles or releases it once Peach shows the result.
            $this->transactionService->createForSubscription(
                $subscription,
                $attempt['amount_minor'],
                0,
                $attempt['discount_minor'],
                0,
                $currency,
                $paymentProvider,
                $paymentId,
                $resultCode,
                TransactionStatus::PENDING,
            );
            $extra = $subscription->extra_payment_provider_data ?? [];
            $extra['renewal_in_flight'] = [...($extra['renewal_in_flight'] ?? []), 'payment_id' => $paymentId, 'result_code' => $resultCode];
            $this->subscriptionService->updateSubscription($subscription, ['extra_payment_provider_data' => $extra]);

            $this->pendingCount++;
            $this->info(sprintf('[%s] renewal charge pending (result_code=%s). Check Peach, then run with --settle or --release.', $subscription->uuid, $resultCode));

            return;
        }

        $this->handleFailedRenewal($subscription, $paymentProvider, $currency, $attempt['amount_minor'], $attempt['discount_minor'], $paymentId, $resultCode);
    }

    /** Settles (the charge succeeded in Peach) or releases (no charge was taken) a renewal with no known outcome. */
    private function resolve(PaymentProvider $paymentProvider, string $uuid, bool $settle): int
    {
        return DB::transaction(function () use ($paymentProvider, $uuid, $settle): int {
            $subscription = Subscription::where('uuid', $uuid)->where('payment_provider_id', $paymentProvider->id)->lockForUpdate()->first();
            $inFlight = $subscription?->extra_payment_provider_data['renewal_in_flight'] ?? null;
            if ($subscription === null || ! is_array($inFlight)) {
                $this->error(sprintf('[%s] has no renewal waiting for an outcome.', $uuid));

                return self::FAILURE;
            }
            $paymentId = (string) ($inFlight['payment_id'] ?? $inFlight['merchant_transaction_id']);
            $pending = $subscription->transactions()->where('status', TransactionStatus::PENDING->value)->where('payment_provider_transaction_id', $paymentId)->first();
            if ($settle) {
                if ($pending !== null) {
                    $this->transactionService->updateTransaction($pending, 'settled-by-operator', TransactionStatus::SUCCESS);
                }
                $this->handleSuccessfulRenewal($subscription, $paymentProvider, $subscription->currency, (int) $inFlight['amount_minor'], (int) ($inFlight['discount_minor'] ?? 0),
                    $paymentId, 'settled-by-operator', false, recordTransaction: $pending === null);
            } else {
                if ($pending !== null) {
                    $this->transactionService->updateTransaction($pending, 'released-by-operator', TransactionStatus::FAILED);
                }
                $extra = $subscription->extra_payment_provider_data;
                unset($extra['renewal_in_flight']);
                $this->subscriptionService->updateSubscription($subscription, ['extra_payment_provider_data' => $extra]);
                $this->info(sprintf('[%s] released; the next run charges it again.', $uuid));
            }

            return self::SUCCESS;
        });
    }

    private function handleSuccessfulRenewal(
        Subscription $subscription,
        PaymentProvider $paymentProvider,
        Currency $currency,
        int $chargeAmountMinor,
        int $discountMinor,
        string $paymentId,
        string $resultCode,
        bool $discountUsedUp,
        bool $recordTransaction = true,
    ): void {
        if ($recordTransaction) {
            $this->transactionService->createForSubscription(
                $subscription,
                $chargeAmountMinor,
                0,
                $discountMinor,
                0,
                $currency,
                $paymentProvider,
                $paymentId,
                $resultCode,
                TransactionStatus::SUCCESS,
            );
        }

        $interval = $subscription->interval()->firstOrFail();
        $currentEndsAt = Carbon::parse($subscription->ends_at);
        $candidateEndsAt = $currentEndsAt->copy()->add($interval->date_identifier, $subscription->interval_count);

        // If the plan-relative end date is still in the past (e.g. the command missed several
        // runs), base the new period off "now" instead, to avoid an ever-growing catch-up drift.
        $newEndsAt = $candidateEndsAt->isPast() ? now()->add($interval->date_identifier, $subscription->interval_count) : $candidateEndsAt;

        $extra = $subscription->extra_payment_provider_data ?? [];
        $extra['renewal_attempts'] = 0;
        unset($extra['last_renewal_attempt_at'], $extra['renewal_in_flight']);
        if ($discountUsedUp) {
            unset($extra['discount']);
        }

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => SubscriptionStatus::ACTIVE->value,
            'ends_at' => $newEndsAt,
            'payment_provider_status' => $resultCode,
            'extra_payment_provider_data' => $extra,
        ]);

        $this->renewedCount++;
        $this->info(sprintf('[%s] renewed successfully, new ends_at=%s.', $subscription->uuid, $newEndsAt->toIso8601String()));
    }

    private function handleFailedRenewal(
        Subscription $subscription,
        PaymentProvider $paymentProvider,
        Currency $currency,
        int $chargeAmountMinor,
        int $discountMinor,
        string $paymentId,
        string $resultCode,
    ): void {
        $this->transactionService->createForSubscription(
            $subscription,
            $chargeAmountMinor,
            0,
            $discountMinor,
            0,
            $currency,
            $paymentProvider,
            $paymentId,
            $resultCode,
            TransactionStatus::FAILED,
        );

        $maxAttempts = (int) config('services.peachpayments.max_renewal_retries', 3);
        $extra = $subscription->extra_payment_provider_data ?? [];
        $attempts = (int) ($extra['renewal_attempts'] ?? 0) + 1;

        $extra['renewal_attempts'] = $attempts;
        $extra['last_renewal_attempt_at'] = now()->toIso8601String();
        unset($extra['renewal_in_flight']);

        $newStatus = $attempts >= $maxAttempts
            ? SubscriptionStatus::CANCELED->value
            : SubscriptionStatus::PAST_DUE->value;

        $this->subscriptionService->updateSubscription($subscription, [
            'status' => $newStatus,
            'payment_provider_status' => $resultCode,
            'extra_payment_provider_data' => $extra,
        ]);

        // After the update, so listeners see the subscription's new status and attempt count.
        $this->subscriptionService->handleInvoicePaymentFailed($subscription);

        $this->failedCount++;

        if ($newStatus === SubscriptionStatus::CANCELED->value) {
            Log::warning('PeachPaymentsChargeRenewals: subscription canceled after exhausting renewal retries.', [
                'subscription_uuid' => $subscription->uuid,
                'attempts' => $attempts,
            ]);
            $this->error(sprintf('[%s] renewal failed, max retries (%d) reached, canceled.', $subscription->uuid, $maxAttempts));
        } else {
            $this->error(sprintf('[%s] renewal failed (result_code=%s), attempt %d/%d, marked past_due.', $subscription->uuid, $resultCode, $attempts, $maxAttempts));
        }
    }
}
