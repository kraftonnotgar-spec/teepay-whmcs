<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * Handles the three requests that reach modules/gateways/callback/teepay.php:
 *
 *  - pay:     the client clicked "Pay now"; create (or reuse) a checkout session and redirect to it.
 *  - return:  the client came back from TeePay; confirm with an authenticated status read.
 *  - webhook: TeePay notified us; verify the signature, then confirm with an authenticated status read.
 *
 * Payment is recorded only by PaymentProcessor after an authenticated GET of the checkout session.
 * Query-string parameters on the return URL are never read.
 */
class CallbackHandler
{
    const SESSION_INVOICE = 'teepay_invoice_id';
    const SESSION_CHECKOUT = 'teepay_checkout_session_id';

    const CLIENT_UNAVAILABLE = 'Mobile money payments are unavailable right now. Please choose another payment method or try again later.';
    const CLIENT_RETRY = 'We could not start your mobile money payment. Please try again in a moment.';

    /** @var Config */
    private $config;

    /** @var ApiClient|null */
    private $client;

    /** @var WhmcsBridge */
    private $bridge;

    /** @var PaymentProcessor */
    private $processor;

    /**
     * @param Config         $config
     * @param ApiClient|null $client Null when no valid API key is configured for the active mode.
     * @param WhmcsBridge    $bridge
     */
    public function __construct(Config $config, $client, WhmcsBridge $bridge)
    {
        $this->config = $config;
        $this->client = $client;
        $this->bridge = $bridge;
        $this->processor = new PaymentProcessor($config, $bridge);
    }

    /**
     * @param array    $post    $_POST.
     * @param array    $session $_SESSION (by reference).
     * @param int|null $now
     * @return Response
     */
    public function handlePay(array $post, array &$session, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $invoiceId = isset($post['invoiceid']) && is_scalar($post['invoiceid']) && ctype_digit((string) $post['invoiceid']) ? (int) $post['invoiceid'] : 0;
        $token = isset($post['token']) && is_string($post['token']) ? $post['token'] : '';
        if ($invoiceId <= 0 || !hash_equals($this->config->payToken($invoiceId), $token)) {
            return Response::page(403, 'This payment link is not valid. Please open your invoice and try again.', $this->config->systemUrl . 'clientarea.php?action=invoices');
        }
        $invoiceUrl = $this->invoiceUrl($invoiceId);
        if ($this->client === null) {
            $this->bridge->log(array('action' => 'pay', 'invoice_id' => $invoiceId, 'error' => 'No valid TeePay API key is configured for the active mode.'), 'Configuration Error');

            return Response::page(503, self::CLIENT_UNAVAILABLE, $invoiceUrl);
        }

        $invoice = $this->bridge->getInvoice($invoiceId);
        if ($invoice === null || !in_array((string) $invoice['status'], array('Unpaid', 'Payment Pending'), true)) {
            return Response::redirect($invoiceUrl);
        }
        $currency = strtoupper((string) $invoice['currency']);
        try {
            $amountMinor = Money::toMinor((string) $invoice['balance'], $currency);
        } catch (\InvalidArgumentException $e) {
            $amountMinor = '0';
        }
        if ($amountMinor === '0' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return Response::redirect($invoiceUrl);
        }

        $label = isset($invoice['invoicenum']) && (string) $invoice['invoicenum'] !== '' ? (string) $invoice['invoicenum'] : (string) $invoiceId;
        $title = self::truncate('Invoice #' . $label, 100);
        $description = $this->config->companyName !== '' ? self::truncate('Payment to ' . $this->config->companyName, 200) : '';
        $input = array(
            'title' => $title,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'expires_in' => 86400,
            'reference' => $this->config->referenceFor($invoiceId),
            'metadata' => array('invoice_id' => (string) $invoiceId, 'source' => 'whmcs'),
        );
        if ($description !== '') {
            $input['description'] = $description;
        }
        if ($this->config->paymentProfileId !== '') {
            $input['payment_profile_id'] = $this->config->paymentProfileId;
        }
        // Same invoice, amount and currency within a 12-hour window => same open session (24h expiry).
        $idempotencyKey = sprintf('whmcs-%s-%d-%s-%s-%d', $this->config->siteTag(), $invoiceId, $amountMinor, $currency, (int) floor($now / 43200));

        try {
            $checkout = $this->client->createCheckoutSession($input, $idempotencyKey);
        } catch (ApiException $e) {
            $this->bridge->log(Redactor::redact(array(
                'action' => 'pay',
                'invoice_id' => $invoiceId,
                'http_status' => $e->getStatus(),
                'error_code' => $e->getErrorCode(),
                'request_id' => $e->getRequestId(),
                'admin_notice' => $e->adminMessage(),
            )), $e->isAccountRestricted() ? 'Account Restricted' : 'Error');

            return Response::page(
                $e->isRetryable() ? 503 : 502,
                $e->isRetryable() ? self::CLIENT_RETRY : self::CLIENT_UNAVAILABLE,
                $invoiceUrl
            );
        } catch (\InvalidArgumentException $e) {
            $this->bridge->log(array('action' => 'pay', 'invoice_id' => $invoiceId, 'error' => $e->getMessage()), 'Error');

            return Response::page(502, self::CLIENT_UNAVAILABLE, $invoiceUrl);
        }

        $checkoutUrl = isset($checkout['checkout_url']) && is_string($checkout['checkout_url']) ? $checkout['checkout_url'] : '';
        $sessionId = isset($checkout['id']) && is_string($checkout['id']) ? $checkout['id'] : '';
        if (strpos($checkoutUrl, 'https://') !== 0 || !preg_match(ApiClient::UUID_PATTERN, $sessionId)) {
            $this->bridge->log(array('action' => 'pay', 'invoice_id' => $invoiceId, 'error' => 'Checkout session has no usable checkout_url.', 'status' => isset($checkout['status']) ? (string) $checkout['status'] : ''), 'Error');

            return Response::page(502, self::CLIENT_RETRY, $invoiceUrl);
        }

        $session[self::SESSION_INVOICE] = $invoiceId;
        $session[self::SESSION_CHECKOUT] = $sessionId;

        return Response::redirect($checkoutUrl);
    }

