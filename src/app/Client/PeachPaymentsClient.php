<?php

namespace App\Client;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class PeachPaymentsClient
{
    /**
     * Thin HTTP client for the Peach Payments Checkout V2, card-host recurring (MIT),
     * and V1 refund APIs. See developer.peachpayments.com.
     */
    public function createCheckout(array $payload): array
    {
        $payload = $this->withAuthentication($payload);

        if (empty($payload['nonce'])) {
            $payload['nonce'] = Str::random(32);
        }

        $response = Http::withToken($this->getAccessToken())
            ->withHeaders([
                'Referer' => config('app.url'),
            ])
            ->acceptJson()
            ->post($this->getCheckoutUrl().'/v2/checkout', $payload);

        return $this->decodeOrFail($response, 'createCheckout');
    }

    /**
     * @see https://developer.peachpayments.com/ - GET /v2-checkout/{checkoutId}/status
     */
    public function getCheckoutStatus(string $checkoutId): array
    {
        $entityId = config('services.peachpayments.entity_id');

        $query = [
            'authentication.entityId' => $entityId,
        ];
        $query['signature'] = $this->sign($query);

        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->get($this->getCheckoutUrl().'/v2-checkout/'.$checkoutId.'/status', $query);

        return $this->decodeOrFail($response, 'getCheckoutStatus');
    }

