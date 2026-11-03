<?php

namespace TeePay\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use TeePay\Whmcs\Config;
use TeePay\Whmcs\Tests\Fakes\Fixtures;

/**
 * Loads modules/gateways/teepay.php the way WHMCS does and checks its public functions.
 */
class GatewayModuleTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        if (!defined('WHMCS')) {
            define('WHMCS', true);
        }
        require_once dirname(__DIR__) . '/modules/gateways/teepay.php';
    }

    public function testMetaData()
    {
        $meta = teepay_MetaData();
        $this->assertSame('TeePay (Mobile Money)', $meta['DisplayName']);
        $this->assertSame('1.1', $meta['APIVersion']);
        $this->assertTrue($meta['DisableLocalCreditCardInput']);
        $this->assertFalse($meta['TokenisedStorage']);
    }

    public function testSecretsAreConfiguredAsPasswordFields()
    {
        $config = teepay_config();
        $this->assertSame('System', $config['FriendlyName']['Type']);
        foreach (array('liveApiKey', 'testApiKey', 'liveWebhookSecret', 'testWebhookSecret') as $field) {
            $this->assertSame('password', $config[$field]['Type'], $field);
        }
        $this->assertSame('yesno', $config['testMode']['Type']);
        $this->assertStringContainsString('modules/gateways/callback/teepay.php?action=return', $config['paymentProfileId']['Description']);
    }

    public function testLinkRendersEscapedPayFormWithoutCallingTheApi()
    {
        $params = array(
            'invoiceid' => 42,
            'testMode' => '',
            'liveApiKey' => Fixtures::LIVE_KEY,
            'systemurl' => 'https://billing.example.co.tz/',
            'langpaynow' => 'Pay "now" <b>',
        );
        $html = teepay_link($params);
        $config = Config::fromParams($params);

        $this->assertStringContainsString('action="https://billing.example.co.tz/modules/gateways/callback/teepay.php"', $html);
        $this->assertStringContainsString('name="invoiceid" value="42"', $html);
        $this->assertStringContainsString('value="' . $config->payToken(42) . '"', $html);
        $this->assertStringContainsString('Pay &quot;now&quot; &lt;b&gt;', $html);
        $this->assertStringNotContainsString(Fixtures::LIVE_KEY, $html);
    }

    public function testLinkWithoutKeyShowsUnavailable()
    {
        $html = teepay_link(array('invoiceid' => 42, 'testMode' => 'on', 'testApiKey' => '', 'systemurl' => 'https://b.example/'));
        $this->assertStringContainsString('unavailable', $html);
        $this->assertStringNotContainsString('<form', $html);
    }

    public function testPayTokenIsBoundToInvoiceAndKey()
    {
        $a = Fixtures::config();
        $b = Fixtures::config(true);
        $this->assertNotSame($a->payToken(42), $a->payToken(43));
        $this->assertNotSame($a->payToken(42), $b->payToken(42));
    }
}
