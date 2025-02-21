<?php

namespace App\Services\PaymentProviders\PeachPayments;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\OrderManager;
use App\Services\TransactionManager;
use App\Services\SubscriptionManager;
use PeachPayments\Signature;

class PeachPaymentsWebhookHandler
{
    public function __construct(
        private SubscriptionManager $subscriptionManager,
        private TransactionManager $transactionManager,
        private OrderManager $orderManager,
    ) {}

    public function handleWebhook(Request $request): JsonResponse
    {
        if (!$this->validateSignature($request)) {
            return response()->json([
                'message' => 'Invalid signature',
            ], 400);
        }

        return response()->json([
            'message' => 'Webhook received and validated',
        ]);
    }

    private function validateSignature(Request $request): bool
    {
        $secret = config('services.peachpayments.secret_token');
        $signature = Signature::generate($request->all(), $secret);
        $receivedSignature = $request->input('signature');

        if ($signature === $receivedSignature) {
            return true;
          } else {
            return false;
          }


        return true;
    }
}
