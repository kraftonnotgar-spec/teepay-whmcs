<?php

namespace TeePay\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use TeePay\Whmcs\ApiClient;
use TeePay\Whmcs\CallbackHandler;
use TeePay\Whmcs\Config;
use TeePay\Whmcs\Tests\Fakes\FakeBridge;
use TeePay\Whmcs\Tests\Fakes\FakeTransport;
use TeePay\Whmcs\Tests\Fakes\Fixtures;
use TeePay\Whmcs\WebhookVerifier;

class CallbackHandlerTest extends TestCase
{
    const NOW = 1798000000;

    /** @var FakeTransport */
    private $transport;

    /** @var FakeBridge */
    private $bridge;

    /** @var Config */
    private $config;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->bridge = new FakeBridge();
        $this->bridge->addInvoice(42, '5000.00');
        $this->config = Fixtures::config();
    }

    private function handler(?Config $config = null)
    {
        $config = $config ?: $this->config;

        return new CallbackHandler($config, new ApiClient($config->apiKey, $this->transport), $this->bridge);
    }

    private function signedHeaders($body, $timestamp = self::NOW, $secret = Fixtures::SECRET)
    {
        return array(
            'X-TeePay-Event-ID' => '0f8d7c2e-5b1a-5e3f-9c4d-7a6b2e1f0c9d',
            'X-TeePay-Timestamp' => (string) $timestamp,
            'X-TeePay-Signature' => WebhookVerifier::sign($secret, $timestamp, $body),
        );
    }

    // --- Pay ---------------------------------------------------------------------------------

    public function testPayCreatesSessionFromInvoiceBalanceAndRedirects()
    {
        $this->transport->queue(201, Fixtures::session(array('status' => 'open', 'payment' => null)));
        $session = array();

        $response = $this->handler()->handlePay(array('invoiceid' => '42', 'token' => $this->config->payToken(42), 'amount' => '1'), $session, self::NOW);

        $this->assertSame(303, $response->status);
        $this->assertSame('https://checkout.teepay.me/checkout/abc', $response->headers['Location']);
        $this->assertSame(42, $session[CallbackHandler::SESSION_INVOICE]);
        $this->assertSame(Fixtures::SESSION_ID, $session[CallbackHandler::SESSION_CHECKOUT]);

        $request = $this->transport->requests[0];
        $tag = $this->config->siteTag();
        $this->assertContains('Idempotency-Key: whmcs-' . $tag . '-42-500000-TZS-' . (int) floor(self::NOW / 43200), $request['headers']);
        $this->assertSame(array(
            'title' => 'Invoice #42',
            'description' => 'Payment to Example Hosting Ltd',
            'amount_minor' => '500000',
            'currency' => 'TZS',
            'expires_in' => 86400,
            'reference' => 'whmcs-' . $tag . '-42',
            'metadata' => array('invoice_id' => '42', 'source' => 'whmcs'),
        ), json_decode($request['body'], true));
        $this->assertSame(array(), $this->bridge->payments, 'Creating a session never records a payment');
    }

    public function testPayIncludesConfiguredPaymentProfile()
    {
        $config = Fixtures::config();
        $config->paymentProfileId = Fixtures::PROFILE_ID;
        $this->transport->queue(201, Fixtures::session(array('status' => 'open', 'payment' => null)));
        $session = array();
        $this->handler($config)->handlePay(array('invoiceid' => '42', 'token' => $config->payToken(42)), $session, self::NOW);
        $this->assertSame(Fixtures::PROFILE_ID, json_decode($this->transport->requests[0]['body'], true)['payment_profile_id']);
    }

    public function testPayWithInvalidTokenMakesNoApiCall()
    {
        $session = array();
        $response = $this->handler()->handlePay(array('invoiceid' => '42', 'token' => 'forged'), $session, self::NOW);
        $this->assertSame(403, $response->status);
        $this->assertCount(0, $this->transport->requests);
        $this->assertSame(array(), $session);
    }

    public function testPayForPaidInvoiceRedirectsWithoutApiCall()
    {
        $this->bridge->invoices[42]['status'] = 'Paid';
        $session = array();
        $response = $this->handler()->handlePay(array('invoiceid' => '42', 'token' => $this->config->payToken(42)), $session, self::NOW);
        $this->assertSame(303, $response->status);
        $this->assertSame('https://billing.example.co.tz/viewinvoice.php?id=42', $response->headers['Location']);
        $this->assertCount(0, $this->transport->requests);
    }

    public function testRestrictedAccountShowsGenericMessageAndLogsAdminNotice()
    {
        $this->transport->queue(403, array('error' => array('code' => 'account_restricted', 'message' => 'Restricted.', 'request_id' => 'req_9')));
        $session = array();

        $response = $this->handler()->handlePay(array('invoiceid' => '42', 'token' => $this->config->payToken(42)), $session, self::NOW);

        $this->assertSame(502, $response->status);
        $this->assertStringContainsString('Mobile money payments are unavailable right now', $response->body);
        $this->assertStringNotContainsString('restricted', strtolower($response->body), 'Clients never see the restriction');
        $this->assertSame(array('Account Restricted'), $this->bridge->logStatuses());
        $this->assertStringContainsString('Your TeePay account is restricted', $this->bridge->logs[0]['data']['admin_notice']);
        $this->assertSame('req_9', $this->bridge->logs[0]['data']['request_id']);
        $this->assertArrayNotHasKey(CallbackHandler::SESSION_CHECKOUT, $session);
    }

    public function testTransientErrorAsksClientToRetry()
    {
        $this->transport->queue(503, array('error' => array('code' => 'unavailable', 'message' => 'x', 'request_id' => 'r')));
        $session = array();
        $response = $this->handler()->handlePay(array('invoiceid' => '42', 'token' => $this->config->payToken(42)), $session, self::NOW);
        $this->assertSame(503, $response->status);
        $this->assertStringContainsString('try again', $response->body);
        $this->assertSame(array('Error'), $this->bridge->logStatuses());
    }

    public function testPayWithoutApiKeyIsUnavailable()
    {
        $session = array();
        $handler = new CallbackHandler($this->config, null, $this->bridge);
        $response = $handler->handlePay(array('invoiceid' => '42', 'token' => $this->config->payToken(42)), $session, self::NOW);
        $this->assertSame(503, $response->status);
        $this->assertSame(array('Configuration Error'), $this->bridge->logStatuses());
    }

    // --- Return ------------------------------------------------------------------------------

    public function testReturnConfirmsWithAuthenticatedReadAndRecordsExactAmount()
    {
        $this->transport->queue(200, Fixtures::session());
        $session = array(CallbackHandler::SESSION_INVOICE => 42, CallbackHandler::SESSION_CHECKOUT => Fixtures::SESSION_ID);

        $response = $this->handler()->handleReturn($session);

        $this->assertSame('GET', $this->transport->requests[0]['method']);
        $this->assertSame(array(array('invoice' => 42, 'transid' => 'TP8K2M4QZ1', 'amount' => '5000.00')), $this->bridge->payments);
        $this->assertSame('https://billing.example.co.tz/viewinvoice.php?id=42&paymentsuccess=true', $response->headers['Location']);
        $this->assertSame(array('Success'), $this->bridge->logStatuses());
        $this->assertSame(array(), $session);
    }

    public function testReturnQueryStringIsNeverTrusted()
    {
        $_GET = array('action' => 'return', 'status' => 'paid', 'invoiceid' => '42', 'session_id' => Fixtures::SESSION_ID);
        $_REQUEST = $_GET;
        $session = array();

        $response = $this->handler()->handleReturn($session);

        $this->assertSame('https://billing.example.co.tz/clientarea.php?action=invoices', $response->headers['Location']);
        $this->assertCount(0, $this->transport->requests);
        $this->assertSame(array(), $this->bridge->payments);
        $_GET = $_REQUEST = array();
    }

    public function testReturnWhileStillProcessingRecordsNothing()
    {
        $this->transport->queue(200, Fixtures::session(array('status' => 'processing', 'payment' => null)));
        $session = array(CallbackHandler::SESSION_INVOICE => 42, CallbackHandler::SESSION_CHECKOUT => Fixtures::SESSION_ID);
        $response = $this->handler()->handleReturn($session);
        $this->assertSame('https://billing.example.co.tz/viewinvoice.php?id=42', $response->headers['Location']);
        $this->assertSame(array(), $this->bridge->payments);
        $this->assertArrayHasKey(CallbackHandler::SESSION_CHECKOUT, $session);
    }

    public function testReturnAfterExpiryShowsFailure()
    {
        $this->transport->queue(200, Fixtures::session(array('status' => 'expired', 'payment' => null)));
        $session = array(CallbackHandler::SESSION_INVOICE => 42, CallbackHandler::SESSION_CHECKOUT => Fixtures::SESSION_ID);
        $response = $this->handler()->handleReturn($session);
        $this->assertStringEndsWith('&paymentfailed=true', $response->headers['Location']);
        $this->assertSame(array(), $this->bridge->payments);
    }

    public function testReturnWhenApiIsDownAssumesNothing()
    {
        $this->transport->queueNetworkError();
        $session = array(CallbackHandler::SESSION_INVOICE => 42, CallbackHandler::SESSION_CHECKOUT => Fixtures::SESSION_ID);
        $response = $this->handler()->handleReturn($session);
        $this->assertSame('https://billing.example.co.tz/viewinvoice.php?id=42', $response->headers['Location']);
        $this->assertSame(array(), $this->bridge->payments);
    }

    // --- Webhook -----------------------------------------------------------------------------

    public function testVerifiedWebhookRecordsPaymentAfterStatusRead()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(200, Fixtures::session());

        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);

        $this->assertSame(200, $response->status);
        $this->assertSame('applied', json_decode($response->body, true)['result']);
        $this->assertSame('https://api.teepay.me/v1/checkout-sessions/' . Fixtures::SESSION_ID, $this->transport->requests[0]['url']);
        $this->assertSame(array(array('invoice' => 42, 'transid' => 'TP8K2M4QZ1', 'amount' => '5000.00')), $this->bridge->payments);
        $this->assertSame(array('teepay_invoice_42'), $this->bridge->locks);
    }

    public function testHeaderNamesAreCaseInsensitive()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(200, Fixtures::session());
        $headers = array_change_key_case($this->signedHeaders($body), CASE_LOWER);
        $this->assertSame(200, $this->handler()->handleWebhook($body, $headers, self::NOW)->status);
        $this->assertCount(1, $this->bridge->payments);
    }

    public function testRedeliveredWebhookIsNotAppliedTwice()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(200, Fixtures::session())->queue(200, Fixtures::session());
        $handler = $this->handler();

        $handler->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $second = $handler->handleWebhook($body, $this->signedHeaders($body, self::NOW + 60), self::NOW + 60);

        $this->assertSame(200, $second->status);
        $this->assertSame('duplicate', json_decode($second->body, true)['result']);
        $this->assertCount(1, $this->bridge->payments);
    }

    public function testWebhookThenReturnRecordsOnce()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(200, Fixtures::session())->queue(200, Fixtures::session());
        $handler = $this->handler();
        $handler->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $session = array(CallbackHandler::SESSION_INVOICE => 42, CallbackHandler::SESSION_CHECKOUT => Fixtures::SESSION_ID);
        $response = $handler->handleReturn($session);
        $this->assertStringEndsWith('&paymentsuccess=true', $response->headers['Location']);
        $this->assertCount(1, $this->bridge->payments);
    }

    public function testInvalidSignatureIsRejectedWithoutApiCall()
    {
        $body = Fixtures::webhookBody();
        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body, self::NOW, 'whsec_attacker'), self::NOW);
        $this->assertSame(401, $response->status);
        $this->assertCount(0, $this->transport->requests);
        $this->assertSame(array(), $this->bridge->payments);
        $this->assertSame(array('Invalid Signature'), $this->bridge->logStatuses());
    }

    public function testReplayedOldWebhookIsRejected()
    {
        $body = Fixtures::webhookBody();
        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body, self::NOW - 3600), self::NOW);
        $this->assertSame(401, $response->status);
        $this->assertCount(0, $this->transport->requests);
    }

    public function testUnsignedForgedSuccessIsRejected()
    {
        $body = Fixtures::webhookBody();
        $response = $this->handler()->handleWebhook($body, array('Content-Type' => 'application/json'), self::NOW);
        $this->assertSame(401, $response->status);
        $this->assertSame(array(), $this->bridge->payments);
    }

    public function testAmountMismatchIsRejectedAndLogged()
    {
        $body = Fixtures::webhookBody();
        $this->bridge->invoices[42]['balance'] = '6000.00';
        $this->transport->queue(200, Fixtures::session());

        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);

        $this->assertSame(200, $response->status);
        $this->assertSame('rejected', json_decode($response->body, true)['result']);
        $this->assertSame(array(), $this->bridge->payments);
        $this->assertSame(array('Amount Mismatch'), $this->bridge->logStatuses());
        $this->assertSame('600000', $this->bridge->logs[0]['data']['expected_amount_minor']);
    }

    public function testCurrencyMismatchIsRejected()
    {
        $body = Fixtures::webhookBody();
        $this->bridge->invoices[42]['currency'] = 'USD';
        $this->transport->queue(200, Fixtures::session());
        $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $this->assertSame(array(), $this->bridge->payments);
        $this->assertSame(array('Amount Mismatch'), $this->bridge->logStatuses());
    }

    public function testWebhookPaidButSessionReadSaysProcessingRecordsNothing()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(200, Fixtures::session(array('status' => 'processing', 'payment' => null)));
        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $this->assertSame('pending', json_decode($response->body, true)['result']);
        $this->assertSame(array(), $this->bridge->payments);
    }

    public function testFailedExpiredAndVoidedEventsChangeNothing()
    {
        foreach (array('payment.failed', 'payment.expired', 'payment.voided', 'payout.completed') as $event) {
            $body = Fixtures::webhookBody($event);
            $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);
            $this->assertSame(200, $response->status, $event);
        }
        $this->assertCount(0, $this->transport->requests);
        $this->assertSame(array(), $this->bridge->payments);
        $this->assertSame('Unpaid', $this->bridge->invoices[42]['status']);
    }

    public function testNonCheckoutAndOtherEnvironmentEventsAreIgnored()
    {
        $push = Fixtures::webhookBody('payment.completed', false);
        $sandbox = Fixtures::webhookBody('payment.completed', null, 'sandbox');
        foreach (array($push, $sandbox) as $body) {
            $this->assertSame(200, $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW)->status);
        }
        $this->assertCount(0, $this->transport->requests);
        $this->assertSame(array(), $this->bridge->payments);
    }

    public function testSessionFromAnotherInstallationIsIgnored()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(200, Fixtures::session(array('reference' => 'whmcs-00000000-42')));
        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $this->assertSame('ignored', json_decode($response->body, true)['result']);
        $this->assertSame(array(), $this->bridge->payments);
    }

    public function testTransientStatusReadFailureAsksTeePayToRetry()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(503, array('error' => array('code' => 'unavailable', 'message' => 'x', 'request_id' => 'r')), array('Retry-After' => '5'));
        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $this->assertSame(503, $response->status);
        $this->assertSame(array(), $this->bridge->payments);
    }

    public function testRestrictedAccountDuringWebhookLogsAdminNotice()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(403, array('error' => array('code' => 'account_restricted', 'message' => 'Restricted.', 'request_id' => 'req_7')));
        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $this->assertSame(500, $response->status);
        $this->assertSame(array('Account Restricted'), $this->bridge->logStatuses());
        $this->assertSame(array(), $this->bridge->payments);
    }

    public function testPaidSessionForAlreadyPaidInvoiceIsFlaggedNotApplied()
    {
        $body = Fixtures::webhookBody();
        $this->bridge->invoices[42]['status'] = 'Paid';
        $this->bridge->invoices[42]['balance'] = '0.00';
        $this->transport->queue(200, Fixtures::session());
        $response = $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $this->assertSame('invoice_not_payable', json_decode($response->body, true)['result']);
        $this->assertSame(array('Rejected'), $this->bridge->logStatuses());
        $this->assertSame('invoice_not_payable', $this->bridge->logs[0]['data']['reason']);
    }

    public function testMissingWebhookSecretFailsClosed()
    {
        $config = Fixtures::config();
        $config->webhookSecret = '';
        $body = Fixtures::webhookBody();
        $response = $this->handler($config)->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $this->assertSame(500, $response->status);
        $this->assertSame(array(), $this->bridge->payments);
    }

    public function testLogsNeverContainCredentials()
    {
        $body = Fixtures::webhookBody();
        $this->transport->queue(200, Fixtures::session());
        $this->handler()->handleWebhook($body, $this->signedHeaders($body), self::NOW);
        $logged = json_encode($this->bridge->logs);
        $this->assertStringNotContainsString('notarealsecret', $logged);
        $this->assertStringNotContainsString(Fixtures::SECRET, $logged);
    }

    public function testConfigReferencesAreSiteScoped()
    {
        $other = Config::fromParams(array('systemurl' => 'https://other.example/', 'liveApiKey' => Fixtures::LIVE_KEY));
        $this->assertNotSame($this->config->siteTag(), $other->siteTag());
        $this->assertSame(42, $this->config->invoiceIdFromReference($this->config->referenceFor(42)));
        $this->assertSame(0, $other->invoiceIdFromReference($this->config->referenceFor(42)));
        $this->assertSame(0, $this->config->invoiceIdFromReference('whmcs-' . $this->config->siteTag() . '-0'));
        $this->assertSame('sandbox', Fixtures::config(true)->environment());
        $this->assertSame(Fixtures::TEST_KEY, Fixtures::config(true)->apiKey);
    }
}
