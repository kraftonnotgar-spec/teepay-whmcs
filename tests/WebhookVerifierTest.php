<?php

namespace TeePay\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use TeePay\Whmcs\Tests\Fakes\Fixtures;
use TeePay\Whmcs\WebhookVerifier;

class WebhookVerifierTest extends TestCase
{
    const NOW = 1798000000;

    public function testSignatureMatchesTeePaySigningScheme()
    {
        // "v1=" + hex HMAC-SHA256 of "<timestamp>.<raw body>", as merchant_webhooks.signature() produces.
        $expected = 'v1=' . hash_hmac('sha256', '1798000000.{"a":1}', 'whsec_x');
        $this->assertSame($expected, WebhookVerifier::sign('whsec_x', 1798000000, '{"a":1}'));
    }

    public function testValidSignatureIsAccepted()
    {
        $body = Fixtures::webhookBody();
        $verifier = new WebhookVerifier(Fixtures::SECRET);
        $this->assertTrue($verifier->verify($body, (string) self::NOW, WebhookVerifier::sign(Fixtures::SECRET, self::NOW, $body), self::NOW + 10));
        $this->assertSame('', $verifier->lastError());
    }

    public function testTamperedBodyIsRejected()
    {
        $body = Fixtures::webhookBody();
        $signature = WebhookVerifier::sign(Fixtures::SECRET, self::NOW, $body);
        $tampered = str_replace('500000', '5000000', $body);
        $verifier = new WebhookVerifier(Fixtures::SECRET);
        $this->assertFalse($verifier->verify($tampered, (string) self::NOW, $signature, self::NOW));
        $this->assertSame('signature_mismatch', $verifier->lastError());
    }

    public function testWrongSecretIsRejected()
    {
        $body = Fixtures::webhookBody();
        $verifier = new WebhookVerifier(Fixtures::SECRET);
        $this->assertFalse($verifier->verify($body, (string) self::NOW, WebhookVerifier::sign('whsec_other', self::NOW, $body), self::NOW));
    }

    public function testTimestampIsPartOfTheSignature()
    {
        $body = Fixtures::webhookBody();
        $signature = WebhookVerifier::sign(Fixtures::SECRET, self::NOW, $body);
        $verifier = new WebhookVerifier(Fixtures::SECRET);
        $this->assertFalse($verifier->verify($body, (string) (self::NOW + 1), $signature, self::NOW));
    }

    public function testStaleReplayIsRejected()
    {
        $body = Fixtures::webhookBody();
        $old = self::NOW - 301;
        $verifier = new WebhookVerifier(Fixtures::SECRET);
        $this->assertFalse($verifier->verify($body, (string) $old, WebhookVerifier::sign(Fixtures::SECRET, $old, $body), self::NOW));
        $this->assertSame('timestamp_out_of_tolerance', $verifier->lastError());
        $this->assertTrue($verifier->verify($body, (string) (self::NOW - 300), WebhookVerifier::sign(Fixtures::SECRET, self::NOW - 300, $body), self::NOW));
    }

    public function testFutureTimestampIsRejected()
    {
        $body = Fixtures::webhookBody();
        $future = self::NOW + 400;
        $verifier = new WebhookVerifier(Fixtures::SECRET);
        $this->assertFalse($verifier->verify($body, (string) $future, WebhookVerifier::sign(Fixtures::SECRET, $future, $body), self::NOW));
    }

    /**
     * @dataProvider malformedHeaders
     */
    public function testMissingOrMalformedHeadersAreRejected($timestamp, $signature, $reason)
    {
        $verifier = new WebhookVerifier(Fixtures::SECRET);
        $this->assertFalse($verifier->verify('{}', $timestamp, $signature, self::NOW));
        $this->assertSame($reason, $verifier->lastError());
    }

    public function malformedHeaders()
    {
        return array(
            'no timestamp' => array(null, 'v1=abc', 'timestamp_missing'),
            'non numeric timestamp' => array('12abc', 'v1=abc', 'timestamp_missing'),
            'negative timestamp' => array('-5', 'v1=abc', 'timestamp_missing'),
            'no signature' => array((string) self::NOW, null, 'signature_missing'),
            'empty signature' => array((string) self::NOW, '', 'signature_missing'),
            'unversioned hex' => array((string) self::NOW, hash_hmac('sha256', self::NOW . '.{}', Fixtures::SECRET), 'signature_mismatch'),
        );
    }

    public function testMissingSecretRejectsEverything()
    {
        $verifier = new WebhookVerifier('');
        $this->assertFalse($verifier->verify('{}', (string) self::NOW, WebhookVerifier::sign('', self::NOW, '{}'), self::NOW));
        $this->assertSame('webhook_secret_missing', $verifier->lastError());
    }
}
