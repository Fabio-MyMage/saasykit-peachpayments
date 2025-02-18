<?php

namespace App\Http\Controllers\PaymentProviders;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\PaymentProviders\Stripe\PeachpaymentsWebhookHandler;

class PeachpaymentsController extends Controller
{
    public function handleWebhook(Request $request)
    {
        return response()->json(['status' => 'success']);
    }
}
