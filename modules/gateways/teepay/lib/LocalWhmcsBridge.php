<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

use WHMCS\Database\Capsule;

/**
 * WhmcsBridge backed by the real WHMCS runtime (localAPI, Capsule and the gateway helper functions).
 *
 * Only loaded inside WHMCS, from modules/gateways/callback/teepay.php.
 */
class LocalWhmcsBridge implements WhmcsBridge
{
    /** @var string */
    private $gateway;

    /**
     * @param string $gateway Gateway module name (normally "teepay").
     */
    public function __construct($gateway = Config::GATEWAY)
    {
        $this->gateway = (string) $gateway;
    }

    public function getInvoice($invoiceId)
    {
        $invoice = localAPI('GetInvoice', array('invoiceid' => (int) $invoiceId));
        if (!is_array($invoice) || !isset($invoice['result']) || $invoice['result'] !== 'success') {
            return null;
        }
        $userId = isset($invoice['userid']) ? (int) $invoice['userid'] : 0;

        return array(
            'id' => (int) $invoice['invoiceid'],
            'status' => isset($invoice['status']) ? (string) $invoice['status'] : '',
            'balance' => isset($invoice['balance']) ? (string) $invoice['balance'] : '0',
            'currency' => $this->clientCurrency($userId),
            'userid' => $userId,
            'invoicenum' => isset($invoice['invoicenum']) ? (string) $invoice['invoicenum'] : '',
        );
    }

    public function transactionExists($transactionId)
    {
        return Capsule::table('tblaccounts')
            ->where('gateway', $this->gateway)
            ->where('transid', (string) $transactionId)
            ->exists();
    }

    public function addPayment($invoiceId, $transactionId, $amount)
    {
        // Both helpers stop the request if the invoice is unknown or the transaction is already recorded.
        $invoiceId = checkCbInvoiceID((int) $invoiceId, $this->gateway);
        checkCbTransID((string) $transactionId);
        addInvoicePayment($invoiceId, (string) $transactionId, (string) $amount, 0, $this->gateway);
    }

    public function log(array $data, $status)
    {
        logTransaction($this->gateway, Redactor::redact($data), (string) $status);
    }

    public function withLock($name, $callback)
    {
        $lock = substr('teepay_' . hash('sha256', (string) $name), 0, 64);
        $acquired = false;
        try {
            $row = Capsule::selectOne('SELECT GET_LOCK(?, 10) AS acquired', array($lock));
            $acquired = $row !== null && (int) $row->acquired === 1;
        } catch (\Exception $e) {
            $acquired = false;
        }
        try {
            return call_user_func($callback);
        } finally {
            if ($acquired) {
                try {
                    Capsule::selectOne('SELECT RELEASE_LOCK(?) AS released', array($lock));
                } catch (\Exception $e) {
                    // The lock is released when the connection closes.
                }
            }
        }
    }

    /**
     * @param int $userId
     * @return string ISO currency code of the client's currency.
     */
    private function clientCurrency($userId)
    {
        if ($userId > 0 && function_exists('getCurrency')) {
            $currency = getCurrency($userId);
            if (is_array($currency) && isset($currency['code'])) {
                return strtoupper((string) $currency['code']);
            }
        }
        $client = localAPI('GetClientsDetails', array('clientid' => $userId, 'stats' => false));
        if (is_array($client)) {
            if (isset($client['currency_code'])) {
                return strtoupper((string) $client['currency_code']);
            }
            if (isset($client['client']['currency_code'])) {
                return strtoupper((string) $client['client']['currency_code']);
            }
        }

        return '';
    }
}
