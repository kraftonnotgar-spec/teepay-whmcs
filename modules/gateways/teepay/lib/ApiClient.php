<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * Small, dependency-free client for the public TeePay API (https://api.teepay.me).
 *
 * The transport is injectable for tests. It is a callable with the signature
 * function (string $method, string $url, array $headers, ?string $body): array
 * returning array('status' => int, 'headers' => array<lowercase name, string>, 'body' => string)
 * and throwing ApiException(0, 'network_error', ...) when the request could not be sent.
 */
class ApiClient
{
    const VERSION = '1.0.0';
    const API_VERSION = '2026-10-18';
    const DEFAULT_BASE_URL = 'https://api.teepay.me';

    const UUID_PATTERN = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var callable */
    private $transport;

    /**
     * @param string        $apiKey    tp_test_... or tp_live_...
     * @param callable|null $transport
     * @param string        $baseUrl
     */
    public function __construct($apiKey, $transport = null, $baseUrl = self::DEFAULT_BASE_URL)
    {
        $apiKey = trim((string) $apiKey);
        if (!self::isValidKey($apiKey)) {
            throw new \InvalidArgumentException('A TeePay API key starting with tp_test_ or tp_live_ is required.');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim((string) $baseUrl, '/');
        $this->transport = $transport !== null ? $transport : array(__CLASS__, 'curlTransport');
    }

    /**
     * @param string $key
     * @return bool
     */
    public static function isValidKey($key)
    {
        return is_string($key) && (bool) preg_match('/^tp_(test|live)_[A-Za-z0-9._-]{8,}$/', $key);
    }

    /**
     * The environment the key belongs to: "sandbox" for tp_test_, "live" for tp_live_.
     *
     * @return string
     */
    public function environment()
    {
        return strpos($this->apiKey, 'tp_live_') === 0 ? 'live' : 'sandbox';
    }

    /**
     * POST /v1/checkout-sessions.
     *
     * @param array  $input          title, amount_minor, currency (required); description, expires_in,
     *                               payment_profile_id, reference, metadata (optional).
     * @param string $idempotencyKey 8-120 characters of [A-Za-z0-9_-].
     * @return array The checkout session.
     */
    public function createCheckoutSession(array $input, $idempotencyKey)
    {
        if (!is_string($idempotencyKey) || !preg_match('/^[A-Za-z0-9_-]{8,120}$/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Idempotency-Key must be 8-120 letters, digits, "_" or "-".');
        }
        $body = self::checkoutSessionBody($input);

        return $this->request('POST', '/v1/checkout-sessions', $body, array('Idempotency-Key: ' . $idempotencyKey));
    }

    /**
     * GET /v1/checkout-sessions/{id}.
     *
     * @param string $id
     * @return array
     */
    public function getCheckoutSession($id)
    {
        if (!is_string($id) || !preg_match(self::UUID_PATTERN, $id)) {
            throw new \InvalidArgumentException('Checkout session ID must be a UUID.');
        }

        return $this->request('GET', '/v1/checkout-sessions/' . rawurlencode($id));
    }

    /**
     * GET /v1/capabilities.
     *
     * @return array
     */
    public function getCapabilities()
    {
        return $this->request('GET', '/v1/capabilities');
    }

    /**
     * Validate and normalise a checkout session body (the API rejects unknown fields).
     *
     * @param array $input
     * @return array
     */
    public static function checkoutSessionBody(array $input)
    {
        $allowed = array('title', 'description', 'amount_minor', 'currency', 'expires_in', 'payment_profile_id', 'reference', 'metadata');
        foreach (array_keys($input) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new \InvalidArgumentException('Unknown checkout session field: ' . $field);
            }
        }
        $title = isset($input['title']) ? (string) $input['title'] : '';
        if ($title === '' || self::length($title) > 100) {
            throw new \InvalidArgumentException('title must be 1-100 characters.');
        }
        $amount = isset($input['amount_minor']) ? (string) $input['amount_minor'] : '';
        if (!preg_match('/^[1-9][0-9]{0,12}$/', $amount)) {
            throw new \InvalidArgumentException('amount_minor must be a positive integer string of up to 13 digits.');
        }
        $currency = isset($input['currency']) ? (string) $input['currency'] : '';
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('currency must be an ISO 4217 code such as TZS.');
        }
        $body = array('title' => $title);
        if (isset($input['description']) && (string) $input['description'] !== '') {
            $description = (string) $input['description'];
            if (self::length($description) > 200) {
                throw new \InvalidArgumentException('description must be at most 200 characters.');
            }
            $body['description'] = $description;
        }
        $body['amount_minor'] = $amount;
        $body['currency'] = $currency;
        if (isset($input['expires_in'])) {
            $expires = $input['expires_in'];
            if (!is_int($expires) || $expires < 3600 || $expires > 604800) {
                throw new \InvalidArgumentException('expires_in must be an integer from 3600 to 604800.');
            }
            $body['expires_in'] = $expires;
        }
        if (isset($input['payment_profile_id']) && (string) $input['payment_profile_id'] !== '') {
            if (!preg_match(self::UUID_PATTERN, (string) $input['payment_profile_id'])) {
                throw new \InvalidArgumentException('payment_profile_id must be a UUID.');
            }
            $body['payment_profile_id'] = (string) $input['payment_profile_id'];
        }
        if (isset($input['reference'])) {
            if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', (string) $input['reference'])) {
                throw new \InvalidArgumentException('reference must be 1-64 letters, digits or _ . : -');
            }
            $body['reference'] = (string) $input['reference'];
        }
        if (isset($input['metadata']) && $input['metadata'] !== array()) {
            if (!is_array($input['metadata']) || count($input['metadata']) > 10) {
                throw new \InvalidArgumentException('metadata must have at most 10 entries.');
            }
            $metadata = array();
            foreach ($input['metadata'] as $key => $value) {
                if (!is_string($key) || !preg_match('/^[A-Za-z0-9_.-]{1,40}$/', $key)) {
                    throw new \InvalidArgumentException('metadata keys must be 1-40 letters, digits or _ . -');
                }
                if (!is_scalar($value) || self::length((string) $value) > 200) {
                    throw new \InvalidArgumentException('metadata values must be strings of at most 200 characters.');
                }
                $metadata[$key] = (string) $value;
            }
            $body['metadata'] = $metadata;
        }

        return $body;
    }

    /**
     * @param string     $method
     * @param string     $path
     * @param array|null $body
     * @param string[]   $extraHeaders
     * @return array
     */
    private function request($method, $path, $body = null, array $extraHeaders = array())
    {
        $headers = array(
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'TeePay-Version: ' . self::API_VERSION,
            'User-Agent: teepay-whmcs/' . self::VERSION,
        );
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($payload === false) {
                throw new \InvalidArgumentException('Request body could not be encoded as JSON.');
            }
        }
        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        $response = call_user_func($this->transport, $method, $this->baseUrl . $path, $headers, $payload);
        $status = isset($response['status']) ? (int) $response['status'] : 0;
        $responseHeaders = isset($response['headers']) && is_array($response['headers']) ? array_change_key_case($response['headers'], CASE_LOWER) : array();
        $responseBody = isset($response['body']) ? (string) $response['body'] : '';

        if ($status < 200 || $status >= 300) {
            throw ApiException::fromResponse($status, $responseBody, $responseHeaders);
        }
        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            $requestId = isset($responseHeaders['x-request-id']) ? (string) $responseHeaders['x-request-id'] : '';
            throw new ApiException(502, 'invalid_response', 'TeePay returned a response that is not JSON.', $requestId);
        }

        return $decoded;
    }

    /**
     * Default cURL transport with TLS verification and timeouts.
     *
     * @param string      $method
     * @param string      $url
     * @param string[]    $headers
     * @param string|null $body
     * @return array
     */
    public static function curlTransport($method, $url, array $headers, $body)
    {
        if (!function_exists('curl_init')) {
            throw new ApiException(0, 'network_error', 'The PHP cURL extension is required.');
        }
        $responseHeaders = array();
        $handle = curl_init($url);
        curl_setopt_array($handle, array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ));
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }
        $result = curl_exec($handle);
        if ($result === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new ApiException(0, 'network_error', 'Could not reach TeePay: ' . $error);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return array('status' => $status, 'headers' => $responseHeaders, 'body' => (string) $result);
    }

    /**
     * @param string $value
     * @return int
     */
    private static function length($value)
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}
