<?php

namespace App\Services\PaymentProviders\PeachPayments;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\OrderManager;
use App\Services\TransactionManager;
use App\Services\SubscriptionManager;

class PeachPaymentsProvider
{
    public function __construct(
        private SubscriptionManager $subscriptionManager,
        private TransactionManager $transactionManager,
        private OrderManager $orderManager,
    ) {}

    public function handleWebhook(Request $request): JsonResponse
    {
        try {
            // TODO
            return response()->json(['message' => 'Webhook handled successfully']);
        } catch (\Exception $e) {
            // TODO
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