    /**
     * Recurring (Merchant Initiated Transaction) charge against a tokenized
     * registration, via Peach's card server-to-server host.
     * Peach returns a synchronous result.code in the body even for
     * "failed" charges (e.g. declined card) - we return the body as-is so the
     * caller can inspect result.code, and only throw on a genuine transport error.
     */
    public function chargeRegistration(string $registrationId, float|string $amount, string $currency, array $extra = []): array
    {
        $params = array_merge([
            'entityId' => config('services.peachpayments.recurring_entity_id'),
            'amount' => is_string($amount) ? $amount : number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'paymentType' => 'DB',
            'standingInstruction.mode' => 'REPEATED',
            'standingInstruction.type' => 'UNSCHEDULED',
            'standingInstruction.source' => 'MIT',
        ], $extra);

        $response = Http::withToken(config('services.peachpayments.recurring_access_token'))
            ->asForm()
            ->acceptJson()
            ->post($this->getRecurringUrl().'/v1/registrations/'.$registrationId.'/payments', $params);

        if ($response->serverError()) {
            Log::error('PeachPaymentsClient::chargeRegistration transport error', [
                'registrationId' => $registrationId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // Full response body is in the log above; keep the exception message generic
            // so it cannot leak API details via error pages or exception trackers.
            throw new RuntimeException('Peach Payments chargeRegistration request failed with HTTP status '.$response->status());
        }

        return (array) $response->json();
    }

    /**
     * @see https://developer.peachpayments.com/ - POST /v1/checkout/refund
     */
    public function refund(string $paymentId, float $amount, string $currency): array
    {
        $params = [
            'authentication.entityId' => config('services.peachpayments.entity_id'),
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => $currency,
            'paymentType' => 'RF',
            'id' => $paymentId,
        ];

        $params['signature'] = $this->sign($params);

        $response = Http::asForm()
            ->acceptJson()
            ->post($this->getRefundsApiUrl().'/v1/checkout/refund', $params);

        return $this->decodeOrFail($response, 'refund');
    }

    /**
     * Peach signature scheme: sort params by key (byte order), concatenate
     * `key.value` pairs with no separator, HMAC-SHA256 (hex) using the secret token.
     */
    public function sign(array $params): string
    {
        $flat = $this->flatten($params);

        unset($flat['signature']);

        ksort($flat, SORT_STRING);

        $concatenated = '';
        foreach ($flat as $key => $value) {
            $concatenated .= $key.$value;
        }

        return hash_hmac('sha256', $concatenated, (string) config('services.peachpayments.secret_token'));
    }

    /**
     * Verifies a signature embedded in an incoming payload (redirect back or
     * webhook form post) against a freshly-computed one.
     *
     * NOTE: prefer verifyRawSignature() for real inbound Peach requests. Peach
     * signs the ORIGINAL wire field names, which use dot notation (e.g. `card.bin`,
     * `result.code`, `merchant.name`) and bracket notation (`customParameters[x]`).
     * PHP's form parser rewrites dots to underscores and brackets to nested arrays,
     * so an array taken from `$request->all()` no longer matches what Peach signed.
     * This array-based helper is only correct for params whose keys already match
     * the signed field names verbatim.
     */
    public function verifySignature(array $params): bool
    {
        if (! array_key_exists('signature', $params)) {
            return false;
        }

        $received = (string) $params['signature'];
        unset($params['signature']);

        $computed = $this->sign($params);

        return hash_equals($computed, $received);
    }

    /**
     * Verifies the signature of an inbound Peach request (Checkout webhook or the
     * shopperResultUrl redirect POST-back) directly from the raw request body, so the
     * original signed field names are preserved (see the note on verifySignature()).
     */
    public function verifyRawSignature(string $rawBody): bool
    {
        return $this->verifySignature($this->parseFormBody($rawBody));
    }

    /**
     * Parses an application/x-www-form-urlencoded body into a flat map, preserving
     * the exact field names as sent on the wire (no dot->underscore or bracket->array
     * rewriting). Values are URL-decoded, which is what Peach signs.
     */
    private function parseFormBody(string $rawBody): array
    {
        $params = [];

        foreach (explode('&', $rawBody) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $params[urldecode($key)] = urldecode($value);
        }

        return $params;
    }

    /**
     * OAuth client-credentials token, cached until shortly before expiry.
     *
     * @see https://developer.peachpayments.com/ - POST /api/oauth/token
     */
    private function getAccessToken(): string
    {
        return Cache::remember('peachpayments.access_token', $this->tokenTtl(), function () {
            $response = Http::acceptJson()
                ->post($this->getAuthUrl().'/api/oauth/token', [
                    'clientId' => config('services.peachpayments.client_id'),
                    'clientSecret' => config('services.peachpayments.client_secret'),
                    'merchantId' => config('services.peachpayments.merchant_id'),
                ]);

            $body = $this->decodeOrFail($response, 'getAccessToken');

            // Stash the actual expiry alongside the token so tokenTtl() can react
            // to it on the *next* fetch; on first fetch we fall back to a safe default.
            Cache::put('peachpayments.access_token.expires_in', $body['expires_in'] ?? 300, 3600);

            return $body['access_token'] ?? $body['token'] ?? '';
        });
    }

    private function tokenTtl(): int
    {
        $expiresIn = (int) Cache::get('peachpayments.access_token.expires_in', 300);

        return max(30, $expiresIn - 30);
    }

    private function withAuthentication(array $payload): array
    {
        if (empty($payload['authentication']['entityId'])) {
            $payload['authentication']['entityId'] = config('services.peachpayments.entity_id');
        }

        return $payload;
    }

    /**
     * Flattens a (possibly nested) params array into the exact key names Peach
     * expects on the wire: dot notation for grouped fields (authentication.entityId,
     * standingInstruction.mode, ...), bracket notation for customParameters
     * (customParameters[name]).
     */
    private function flatten(array $params, string $prefix = ''): array
    {
        $flat = [];

        foreach ($params as $key => $value) {
            if (! is_array($value)) {
                $flatKey = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                $flat[$flatKey] = (string) $value;

                continue;
            }

            if ($key === 'customParameters' && $prefix === '') {
                foreach ($value as $paramName => $paramValue) {
                    $flat['customParameters['.$paramName.']'] = (string) $paramValue;
                }

                continue;
            }

            $nestedPrefix = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $flat = array_merge($flat, $this->flatten($value, $nestedPrefix));
        }

        return $flat;
    }

    private function decodeOrFail(Response $response, string $operation): array
    {
        if ($response->failed()) {
            Log::error("PeachPaymentsClient::{$operation} request failed", [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // Full response body is in the log above; keep the exception message generic
            // so it cannot leak API details via error pages or exception trackers.
            throw new RuntimeException("Peach Payments {$operation} request failed with HTTP status {$response->status()}");
        }

        return (array) $response->json();
    }

    private function getAuthUrl(): string
    {
        return config('services.peachpayments.test_mode')
            ? 'https://sandbox-dashboard.peachpayments.com'
            : 'https://dashboard.peachpayments.com';
    }

    private function getCheckoutUrl(): string
    {
        return config('services.peachpayments.test_mode')
            ? 'https://testsecure.peachpayments.com'
            : 'https://secure.peachpayments.com';
    }

    private function getRefundsApiUrl(): string
    {
        return config('services.peachpayments.test_mode')
            ? 'https://testapi.peachpayments.com'
            : 'https://api.peachpayments.com';
    }

    /**
     * Peach's card server-to-server host, used for charging stored registrations
     * (recurring / MIT). Peach-issued recurring tokens only authenticate against this
     * host - the raw OPPWA hosts (eu-*.oppwa.com) reject them with 800.900.300.
     *
     * @see https://developer.peachpayments.com/docs/card-manage-payments
     */
    private function getRecurringUrl(): string
    {
        return config('services.peachpayments.test_mode')
            ? 'https://sandbox-card.peachpayments.com'
            : 'https://card.peachpayments.com';
    }
}
