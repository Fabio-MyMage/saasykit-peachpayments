<?php

namespace App\Http\Controllers\PaymentProviders;

use App\Client\PeachPaymentsClient;
use App\Http\Controllers\Controller;
use App\Services\PaymentProviders\PeachPayments\PeachPaymentsWebhookHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PeachPaymentsController extends Controller
{
    public function handleWebhook(Request $request, PeachPaymentsWebhookHandler $handler): JsonResponse
    {
        return $handler->handleWebhook($request);
    }

    /**
     * Handles Peach's `shopperResultUrl` POST-back after the shopper completes (or
     * abandons) the hosted checkout page, and redirects the *browser* to SaasyKit's
     * own success/failure pages. Order/subscription state itself is only ever
     * mutated by the asynchronous webhook (handleWebhook above) - this action is
     * purely about where to send the shopper's browser.
     */
    public function checkoutResult(Request $request, PeachPaymentsClient $client): RedirectResponse
    {
        if (! $client->verifyRawSignature($request->getContent())) {
            abort(401);
        }

        $merchantTransactionId = (string) $request->input('merchantTransactionId');

        // Peach sends the result code as a literal `result.code` form field, which
        // PHP's form parser rewrites to `result_code` before it reaches the request.
        $resultCode = (string) $request->input('result_code');

        if ($this->isSuccessOrPending($resultCode)) {
            if (str_starts_with($merchantTransactionId, 'pmc-')) {
                return redirect()->route('home')->with('success', __('Your payment method has been updated.'));
            }

            if (str_starts_with($merchantTransactionId, 's-')) {
                return redirect()->route('checkout.subscription.success');
            }

            if (str_starts_with($merchantTransactionId, 'o-')) {
                return redirect()->route('checkout.product.success');
            }

            // Shouldn't happen (we always generate a prefixed merchantTransactionId
            // ourselves), but fail safe rather than 500.
            return redirect()->route('home');
        }

        return redirect()->route('home')->with('error', __('Your payment could not be completed. Please try again.'));
    }

    /**
     * @see https://developer.peachpayments.com/docs/dashboard-response-codes
     */
    private function isSuccessOrPending(string $resultCode): bool
    {
        return (bool) preg_match('/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[12]0|000\.200)/', $resultCode);
    }
}
