<?php

namespace TeePay\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use TeePay\Whmcs\Money;
use TeePay\Whmcs\Redactor;

class MoneyAndRedactorTest extends TestCase
{
    /**
     * @dataProvider toMinorCases
     */
    public function testToMinor($amount, $currency, $expected)
    {
        $this->assertSame($expected, Money::toMinor($amount, $currency));
    }

    public function toMinorCases()
    {
        return array(
            'TZS whole' => array('5000.00', 'TZS', '500000'),
            'TZS no decimals' => array('5000', 'TZS', '500000'),
            'TZS one decimal' => array('12.5', 'TZS', '1250'),
            'TZS rounding up' => array('10.005', 'TZS', '1001'),
            'TZS rounding down' => array('10.004', 'TZS', '1000'),
            'carry' => array('9.999', 'TZS', '1000'),
            'UGX zero decimal' => array('15000.00', 'UGX', '15000'),
            'KWD three decimals' => array('1.234', 'KWD', '1234'),
            'float' => array(5000.10, 'TZS', '500010'),
            'zero' => array('0.00', 'TZS', '0'),
            'large' => array('9999999999.99', 'TZS', '999999999999'),
        );
    }

    public function testToMinorRejectsNegativeAndGarbage()
    {
        foreach (array('-1.00', 'abc', '1,000.00', '') as $value) {
            try {
                Money::toMinor($value, 'TZS');
                $this->fail('Expected rejection of ' . $value);
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testFromMinor()
    {
        $this->assertSame('5000.00', Money::fromMinor('500000', 'TZS'));
        $this->assertSame('0.05', Money::fromMinor('5', 'TZS'));
        $this->assertSame('15000', Money::fromMinor('15000', 'UGX'));
        $this->assertSame('1.234', Money::fromMinor(1234, 'KWD'));
    }

    public function testRedactorRemovesCredentialsAndPhones()
    {
        $redacted = Redactor::redact(array(
            'header' => 'Authorization: Bearer tp_live_abc.def',
            'note' => 'key tp_test_prefix.secret and whsec_abcdef',
            'phone' => '+255712345678',
            'nested' => array('message' => 'Customer 0712345678 paid', 'liveApiKey' => 'tp_live_x'),
            'amount' => 500000,
        ));

        $flat = json_encode($redacted);
        $this->assertStringNotContainsString('abc.def', $flat);
        $this->assertStringNotContainsString('prefix.secret', $flat);
        $this->assertStringNotContainsString('whsec_abcdef', $flat);
        $this->assertStringNotContainsString('12345678', $flat);
        $this->assertSame('[redacted]', $redacted['phone']);
        $this->assertSame('[redacted]', $redacted['nested']['liveApiKey']);
        $this->assertSame('Customer 071*****78 paid', $redacted['nested']['message']);
        $this->assertSame(500000, $redacted['amount']);
    }
}
