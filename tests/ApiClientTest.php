<?php

namespace TeePay\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use TeePay\Whmcs\ApiClient;
use TeePay\Whmcs\ApiException;
use TeePay\Whmcs\Tests\Fakes\FakeTransport;
use TeePay\Whmcs\Tests\Fakes\Fixtures;

class ApiClientTest extends TestCase
{
    public function testCreateCheckoutSessionSendsExactRequestShape()
    {
        $transport = (new FakeTransport())->queue(201, Fixtures::session(array('status' => 'open', 'payment' => null)));
        $client = new ApiClient(Fixtures::LIVE_KEY, $transport);

        $session = $client->createCheckoutSession(array(
            'title' => 'Invoice #42',
            'description' => 'Payment to Example Hosting Ltd',
            'amount_minor' => '500000',
            'currency' => 'TZS',
            'expires_in' => 86400,
            'payment_profile_id' => Fixtures::PROFILE_ID,
            'reference' => 'whmcs-abcd1234-42',
            'metadata' => array('invoice_id' => '42', 'source' => 'whmcs'),
        ), 'whmcs-abcd1234-42-500000-TZS-41000');

        $this->assertSame('open', $session['status']);
        $this->assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.teepay.me/v1/checkout-sessions', $request['url']);
        $this->assertSame(array(
            'Authorization: Bearer ' . Fixtures::LIVE_KEY,
            'Accept: application/json',
            'TeePay-Version: 2026-10-18',
            'User-Agent: teepay-whmcs/1.0.0',
            'Content-Type: application/json',
            'Idempotency-Key: whmcs-abcd1234-42-500000-TZS-41000',
        ), $request['headers']);
        $this->assertSame(
            '{"title":"Invoice #42","description":"Payment to Example Hosting Ltd","amount_minor":"500000","currency":"TZS",'
            . '"expires_in":86400,"payment_profile_id":"' . Fixtures::PROFILE_ID . '","reference":"whmcs-abcd1234-42",'
            . '"metadata":{"invoice_id":"42","source":"whmcs"}}',
            $request['body']
        );
        $decoded = json_decode($request['body'], true);
        $this->assertIsString($decoded['amount_minor'], 'amount_minor must be sent as a string');
    }

    public function testOptionalFieldsAreOmittedWhenEmpty()
    {
        $body = ApiClient::checkoutSessionBody(array('title' => 'T', 'amount_minor' => '100', 'currency' => 'TZS', 'description' => '', 'payment_profile_id' => '', 'metadata' => array()));
        $this->assertSame(array('title' => 'T', 'amount_minor' => '100', 'currency' => 'TZS'), $body);
    }

    /**
     * @dataProvider invalidBodies
     */
    public function testInvalidBodiesAreRejectedBeforeSending(array $input)
    {
        $transport = new FakeTransport();
        $client = new ApiClient(Fixtures::LIVE_KEY, $transport);
        try {
            $client->createCheckoutSession($input, 'valid-key-123');
            $this->fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertCount(0, $transport->requests);
        }
    }

    public function invalidBodies()
    {
        $base = array('title' => 'Invoice', 'amount_minor' => '500000', 'currency' => 'TZS');

        return array(
            'missing title' => array(array('amount_minor' => '1', 'currency' => 'TZS')),
            'long title' => array(array_merge($base, array('title' => str_repeat('a', 101)))),
            'decimal amount' => array(array_merge($base, array('amount_minor' => '5000.00'))),
            'zero amount' => array(array_merge($base, array('amount_minor' => '0'))),
            'integer amount' => array(array_merge($base, array('amount_minor' => 'abc'))),
            'lowercase currency' => array(array_merge($base, array('currency' => 'tzs'))),
            'expires too soon' => array(array_merge($base, array('expires_in' => 60))),
            'bad reference' => array(array_merge($base, array('reference' => 'has spaces'))),
            'bad profile' => array(array_merge($base, array('payment_profile_id' => 'not-a-uuid'))),
            'unknown field' => array(array_merge($base, array('return_url' => 'https://evil.example'))),
            'too much metadata' => array(array_merge($base, array('metadata' => array_fill_keys(range('a', 'k'), 'x')))),
        );
    }

    public function testInvalidIdempotencyKeyIsRejected()
    {
        $client = new ApiClient(Fixtures::LIVE_KEY, new FakeTransport());
        $this->expectException(\InvalidArgumentException::class);
        $client->createCheckoutSession(array('title' => 'T', 'amount_minor' => '1', 'currency' => 'TZS'), 'short');
    }

