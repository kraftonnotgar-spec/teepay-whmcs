<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * Verifies TeePay webhook signatures.
 *
 * X-TeePay-Signature is "v1=" followed by the lowercase hex HMAC-SHA256 of
 * "<X-TeePay-Timestamp>.<raw request body>" keyed with the webhook signing secret.
 * Requests whose timestamp is more than five minutes from now are rejected.
 */
class WebhookVerifier
{
    const TOLERANCE_SECONDS = 300;

    /** @var string */
    private $secret;

    /** @var int */
    private $tolerance;

    /** @var string Reason for the last failed verification. */
    private $lastError = '';

    /**
     * @param string $secret
     * @param int    $tolerance
     */
    public function __construct($secret, $tolerance = self::TOLERANCE_SECONDS)
    {
        $this->secret = (string) $secret;
        $this->tolerance = (int) $tolerance;
    }

    /**
     * Compute the signature header value for a body (used by tests and tooling).
     *
     * @param string     $secret
     * @param string|int $timestamp
     * @param string     $rawBody
     * @return string
     */
    public static function sign($secret, $timestamp, $rawBody)
    {
        return 'v1=' . hash_hmac('sha256', (string) $timestamp . '.' . (string) $rawBody, (string) $secret);
    }

    /**
     * @param string      $rawBody   Exact request body bytes.
     * @param string|null $timestamp X-TeePay-Timestamp.
     * @param string|null $signature X-TeePay-Signature.
     * @param int|null    $now       Current Unix time (injectable for tests).
     * @return bool
     */
    public function verify($rawBody, $timestamp, $signature, $now = null)
    {
        $this->lastError = '';
        if ($this->secret === '') {
            $this->lastError = 'webhook_secret_missing';

            return false;
        }
        if (!is_string($timestamp) || $timestamp === '' || !ctype_digit($timestamp) || strlen($timestamp) > 12) {
            $this->lastError = 'timestamp_missing';

            return false;
        }
        if (!is_string($signature) || $signature === '') {
            $this->lastError = 'signature_missing';

            return false;
        }
        $now = $now === null ? time() : (int) $now;
        if (abs($now - (int) $timestamp) > $this->tolerance) {
            $this->lastError = 'timestamp_out_of_tolerance';

            return false;
        }
        $expected = self::sign($this->secret, $timestamp, (string) $rawBody);
        foreach (explode(',', $signature) as $candidate) {
            if (hash_equals($expected, trim($candidate))) {
                return true;
            }
        }
        $this->lastError = 'signature_mismatch';

        return false;
    }

    /** @return string */
    public function lastError()
    {
        return $this->lastError;
    }
}
