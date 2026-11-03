<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * Decides what an authenticated checkout session read means for an invoice.
 *
 * A payment is accepted only when every check passes:
 *  - the session ID, object type, environment and reference are the ones this installation created;
 *  - status is "paid" and payment.reference is set (TeePay verified and ledger-posted the payment);
 *  - currency and amount_minor equal what the invoice still owes, exactly.
 */
class SessionEvaluator
{
    const PAID = 'paid';
    const PENDING = 'pending';
    const FAILED = 'failed';
    const REJECTED = 'rejected';

    /**
     * @param array $session Decoded GET /v1/checkout-sessions/{id} response.
     * @param array $expect  Keys: session_id (optional), reference, currency, amount_minor, environment.
     * @return array{decision:string, reason:string, payment_reference:string, amount_minor:string, currency:string}
     */
    public static function evaluate(array $session, array $expect)
    {
        $result = function ($decision, $reason, $paymentReference = '') use ($session) {
            return array(
                'decision' => $decision,
                'reason' => $reason,
                'payment_reference' => $paymentReference,
                'amount_minor' => isset($session['amount_minor']) ? (string) $session['amount_minor'] : '',
                'currency' => isset($session['currency']) ? (string) $session['currency'] : '',
            );
        };

        if (self::field($session, 'object') !== 'checkout_session') {
            return $result(self::REJECTED, 'not_a_checkout_session');
        }
        if (isset($expect['session_id']) && $expect['session_id'] !== '' && strtolower(self::field($session, 'id')) !== strtolower((string) $expect['session_id'])) {
            return $result(self::REJECTED, 'session_mismatch');
        }
        if (self::field($session, 'environment') !== (string) $expect['environment']) {
            return $result(self::REJECTED, 'environment_mismatch');
        }
        if (self::field($session, 'reference') !== (string) $expect['reference']) {
            return $result(self::REJECTED, 'reference_mismatch');
        }

        $status = self::field($session, 'status');
        if ($status === 'expired') {
            return $result(self::FAILED, 'expired');
        }
        if ($status !== 'paid') {
            // open, processing, unavailable or anything new: never assume payment.
            return $result(self::PENDING, $status === '' ? 'unknown_status' : $status);
        }

        $paymentReference = '';
        if (isset($session['payment']) && is_array($session['payment']) && isset($session['payment']['reference']) && is_string($session['payment']['reference'])) {
            $paymentReference = $session['payment']['reference'];
        }
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,128}$/', $paymentReference)) {
            return $result(self::PENDING, 'payment_reference_missing');
        }
        if (self::field($session, 'currency') !== (string) $expect['currency']) {
            return $result(self::REJECTED, 'currency_mismatch', $paymentReference);
        }
        $amount = self::field($session, 'amount_minor');
        if (!preg_match('/^[1-9][0-9]*$/', $amount) || $amount !== (string) $expect['amount_minor']) {
            return $result(self::REJECTED, 'amount_mismatch', $paymentReference);
        }

        return $result(self::PAID, 'verified', $paymentReference);
    }

    /**
     * @param array  $data
     * @param string $key
     * @return string
     */
    private static function field(array $data, $key)
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? (string) $data[$key] : '';
    }
}
