<?php

namespace TeePay\Whmcs\Tests\Fakes;

use TeePay\Whmcs\WhmcsBridge;

/**
 * In-memory WHMCS: invoices, recorded payments (tblaccounts) and the gateway log.
 */
class FakeBridge implements WhmcsBridge
{
    /** @var array<int, array> */
    public $invoices = array();

    /** @var array[] */
    public $payments = array();

    /** @var array[] */
    public $logs = array();

    /** @var string[] */
    public $locks = array();

    public function addInvoice($id, $balance, $currency = 'TZS', $status = 'Unpaid')
    {
        $this->invoices[$id] = array(
            'id' => $id,
            'status' => $status,
            'balance' => $balance,
            'currency' => $currency,
            'userid' => 7,
            'invoicenum' => '',
        );
    }

    public function getInvoice($invoiceId)
    {
        return isset($this->invoices[$invoiceId]) ? $this->invoices[$invoiceId] : null;
    }

    public function transactionExists($transactionId)
    {
        foreach ($this->payments as $payment) {
            if ($payment['transid'] === $transactionId) {
                return true;
            }
        }

        return false;
    }

    public function addPayment($invoiceId, $transactionId, $amount)
    {
        if ($this->transactionExists($transactionId)) {
            throw new \LogicException('checkCbTransID would have stopped a duplicate payment.');
        }
        $this->payments[] = array('invoice' => $invoiceId, 'transid' => $transactionId, 'amount' => $amount);
        $this->invoices[$invoiceId]['status'] = 'Paid';
        $this->invoices[$invoiceId]['balance'] = '0.00';
    }

    public function log(array $data, $status)
    {
        $this->logs[] = array('status' => $status, 'data' => $data);
    }

    public function withLock($name, $callback)
    {
        $this->locks[] = $name;

        return call_user_func($callback);
    }

    /** @return string[] */
    public function logStatuses()
    {
        return array_map(function ($entry) {
            return $entry['status'];
        }, $this->logs);
    }
}
