<?php

namespace App\Http\Controllers\PaymentProviders;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class PeachpaymentsController extends Controller
{
    public function handleWebhook(Request $request)
    {
        // TODO handle the webhook
        return response()->json(['status' => 'success']);
    }
}
