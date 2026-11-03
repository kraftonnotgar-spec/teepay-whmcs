<?php

namespace TeePay\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use TeePay\Whmcs\SessionEvaluator;
use TeePay\Whmcs\Tests\Fakes\Fixtures;

class SessionEvaluatorTest extends TestCase
{
    private function expect(array $overrides = array())
    {
        return array_merge(array(
            'session_id' => Fixtures::SESSION_ID,
            'reference' => Fixtures::config()->referenceFor(42),
            'currency' => 'TZS',
            'amount_minor' => '500000',
            'environment' => 'live',
        ), $overrides);
    }

    public function testVerifiedPaidSessionWithMatchingAmountIsPaid()
    {
        $result = SessionEvaluator::evaluate(Fixtures::session(), $this->expect());
        $this->assertSame(SessionEvaluator::PAID, $result['decision']);
        $this->assertSame('TP8K2M4QZ1', $result['payment_reference']);
        $this->assertSame('500000', $result['amount_minor']);
    }

    /**
     * @dataProvider rejections
     */
    public function testMismatchesAreRejected(array $session, array $expect, $reason)
    {
        $result = SessionEvaluator::evaluate(Fixtures::session($session), $this->expect($expect));
        $this->assertSame(SessionEvaluator::REJECTED, $result['decision']);
        $this->assertSame($reason, $result['reason']);
    }

    public function rejections()
    {
        return array(
            'underpaid' => array(array('amount_minor' => '499999'), array(), 'amount_mismatch'),
            'overpaid' => array(array('amount_minor' => '500001'), array(), 'amount_mismatch'),
            'leading zero' => array(array('amount_minor' => '0500000'), array(), 'amount_mismatch'),
            'currency' => array(array('currency' => 'KES'), array(), 'currency_mismatch'),
            'reference from other site' => array(array('reference' => 'whmcs-ffffffff-42'), array(), 'reference_mismatch'),
            'reference for other invoice' => array(array('reference' => Fixtures::config()->referenceFor(43)), array(), 'reference_mismatch'),
            'sandbox session in live mode' => array(array('environment' => 'sandbox'), array(), 'environment_mismatch'),
            'different session' => array(array('id' => '11111111-2222-4333-8444-555555555555'), array(), 'session_mismatch'),
            'wrong object' => array(array('object' => 'collection'), array(), 'not_a_checkout_session'),
            'invoice already settled' => array(array(), array('amount_minor' => '0'), 'amount_mismatch'),
        );
    }

    /**
     * @dataProvider notYetPaid
     */
    public function testUnpaidStatusesAreNeverPaid($status, $decision)
    {
        $result = SessionEvaluator::evaluate(Fixtures::session(array('status' => $status, 'payment' => null)), $this->expect());
        $this->assertSame($decision, $result['decision']);
    }

    public function notYetPaid()
    {
        return array(
            'open' => array('open', SessionEvaluator::PENDING),
            'processing' => array('processing', SessionEvaluator::PENDING),
            'unavailable' => array('unavailable', SessionEvaluator::PENDING),
            'unknown future status' => array('refunded', SessionEvaluator::PENDING),
            'expired' => array('expired', SessionEvaluator::FAILED),
        );
    }

    public function testPaidWithoutPaymentReferenceIsNotPaid()
    {
        $result = SessionEvaluator::evaluate(Fixtures::session(array('payment' => null)), $this->expect());
        $this->assertSame(SessionEvaluator::PENDING, $result['decision']);
        $this->assertSame('payment_reference_missing', $result['reason']);
    }
}