    /**
     * The client returned from TeePay. Only values stored in the PHP session are used.
     *
     * @param array $session $_SESSION (by reference).
     * @return Response
     */
    public function handleReturn(array &$session)
    {
        $invoiceId = isset($session[self::SESSION_INVOICE]) ? (int) $session[self::SESSION_INVOICE] : 0;
        $sessionId = isset($session[self::SESSION_CHECKOUT]) && is_string($session[self::SESSION_CHECKOUT]) ? $session[self::SESSION_CHECKOUT] : '';
        if ($invoiceId <= 0 || !preg_match(ApiClient::UUID_PATTERN, $sessionId)) {
            return Response::redirect($this->config->systemUrl . 'clientarea.php?action=invoices');
        }
        $invoiceUrl = $this->invoiceUrl($invoiceId);
        if ($this->client === null) {
            return Response::redirect($invoiceUrl);
        }
        try {
            $checkout = $this->client->getCheckoutSession($sessionId);
        } catch (ApiException $e) {
            // The webhook will confirm the payment; never assume an outcome.
            return Response::redirect($invoiceUrl);
        }
        $outcome = $this->processor->process($invoiceId, $checkout, $sessionId, 'return');
        if (in_array($outcome['result'], array(PaymentProcessor::APPLIED, PaymentProcessor::DUPLICATE), true)) {
            unset($session[self::SESSION_INVOICE], $session[self::SESSION_CHECKOUT]);

            return Response::redirect($invoiceUrl . '&paymentsuccess=true');
        }
        if ($outcome['result'] === PaymentProcessor::FAILED) {
            return Response::redirect($invoiceUrl . '&paymentfailed=true');
        }

        return Response::redirect($invoiceUrl);
    }