    public function testGetCheckoutSessionReadsByUuid()
    {
        $transport = (new FakeTransport())->queue(200, Fixtures::session());
        $client = new ApiClient(Fixtures::LIVE_KEY, $transport);

        $session = $client->getCheckoutSession(Fixtures::SESSION_ID);

        $this->assertSame('paid', $session['status']);
        $this->assertSame('GET', $transport->requests[0]['method']);
        $this->assertSame('https://api.teepay.me/v1/checkout-sessions/' . Fixtures::SESSION_ID, $transport->requests[0]['url']);
        $this->assertNull($transport->requests[0]['body']);
        $this->assertNotContains('Content-Type: application/json', $transport->requests[0]['headers']);
    }

    public function testGetCheckoutSessionRejectsPathInjection()
    {
        $transport = new FakeTransport();
        $client = new ApiClient(Fixtures::LIVE_KEY, $transport);
        $this->expectException(\InvalidArgumentException::class);
        try {
            $client->getCheckoutSession('../collections');
        } finally {
            $this->assertCount(0, $transport->requests);
        }
    }

    public function testAccountRestrictedErrorIsParsed()
    {
        $transport = (new FakeTransport())->queue(403, array('error' => array(
            'code' => 'account_restricted',
            'message' => 'This account is restricted.',
            'request_id' => 'req_123',
        )));
        $client = new ApiClient(Fixtures::LIVE_KEY, $transport);
        try {
            $client->createCheckoutSession(array('title' => 'T', 'amount_minor' => '1', 'currency' => 'TZS'), 'valid-key-123');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame(403, $e->getStatus());
            $this->assertSame('account_restricted', $e->getErrorCode());
            $this->assertSame('req_123', $e->getRequestId());
            $this->assertTrue($e->isAccountRestricted());
            $this->assertFalse($e->isRetryable());
            $this->assertStringContainsString('Your TeePay account is restricted', $e->adminMessage());
            $this->assertStringContainsString('req_123', $e->adminMessage());
        }
    }

    public function testOther403IsNotTreatedAsRestricted()
    {
        $e = ApiException::fromResponse(403, '{"error":{"code":"scope_denied","message":"x","request_id":"r"}}');
        $this->assertFalse($e->isAccountRestricted());
        $this->assertStringContainsString('scope', $e->adminMessage());
    }

    public function testServiceUnavailableIsRetryableWithRetryAfter()
    {
        $transport = (new FakeTransport())->queue(503, array('error' => array('code' => 'unavailable', 'message' => 'Try later', 'request_id' => 'r1')), array('Retry-After' => '5'));
        $client = new ApiClient(Fixtures::LIVE_KEY, $transport);
        try {
            $client->getCheckoutSession(Fixtures::SESSION_ID);
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertSame('unavailable', $e->getErrorCode());
            $this->assertSame(5, $e->getRetryAfter());
            $this->assertTrue($e->isRetryable());
        }
    }

    public function testNetworkErrorAndNonJsonBodies()
    {
        $transport = (new FakeTransport())->queueNetworkError()->queue(200, '<html>proxy</html>')->queue(502, 'Bad gateway');
        $client = new ApiClient(Fixtures::LIVE_KEY, $transport);
        foreach (array(array(0, 'network_error'), array(502, 'invalid_response'), array(502, 'http_502')) as $expected) {
            try {
                $client->getCapabilities();
                $this->fail('Expected ApiException');
            } catch (ApiException $e) {
                $this->assertSame($expected[0], $e->getStatus());
                $this->assertSame($expected[1], $e->getErrorCode());
                $this->assertTrue($e->isRetryable());
            }
        }
    }

    public function testKeyValidationAndEnvironment()
    {
        $this->assertSame('live', (new ApiClient(Fixtures::LIVE_KEY, new FakeTransport()))->environment());
        $this->assertSame('sandbox', (new ApiClient(Fixtures::TEST_KEY, new FakeTransport()))->environment());
        $this->assertFalse(ApiClient::isValidKey('sk_live_123456789'));
        $this->assertFalse(ApiClient::isValidKey(''));
        $this->expectException(\InvalidArgumentException::class);
        new ApiClient('whsec_abcdefghijkl', new FakeTransport());
    }
}
