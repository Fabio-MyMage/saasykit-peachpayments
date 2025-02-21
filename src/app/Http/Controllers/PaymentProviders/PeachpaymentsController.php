<?php

namespace App\Http\Controllers\PaymentProviders;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\PaymentProviders\PeachPayments\PeachPaymentsWebhookHandler;

class PeachPaymentsController extends Controller
{
    public function handleWebhook(Request $request, PeachPaymentsWebhookHandler $handler)
    {
        return $handler->handleWebhook($request);
    }
}
