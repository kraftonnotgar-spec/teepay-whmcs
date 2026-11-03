<?php
/**
 * TeePay payment gateway module for WHMCS.
 *
 * Accept M-Pesa, Airtel Money, Mixx by Yas and HaloPesa through TeePay's hosted checkout.
 * Invoices are marked paid only after an authenticated read of the checkout session confirms
 * a verified payment of the exact amount and currency.
 *
 * @see       https://docs.teepay.me/docs/plugins/whmcs
 * @license   MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/teepay/lib/autoload.php';

use TeePay\Whmcs\ApiClient;
use TeePay\Whmcs\Config;

/**
 * @return array
 */
function teepay_MetaData()
{
    return array(
        'DisplayName' => 'TeePay (Mobile Money)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * @return array
 */
function teepay_config()
{
    $callbackUrl = 'https://your-whmcs.example/modules/gateways/callback/teepay.php';
    try {
        if (class_exists('\\WHMCS\\Config\\Setting')) {
            $systemUrl = (string) \WHMCS\Config\Setting::getValue('SystemURL');
            if ($systemUrl !== '') {
                $callbackUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/teepay.php';
            }
        }
    } catch (\Exception $e) {
        // Keep the placeholder.
    }
    $url = htmlspecialchars($callbackUrl, ENT_QUOTES, 'UTF-8');

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'TeePay (Mobile Money)',
        ),
        'liveApiKey' => array(
            'FriendlyName' => 'Live API key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Starts with tp_live_. Needs the collection:create and collection:read scopes. Create it in the TeePay dashboard under Developers.',
        ),
        'liveWebhookSecret' => array(
            'FriendlyName' => 'Live webhook signing secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Starts with whsec_. From Developers &rarr; Webhooks in the TeePay dashboard (live).',
        ),
        'testMode' => array(
            'FriendlyName' => 'Test mode',
            'Type' => 'yesno',
            'Description' => 'Use the test (sandbox) key and secret. Sandbox keys verify your setup; real mobile money payments run in live mode only.',
        ),
        'testApiKey' => array(
            'FriendlyName' => 'Test API key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Starts with tp_test_.',
        ),
        'testWebhookSecret' => array(
            'FriendlyName' => 'Test webhook signing secret',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Starts with whsec_. From Developers &rarr; Webhooks (sandbox).',
        ),
        'paymentProfileId' => array(
            'FriendlyName' => 'Payment profile ID',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Optional. Leave empty to use your default payment profile. '
                . 'On the profile, set the webhook URL to <code>' . $url . '</code> '
                . 'and the return URL to <code>' . $url . '?action=return</code>.',
        ),
    );
}

/**
 * Validate settings when the administrator saves them (WHMCS 8+).
 *
 * @param array $params
 * @return void
 */
function teepay_config_validate(array $params)
{
    $errors = array();
    foreach (array('liveApiKey' => 'tp_live_', 'testApiKey' => 'tp_test_') as $field => $prefix) {
        $value = isset($params[$field]) ? trim((string) $params[$field]) : '';
        if ($value !== '' && (strpos($value, $prefix) !== 0 || !ApiClient::isValidKey($value))) {
            $errors[] = 'The ' . ($prefix === 'tp_live_' ? 'live' : 'test') . ' API key must start with ' . $prefix . '.';
        }
    }
    foreach (array('liveWebhookSecret', 'testWebhookSecret') as $field) {
        $value = isset($params[$field]) ? trim((string) $params[$field]) : '';
        if ($value !== '' && strpos($value, 'whsec_') !== 0) {
            $errors[] = 'Webhook signing secrets start with whsec_.';
        }
    }
    $profile = isset($params['paymentProfileId']) ? trim((string) $params['paymentProfileId']) : '';
    if ($profile !== '' && !preg_match(ApiClient::UUID_PATTERN, $profile)) {
        $errors[] = 'The payment profile ID must be a UUID, or empty.';
    }
    if ($errors && class_exists('\\WHMCS\\Exception\\Module\\InvalidConfiguration')) {
        throw new \WHMCS\Exception\Module\InvalidConfiguration(implode(' ', array_unique($errors)));
    }
}

/**
 * Render the "Pay now" button on the invoice. The checkout session is created only when the client
 * clicks it, by modules/gateways/callback/teepay.php.
 *
 * @param array $params
 * @return string
 */
function teepay_link($params)
{
    $config = Config::fromParams($params);
    if (!ApiClient::isValidKey($config->apiKey)) {
        return '<p>' . htmlspecialchars('Mobile money payments are unavailable right now.', ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $invoiceId = (int) $params['invoiceid'];
    $label = isset($params['langpaynow']) && $params['langpaynow'] !== '' ? (string) $params['langpaynow'] : 'Pay now';

    return '<form method="post" action="' . htmlspecialchars($config->callbackUrl(), ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="action" value="pay">'
        . '<input type="hidden" name="invoiceid" value="' . $invoiceId . '">'
        . '<input type="hidden" name="token" value="' . htmlspecialchars($config->payToken($invoiceId), ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit" class="btn btn-success">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button>'
        . '<p class="small text-muted" style="margin-top:6px">M-Pesa, Airtel Money, Mixx by Yas, HaloPesa</p>'
        . '</form>';
}
