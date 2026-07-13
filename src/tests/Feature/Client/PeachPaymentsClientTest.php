<?php

namespace Tests\Feature\Client;

use App\Client\PeachPaymentsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Feature\FeatureTest;

class PeachPaymentsClientTest extends FeatureTest
{
    private PeachPaymentsClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.peachpayments.entity_id', 'entity-123');
        config()->set('services.peachpayments.secret_token', 'secret-token-123');
        config()->set('services.peachpayments.client_id', 'client-123');
        config()->set('services.peachpayments.client_secret', 'client-secret-123');
        config()->set('services.peachpayments.merchant_id', 'merchant-123');
        config()->set('services.peachpayments.recurring_entity_id', 'recurring-entity-123');
        config()->set('services.peachpayments.recurring_access_token', 'recurring-access-token-123');
        config()->set('services.peachpayments.test_mode', true);
        config()->set('app.url', 'https://saasykit.test');

        // The access token is cached across requests/tests; clear it so each test
        // starts with a fresh OAuth exchange.
        Cache::forget('peachpayments.access_token');
        Cache::forget('peachpayments.access_token.expires_in');

        $this->client = app(PeachPaymentsClient::class);
    }

    public function test_sign_produces_deterministic_hmac_from_flattened_params(): void
    {
        $params = [
            'authentication' => [
                'entityId' => 'entity-123',
            ],
            'amount' => '10.00',
            'currency' => 'USD',
            'customParameters' => [
                'order_uuid' => 'abc-123',
            ],
        ];

        // Mirrors PeachPaymentsClient::flatten()'s documented key format: dot notation
        // for nested groups, bracket notation for customParameters.
        $flat = [
            'authentication.entityId' => 'entity-123',
            'amount' => '10.00',
            'currency' => 'USD',
            'customParameters[order_uuid]' => 'abc-123',
        ];

        ksort($flat, SORT_STRING);

        $concatenated = '';
        foreach ($flat as $key => $value) {
            $concatenated .= $key.$value;
        }

        $expected = hash_hmac('sha256', $concatenated, 'secret-token-123');

        $this->assertEquals($expected, $this->client->sign($params));
    }

    public function test_sign_and_verify_signature_round_trip(): void
    {
        $params = [
            'authentication' => ['entityId' => 'entity-123'],
            'amount' => '19.99',
            'currency' => 'ZAR',
            'customParameters' => ['order_uuid' => 'order-uuid-1'],
        ];

        $params['signature'] = $this->client->sign($params);

        $this->assertTrue($this->client->verifySignature($params));
    }

    public function test_verify_signature_rejects_tampered_params(): void
    {
        $params = [
            'authentication' => ['entityId' => 'entity-123'],
            'amount' => '19.99',
            'currency' => 'ZAR',
            'customParameters' => ['order_uuid' => 'order-uuid-1'],
        ];

        $params['signature'] = $this->client->sign($params);

        $params['amount'] = '999.99';

        $this->assertFalse($this->client->verifySignature($params));
    }

    public function test_verify_signature_rejects_missing_signature(): void
    {
        $params = [
            'authentication' => ['entityId' => 'entity-123'],
            'amount' => '19.99',
            'currency' => 'ZAR',
        ];

        $this->assertFalse($this->client->verifySignature($params));
    }

    public function test_create_checkout_posts_to_sandbox_host_with_bearer_token_and_injects_entity_id_and_nonce(): void
    {
        Http::fake([
            'https://sandbox-dashboard.peachpayments.com/api/oauth/token' => Http::response([
                'access_token' => 'oauth-token-abc',
                'expires_in' => 300,
            ], 200),
            'https://testsecure.peachpayments.com/v2/checkout' => Http::response([
                'checkoutId' => 'chk-123',
                'redirectUrl' => 'https://testsecure.peachpayments.com/checkout/chk-123',
            ], 200),
        ]);

        $payload = [
            'merchantTransactionId' => 'o-000001',
            'amount' => '10.00',
            'currency' => 'USD',
            'shopperResultUrl' => 'https://saasykit.test/result',
            'notificationUrl' => 'https://saasykit.test/webhook',
            'cancelUrl' => 'https://saasykit.test/cancel',
            'customParameters' => [
                'order_uuid' => 'order-uuid-1',
            ],
        ];

        $response = $this->client->createCheckout($payload);

        $this->assertEquals('chk-123', $response['checkoutId']);
        $this->assertEquals('https://testsecure.peachpayments.com/checkout/chk-123', $response['redirectUrl']);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://sandbox-dashboard.peachpayments.com/api/oauth/token'
                && $request['clientId'] === 'client-123'
                && $request['clientSecret'] === 'client-secret-123'
                && $request['merchantId'] === 'merchant-123';
        });

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://testsecure.peachpayments.com/v2/checkout') {
                return false;
            }

            $this->assertEquals(['Bearer oauth-token-abc'], $request->header('Authorization'));
            $this->assertEquals(['https://saasykit.test'], $request->header('Referer'));
            $this->assertEquals('entity-123', $request['authentication']['entityId']);
            $this->assertNotEmpty($request['nonce']);
            $this->assertEquals('o-000001', $request['merchantTransactionId']);

            return true;
        });
    }

    public function test_charge_registration_sends_standing_instruction_params_and_returns_declined_body_without_throwing(): void
    {
        Http::fake([
            'https://sandbox-card.peachpayments.com/v1/registrations/*' => Http::response([
                'id' => 'pay-declined-1',
                'result' => [
                    'code' => '800.100.155',
                    'description' => 'Transaction declined (invalid card)',
                ],
            ], 400),
        ]);

        $response = $this->client->chargeRegistration('reg-123', 19.99, 'USD');

        $this->assertEquals('800.100.155', $response['result']['code']);
        $this->assertEquals('pay-declined-1', $response['id']);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/v1/registrations/reg-123/payments')) {
                return false;
            }

            $this->assertEquals('recurring-entity-123', $request['entityId']);
            $this->assertEquals('19.99', $request['amount']);
            $this->assertEquals('USD', $request['currency']);
            $this->assertEquals('DB', $request['paymentType']);
            $this->assertEquals('REPEATED', $request['standingInstruction.mode']);
            $this->assertEquals('RECURRING', $request['standingInstruction.type']);
            $this->assertEquals('MIT', $request['standingInstruction.source']);
            $this->assertEquals('SUBSCRIPTION', $request['standingInstruction.recurringType']);
            $this->assertEquals(['Bearer recurring-access-token-123'], $request->header('Authorization'));

            return true;
        });
    }
}
