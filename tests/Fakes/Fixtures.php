<?php

namespace TeePay\Whmcs\Tests\Fakes;

use TeePay\Whmcs\Config;

/**
 * Shared test data. Keys and secrets here are obviously fake.
 */
class Fixtures
{
    const LIVE_KEY = 'tp_live_testprefix.notarealsecret000';
    const TEST_KEY = 'tp_test_testprefix.notarealsecret000';
    const SECRET = 'whsec_not_a_real_secret_for_tests';
    const SESSION_ID = '7c0e5d2a-1b3f-4c8e-9a6d-2f4b8e1c0d9a';
    const PROFILE_ID = '3f2a1b4c-5d6e-4f70-8a9b-0c1d2e3f4a5b';

    /**
     * @param bool $testMode
     * @return Config
     */
    public static function config($testMode = false)
    {
        return Config::fromParams(array(
            'testMode' => $testMode ? 'on' : '',
            'liveApiKey' => self::LIVE_KEY,
            'testApiKey' => self::TEST_KEY,
            'liveWebhookSecret' => self::SECRET,
            'testWebhookSecret' => self::SECRET,
            'paymentProfileId' => '',
            'systemurl' => 'https://billing.example.co.tz/',
            'companyname' => 'Example Hosting Ltd',
        ));
    }

    /**
     * A checkout session as GET /v1/checkout-sessions/{id} returns it.
     *
     * @param array $overrides
     * @return array
     */
    public static function session(array $overrides = array())
    {
        $config = self::config();

        return array_merge(array(
            'id' => self::SESSION_ID,
            'object' => 'checkout_session',
            'status' => 'paid',
            'title' => 'Invoice #42',
            'description' => 'Payment to Example Hosting Ltd',
            'currency' => 'TZS',
            'amount_minor' => '500000',
            'checkout_url' => 'https://checkout.teepay.me/checkout/abc',
            'payment_profile_id' => null,
            'reference' => $config->referenceFor(42),
            'metadata' => array('invoice_id' => '42', 'source' => 'whmcs'),
            'payment' => array('reference' => 'TP8K2M4QZ1'),
            'environment' => 'live',
            'created_at' => '2026-10-28T10:00:00Z',
            'expires_at' => '2026-10-29T10:00:00Z',
        ), $overrides);
    }

    /**
     * A webhook body as TeePay sends it.
     *
     * @param string $event
     * @param array  $source
     * @param string $environment
     * @return string
     */
    public static function webhookBody($event = 'payment.completed', $source = null, $environment = 'live')
    {
        if ($source === null) {
            $source = array('type' => 'checkout_session', 'id' => self::SESSION_ID);
        }

        return json_encode(array(
            'id' => '0f8d7c2e-5b1a-5e3f-9c4d-7a6b2e1f0c9d',
            'event' => $event,
            'environment' => $environment,
            'created_at' => '2026-10-28T10:00:05.123456Z',
            'data' => array(
                'reference' => 'TP8K2M4QZ1',
                'transaction_id' => 'pay_6b1f',
                'status' => substr($event, strpos($event, '.') + 1),
                'occurred_at' => '2026-10-28T10:00:03Z',
                'money' => array('currency' => 'TZS', 'amount_minor' => '500000'),
                'source' => $source === false ? null : $source,
            ),
        ));
    }
}
