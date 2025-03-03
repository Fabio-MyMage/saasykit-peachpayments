<?php

namespace App\Services\PaymentProviders\PeachPayments;

use function response;
use App\Constants\PaymentProviderConstants;
use App\Constants\SubscriptionStatus;
use App\Constants\SubscriptionType;
use App\Constants\TransactionStatus;
use App\Models\PaymentProvider;
use App\Models\Currency;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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
        // TODO Handle initial webhook creation request

        if (!$this->validateSignature($request)) {
            return response()->json([
                'message' => 'Invalid signature',
            ], 400);
        }

        $order = $this->orderManager->findByUuidOrFail($request->input(['merchantTransactionId']));
        $paymentProvider = PaymentProvider::where('slug', PaymentProviderConstants::PEACHPAYMENTS_SLUG)->firstOrFail();
        $transactionStatusCode = $request->input('result_code');
        $transactionStatus = $this->transactionStatus($transactionStatusCode);
        $transactionStatusCase = $this->transactionStatusCase($transactionStatus);
        $currency = Currency::where('code', strtoupper($request->input('currency')))->firstOrFail();

        $this->orderManager->updateOrder($order, [
            'status' => $transactionStatus,
            'payment_provider_id' => $paymentProvider->id,
            'payment_provider_order_id' => $request->input('id'),
        ]);

        $transaction = $this->transactionManager->getTransactionByPaymentProviderTxId($order->payment_provider_order_id);

        if (!$transaction) {
            $this->transactionManager->createForOrder(
                $order,
                $order->total_amount,
                0,
                $order->total_discount_amount,
                0,
                $currency,
                $paymentProvider,
                $request->input('id'),
                $request->input('result_description'),
                $transactionStatusCase
            );
        } else {
            $this->transactionManager->updateTransactionByPaymentProviderTxId(
                $transaction->payment_provider_transaction_id,
                $request->input('result_description'),
                $transactionStatusCase,
            );
        }

        return response()->json();
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

    private function transactionStatus(string $transactionStatusCode): string
    {
        // https://developer.peachpayments.com/docs/dashboard-response-codes#result-codes
        if (preg_match('/^(000.000.|000.100.1|000.[36]|000.400.[1][12]0)/', $transactionStatusCode)) {
            return TransactionStatus::SUCCESS->value;
        }

        if (preg_match('/^(000.400.0[^3]|000.400.100)/', $transactionStatusCode)) {
            return TransactionStatus::SUCCESS->value;
        }

        if (preg_match('/^(000\.200)/', $transactionStatusCode)) {
            return TransactionStatus::PENDING->value;
        }

        $rejectedPatterns = [
            '/^(000\.400\.[1][0-9][1-9]|000\.400\.2)/',
            '/^(800\.[17]00|800\.800\.[123])/',
            '/^(900\.[1234]00|000\.400\.030)/',
            '/^(100\.39[765])/',
            '/^(300\.100\.100)/',
            '/^(100\.400\.[0-3]|100\.380\.100|100\.380\.11|100\.380\.4|100\.380\.5)/',
            '/^(800\.400\.1)/',
            '/^(800\.400\.2|100\.390)/',
            '/^(800\.[32])/',
            '/^(800\.1[123456]0)/',
            '/^(600\.[23]|500\.[12]|800\.121)/',
            '/^(100\.[13]50)/',
            '/^(100\.250|100\.360)/',
            '/^(700\.[1345][05]0)/',
            '/^(200\.[123]|100\.[53][07]|800\.900|100)\.[69]00\.500)/',
            '/^(100\.800)/',
            '/^(100\.700|100\.900\.[123467890][00-99])/',
            '/^(100\.100|100.2[01])/',
            '/^(100\.55)/',
            '/^(100\.380\.[23]|100\.380\.101)/',
            '/^(000\.100\.2)/',
        ];

        foreach ($rejectedPatterns as $pattern) {
            if (preg_match($pattern, $transactionStatusCode)) {
                return TransactionStatus::FAILED->value;
            }
        }

        // Default case if no patterns match
        return TransactionStatus::NOT_STARTED->value;
    }

    private function transactionStatusCase(string $status): TransactionStatus
    {
        if ($status == 'success') {
            return TransactionStatus::SUCCESS;
        }

        if ($status == 'failed') {
            return TransactionStatus::FAILED;
        }

        if ($status == 'pending') {
            return TransactionStatus::PENDING;
        }

        if ($status == 'refunded') {
            return TransactionStatus::REFUNDED;
        }

        if ($status == 'disputed') {
            return TransactionStatus::DISPUTED;
        }

        // Default case if no patterns match
        return TransactionStatus::NOT_STARTED;
    }
}
