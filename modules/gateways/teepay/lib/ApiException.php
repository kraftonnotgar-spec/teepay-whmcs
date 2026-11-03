<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * A TeePay API error, parsed from the {"error":{"code","message","request_id"}} envelope.
 */
class ApiException extends \RuntimeException
{
    /** @var int HTTP status, or 0 for a network failure. */
    private $status;

    /** @var string */
    private $errorCode;

    /** @var string */
    private $requestId;

    /** @var int|null Seconds from the Retry-After header. */
    private $retryAfter;

    /**
     * @param int         $status
     * @param string      $errorCode
     * @param string      $message
     * @param string      $requestId
     * @param int|null    $retryAfter
     */
    public function __construct($status, $errorCode, $message, $requestId = '', $retryAfter = null)
    {
        parent::__construct((string) $message, (int) $status);
        $this->status = (int) $status;
        $this->errorCode = (string) $errorCode;
        $this->requestId = (string) $requestId;
        $this->retryAfter = $retryAfter === null ? null : (int) $retryAfter;
    }

    /**
     * Build an exception from a raw HTTP response.
     *
     * @param int    $status
     * @param string $body
     * @param array  $headers Lower-cased header names.
     * @return self
     */
    public static function fromResponse($status, $body, array $headers = array())
    {
        $code = 'http_' . (int) $status;
        $message = 'TeePay API request failed with HTTP ' . (int) $status . '.';
        $requestId = isset($headers['x-request-id']) ? (string) $headers['x-request-id'] : '';
        $decoded = json_decode((string) $body, true);
        if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
            $error = $decoded['error'];
            if (isset($error['code']) && is_string($error['code']) && $error['code'] !== '') {
                $code = $error['code'];
            }
            if (isset($error['message']) && is_string($error['message']) && $error['message'] !== '') {
                $message = $error['message'];
            }
            if (isset($error['request_id']) && is_string($error['request_id']) && $error['request_id'] !== '') {
                $requestId = $error['request_id'];
            }
        }
        $retryAfter = null;
        if (isset($headers['retry-after']) && ctype_digit((string) $headers['retry-after'])) {
            $retryAfter = (int) $headers['retry-after'];
        }

        return new self($status, $code, $message, $requestId, $retryAfter);
    }

    /** @return int */
    public function getStatus()
    {
        return $this->status;
    }

    /** @return string */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /** @return string */
    public function getRequestId()
    {
        return $this->requestId;
    }

    /** @return int|null */
    public function getRetryAfter()
    {
        return $this->retryAfter;
    }

    /**
     * True when TeePay has restricted the merchant account (403 account_restricted).
     *
     * @return bool
     */
    public function isAccountRestricted()
    {
        return $this->status === 403 && $this->errorCode === 'account_restricted';
    }

    /**
     * True when the key itself is wrong (missing, revoked or for the other environment).
     *
     * @return bool
     */
    public function isCredentialError()
    {
        return $this->status === 401;
    }

    /**
     * True for failures where the same request may succeed later.
     *
     * @return bool
     */
    public function isRetryable()
    {
        return $this->status === 0 || $this->status === 429 || $this->status >= 500;
    }

    /**
     * Message for the store owner (never shown to clients).
     *
     * @return string
     */
    public function adminMessage()
    {
        $suffix = $this->requestId !== '' ? ' (TeePay request ID ' . $this->requestId . ')' : '';
        if ($this->isAccountRestricted()) {
            return 'Your TeePay account is restricted, so TeePay is not accepting new payments for it right now. '
                . 'Sign in to the TeePay dashboard to see why, or contact TeePay support' . $suffix . '.';
        }
        if ($this->isCredentialError()) {
            return 'TeePay rejected the API key. Check that the key is correct, active and matches the selected mode '
                . '(tp_test_ for test mode, tp_live_ for live)' . $suffix . '.';
        }
        if ($this->status === 403) {
            return 'This TeePay API key or account cannot use checkout sessions (' . $this->errorCode . '). '
                . 'Check the key scopes (collection:create, collection:read) and your account status' . $suffix . '.';
        }

        return 'TeePay API error ' . $this->errorCode . ': ' . $this->getMessage() . $suffix;
    }
}
