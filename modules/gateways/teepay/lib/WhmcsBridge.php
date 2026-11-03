<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * The few WHMCS operations the gateway needs, behind an interface so the payment logic is testable.
 */
interface WhmcsBridge
{
    /**
     * @param int $invoiceId
     * @return array|null Keys: id (int), status (string), balance (decimal string), currency (ISO code),
     *                    userid (int), invoicenum (string). Null when the invoice does not exist.
     */
    public function getInvoice($invoiceId);

    /**
     * @param string $transactionId TeePay payment reference.
     * @return bool True when a TeePay payment with this transaction ID is already recorded.
     */
    public function transactionExists($transactionId);

    /**
     * Record the payment against the invoice (checkCbInvoiceID, checkCbTransID, addInvoicePayment).
     *
     * @param int    $invoiceId
     * @param string $transactionId
     * @param string $amount Decimal string, exactly the verified amount.
     * @return void
     */
    public function addPayment($invoiceId, $transactionId, $amount);

    /**
     * Write to the gateway log. Data must already be redacted.
     *
     * @param array  $data
     * @param string $status
     * @return void
     */
    public function log(array $data, $status);

    /**
     * Run $callback while holding a named lock so concurrent webhook and return requests
     * cannot record the same payment twice.
     *
     * @param string   $name
     * @param callable $callback
     * @return mixed
     */
    public function withLock($name, $callback);
}
