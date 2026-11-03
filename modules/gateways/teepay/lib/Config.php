<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * Gateway settings resolved for the active mode (test or live).
 */
class Config
{
    const GATEWAY = 'teepay';

    /** @var bool */
    public $testMode;

    /** @var string */
    public $apiKey;

    /** @var string */
    public $webhookSecret;

    /** @var string */
    public $paymentProfileId;

    /** @var string Always ends with "/". */
    public $systemUrl;

    /** @var string */
    public $companyName;

    /**
     * @param array $params WHMCS gateway parameters (getGatewayVariables or link $params).
     * @return self
     */
    public static function fromParams(array $params)
    {
        $config = new self();
        $config->testMode = isset($params['testMode']) && in_array(strtolower((string) $params['testMode']), array('on', 'yes', '1', 'true'), true);
        $config->apiKey = trim((string) ($config->testMode ? self::get($params, 'testApiKey') : self::get($params, 'liveApiKey')));
        $config->webhookSecret = trim((string) ($config->testMode ? self::get($params, 'testWebhookSecret') : self::get($params, 'liveWebhookSecret')));
        $config->paymentProfileId = trim((string) self::get($params, 'paymentProfileId'));
        $config->systemUrl = rtrim((string) self::get($params, 'systemurl'), '/') . '/';
        $config->companyName = (string) self::get($params, 'companyname');

        return $config;
    }

    /**
     * "sandbox" in test mode, "live" otherwise (the environment field TeePay returns).
     *
     * @return string
     */
    public function environment()
    {
        return $this->testMode ? 'sandbox' : 'live';
    }

    /**
     * Eight hex characters identifying this WHMCS installation, so several installations can share
     * one TeePay account without matching each other's invoices.
     *
     * @return string
     */
    public function siteTag()
    {
        return substr(hash('sha256', strtolower($this->systemUrl)), 0, 8);
    }

    /**
     * @param int $invoiceId
     * @return string
     */
    public function referenceFor($invoiceId)
    {
        return 'whmcs-' . $this->siteTag() . '-' . (int) $invoiceId;
    }

    /**
     * @param string $reference
     * @return int Invoice ID, or 0 when the reference was not created by this installation.
     */
    public function invoiceIdFromReference($reference)
    {
        if (is_string($reference) && preg_match('/^whmcs-' . preg_quote($this->siteTag(), '/') . '-([1-9][0-9]{0,18})$/', $reference, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    /**
     * Token proving a "Pay now" form was rendered by this installation for this invoice.
     *
     * @param int $invoiceId
     * @return string
     */
    public function payToken($invoiceId)
    {
        $key = hash('sha256', 'teepay-whmcs-pay-form|' . $this->apiKey . '|' . $this->systemUrl);

        return hash_hmac('sha256', 'pay:' . (int) $invoiceId, $key);
    }

    /** @return string */
    public function callbackUrl()
    {
        return $this->systemUrl . 'modules/gateways/callback/teepay.php';
    }

    /**
     * @param array  $params
     * @param string $key
     * @return mixed
     */
    private static function get(array $params, $key)
    {
        return isset($params[$key]) ? $params[$key] : '';
    }
}
