<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * Applies an authenticated checkout session read to a WHMCS invoice.
 */
class PaymentProcessor
{
    const APPLIED = 'applied';
    const DUPLICATE = 'duplicate';
    const PENDING = 'pending';
    const FAILED = 'failed';
    const REJECTED = 'rejected';
    const INVOICE_NOT_FOUND = 'invoice_not_found';
    const INVOICE_NOT_PAYABLE = 'invoice_not_payable';

    /** @var Config */
    private $config;

    /** @var WhmcsBridge */
    private $bridge;

    /**
     * @param Config      $config
     * @param WhmcsBridge $bridge
     */
    public function __construct(Config $config, WhmcsBridge $bridge)
    {
        $this->config = $config;
        $this->bridge = $bridge;
    }

    /**
     * @param int    $invoiceId
     * @param array  $session   Decoded authenticated GET /v1/checkout-sessions/{id} response.
     * @param string $sessionId The ID that was read.
     * @param string $origin    "webhook" or "return", for the log.
     * @return array{result:string, reason:string, payment_reference:string}
     */
    public function process($invoiceId, array $session, $sessionId, $origin)
    {
        $invoiceId = (int) $invoiceId;
        $bridge = $this->bridge;
        $config = $this->config;
        $self = $this;

        return $bridge->withLock('teepay_invoice_' . $invoiceId, function () use ($invoiceId, $session, $sessionId, $origin, $bridge, $config, $self) {
            $invoice = $invoiceId > 0 ? $bridge->getInvoice($invoiceId) : null;
            $logBase = array(
                'origin' => $origin,
                'invoice_id' => $invoiceId,
                'checkout_session' => (string) $sessionId,
                'session_status' => isset($session['status']) ? (string) $session['status'] : '',
                'session_amount_minor' => isset($session['amount_minor']) ? (string) $session['amount_minor'] : '',
                'session_currency' => isset($session['currency']) ? (string) $session['currency'] : '',
            );
            if ($invoice === null) {
                $bridge->log(Redactor::redact($logBase + array('reason' => 'invoice_not_found')), 'Invoice Not Found');

                return $self->outcome(self::INVOICE_NOT_FOUND, 'invoice_not_found');
            }

            $paymentReference = '';
            if (isset($session['payment']['reference']) && is_string($session['payment']['reference'])) {
                $paymentReference = $session['payment']['reference'];
            }
            if ($paymentReference !== '' && $bridge->transactionExists($paymentReference)) {
                return $self->outcome(self::DUPLICATE, 'already_recorded', $paymentReference);
            }

            $currency = strtoupper((string) $invoice['currency']);
            $payable = in_array((string) $invoice['status'], array('Unpaid', 'Payment Pending'), true);
            $expectedMinor = '0';
            if ($payable && preg_match('/^[A-Z]{3}$/', $currency)) {
                try {
                    $expectedMinor = Money::toMinor((string) $invoice['balance'], $currency);
                } catch (\InvalidArgumentException $e) {
                    $expectedMinor = '0';
                }
            }

            $evaluation = SessionEvaluator::evaluate($session, array(
                'session_id' => (string) $sessionId,
                'reference' => $config->referenceFor($invoiceId),
                'currency' => $currency,
                // For an invoice that is no longer payable, still evaluate everything else so a verified
                // payment is reported to the administrator as "invoice_not_payable" instead of a mismatch.
                'amount_minor' => $payable ? $expectedMinor : (isset($session['amount_minor']) ? (string) $session['amount_minor'] : ''),
                'environment' => $config->environment(),
            ));
            $log = $logBase + array(
                'invoice_status' => (string) $invoice['status'],
                'invoice_balance' => (string) $invoice['balance'],
                'invoice_currency' => $currency,
                'expected_amount_minor' => $expectedMinor,
                'payment_reference' => $evaluation['payment_reference'],
                'decision' => $evaluation['decision'],
                'reason' => $evaluation['reason'],
            );

            if ($evaluation['decision'] === SessionEvaluator::PAID && !$payable) {
                $bridge->log(Redactor::redact(array_merge($log, array('reason' => 'invoice_not_payable'))), 'Rejected');

                return $self->outcome(self::INVOICE_NOT_PAYABLE, 'invoice_not_payable', $evaluation['payment_reference']);
            }

            switch ($evaluation['decision']) {
                case SessionEvaluator::PAID:
                    $amount = Money::fromMinor($evaluation['amount_minor'], $currency);
                    $bridge->addPayment($invoiceId, $evaluation['payment_reference'], $amount);
                    $bridge->log(Redactor::redact($log + array('amount_recorded' => $amount)), 'Success');

                    return $self->outcome(self::APPLIED, 'verified', $evaluation['payment_reference']);
                case SessionEvaluator::REJECTED:
                    $status = in_array($evaluation['reason'], array('amount_mismatch', 'currency_mismatch'), true) ? 'Amount Mismatch' : 'Rejected';
                    $bridge->log(Redactor::redact($log), $status);

                    return $self->outcome(self::REJECTED, $evaluation['reason'], $evaluation['payment_reference']);
                case SessionEvaluator::FAILED:
                    $bridge->log(Redactor::redact($log), 'Failure');

                    return $self->outcome(self::FAILED, $evaluation['reason']);
                default:
                    if ($origin === 'webhook') {
                        $bridge->log(Redactor::redact($log), 'Pending');
                    }

                    return $self->outcome(self::PENDING, $evaluation['reason']);
            }
        });
    }

    /**
     * @param string $result
     * @param string $reason
     * @param string $paymentReference
     * @return array
     */
    public function outcome($result, $reason, $paymentReference = '')
    {
        return array('result' => $result, 'reason' => $reason, 'payment_reference' => $paymentReference);
    }
}