    /**
     * @param string   $rawBody Exact request body bytes.
     * @param array    $headers Request headers (any case).
     * @param int|null $now
     * @return Response
     */
    public function handleWebhook($rawBody, array $headers, $now = null)
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $timestamp = isset($headers['x-teepay-timestamp']) ? (string) $headers['x-teepay-timestamp'] : null;
        $signature = isset($headers['x-teepay-signature']) ? (string) $headers['x-teepay-signature'] : null;
        $eventId = isset($headers['x-teepay-event-id']) ? substr((string) $headers['x-teepay-event-id'], 0, 64) : '';

        if ($this->config->webhookSecret === '') {
            $this->bridge->log(array('action' => 'webhook', 'event_id' => $eventId, 'error' => 'No webhook signing secret is configured for the active mode.'), 'Configuration Error');

            return Response::json(500, array('error' => 'webhook_not_configured'));
        }
        $verifier = new WebhookVerifier($this->config->webhookSecret);
        if (!$verifier->verify((string) $rawBody, $timestamp, $signature, $now)) {
            $this->bridge->log(array('action' => 'webhook', 'event_id' => $eventId, 'error' => $verifier->lastError()), 'Invalid Signature');

            return Response::json(401, array('error' => 'invalid_signature'));
        }

        $event = json_decode((string) $rawBody, true);
        if (!is_array($event) || !isset($event['event']) || !is_string($event['event'])) {
            return Response::json(400, array('error' => 'invalid_payload'));
        }
        $ignored = function ($reason) use ($event, $eventId) {
            $this->bridge->log(Redactor::redact(array('action' => 'webhook', 'event_id' => $eventId, 'event' => $event['event'], 'ignored' => $reason)), 'Ignored');

            return Response::json(200, array('received' => true, 'result' => 'ignored'));
        };

        if (!isset($event['environment']) || $event['environment'] !== $this->config->environment()) {
            return $ignored('environment_mismatch');
        }
        if ($event['event'] !== 'payment.completed') {
            // Failed, voided and expired payments leave the invoice unpaid; nothing to change.
            return $ignored('event_not_handled');
        }
        $source = isset($event['data']['source']) && is_array($event['data']['source']) ? $event['data']['source'] : array();
        $sessionId = isset($source['id']) && is_string($source['id']) ? $source['id'] : '';
        if (!isset($source['type']) || $source['type'] !== 'checkout_session' || !preg_match(ApiClient::UUID_PATTERN, $sessionId)) {
            return $ignored('not_a_checkout_session');
        }
        if ($this->client === null) {
            $this->bridge->log(array('action' => 'webhook', 'event_id' => $eventId, 'error' => 'No valid TeePay API key is configured for the active mode.'), 'Configuration Error');

            return Response::json(503, array('error' => 'api_key_not_configured'));
        }

        try {
            $checkout = $this->client->getCheckoutSession($sessionId);
        } catch (ApiException $e) {
            if ($e->getStatus() === 404) {
                return $ignored('session_not_found_for_this_key');
            }
            $this->bridge->log(Redactor::redact(array(
                'action' => 'webhook',
                'event_id' => $eventId,
                'checkout_session' => $sessionId,
                'http_status' => $e->getStatus(),
                'error_code' => $e->getErrorCode(),
                'request_id' => $e->getRequestId(),
                'admin_notice' => $e->adminMessage(),
            )), $e->isAccountRestricted() ? 'Account Restricted' : 'Error');

            return Response::json($e->isRetryable() ? 503 : 500, array('error' => 'status_read_failed'));
        }

        $invoiceId = $this->config->invoiceIdFromReference(isset($checkout['reference']) ? $checkout['reference'] : '');
        if ($invoiceId === 0) {
            return $ignored('reference_not_from_this_installation');
        }
        $outcome = $this->processor->process($invoiceId, $checkout, $sessionId, 'webhook');

        return Response::json(200, array('received' => true, 'result' => $outcome['result']));
    }

    /**
     * @param int $invoiceId
     * @return string
     */
    private function invoiceUrl($invoiceId)
    {
        return $this->config->systemUrl . 'viewinvoice.php?id=' . (int) $invoiceId;
    }

    /**
     * @param string $value
     * @param int    $length
     * @return string
     */
    private static function truncate($value, $length)
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }
}
