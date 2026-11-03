<?php
/**
 * TeePay callback endpoint for WHMCS.
 *
 *  POST action=pay           client clicked "Pay now": create the checkout session and redirect.
 *  GET  ?action=return       client returned from TeePay (payment profile return URL).
 *  POST with X-TeePay-*      signed webhook (payment profile webhook URL).
 *
 * @license MIT
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../teepay/lib/autoload.php';

use TeePay\Whmcs\ApiClient;
use TeePay\Whmcs\CallbackHandler;
use TeePay\Whmcs\Config;
use TeePay\Whmcs\LocalWhmcsBridge;
use TeePay\Whmcs\Response;

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);
if (empty($gatewayParams['type'])) {
    http_response_code(404);
    die('Module Not Activated');
}
if (empty($gatewayParams['systemurl']) && class_exists('\\WHMCS\\Config\\Setting')) {
    $gatewayParams['systemurl'] = (string) \WHMCS\Config\Setting::getValue('SystemURL');
}
if (empty($gatewayParams['companyname']) && class_exists('\\WHMCS\\Config\\Setting')) {
    $gatewayParams['companyname'] = (string) \WHMCS\Config\Setting::getValue('CompanyName');
}

$config = Config::fromParams($gatewayParams);
$client = null;
if (ApiClient::isValidKey($config->apiKey)) {
    $client = new ApiClient($config->apiKey);
}
$handler = new CallbackHandler($config, $client, new LocalWhmcsBridge($gatewayModuleName));

$headers = array();
if (function_exists('getallheaders')) {
    $headers = (array) getallheaders();
}
foreach (array('X-TeePay-Signature', 'X-TeePay-Timestamp', 'X-TeePay-Event-ID') as $name) {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!isset($headers[$name]) && isset($_SERVER[$serverKey])) {
        $headers[$name] = $_SERVER[$serverKey];
    }
}
$headers = array_change_key_case($headers, CASE_LOWER);

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
$action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : '';

if ($method === 'POST' && isset($headers['x-teepay-signature'])) {
    $response = $handler->handleWebhook((string) file_get_contents('php://input'), $headers);
} elseif ($method === 'POST' && $action === 'pay') {
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = array();
    }
    $response = $handler->handlePay($_POST, $_SESSION);
} elseif ($method === 'GET' && $action === 'return') {
    if (!isset($_SESSION) || !is_array($_SESSION)) {
        $_SESSION = array();
    }
    $response = $handler->handleReturn($_SESSION);
} else {
    $response = new Response(400, 'Bad Request', array('Content-Type' => 'text/plain; charset=utf-8'));
}

$response->emit();
