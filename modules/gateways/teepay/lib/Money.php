<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * Converts between decimal amounts and TeePay integer minor units using exact string arithmetic.
 */
class Money
{
    /** @var string[] ISO 4217 currencies with no minor unit. */
    private static $zeroDecimal = array(
        'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'UYI', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    );

    /** @var string[] ISO 4217 currencies with three decimal places. */
    private static $threeDecimal = array('BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND');

    /**
     * @param string $currency
     * @return int
     */
    public static function exponent($currency)
    {
        $currency = strtoupper((string) $currency);
        if (in_array($currency, self::$zeroDecimal, true)) {
            return 0;
        }
        if (in_array($currency, self::$threeDecimal, true)) {
            return 3;
        }

        return 2;
    }

    /**
     * Convert a decimal amount ("5000.00") to minor units ("500000"), rounding half up.
     *
     * @param string|int|float $amount
     * @param string           $currency
     * @return string
     */
    public static function toMinor($amount, $currency)
    {
        if (is_float($amount)) {
            $amount = number_format($amount, 8, '.', '');
        }
        $value = trim((string) $amount);
        if (!preg_match('/^(\d+)(?:\.(\d*))?$/', $value, $match)) {
            throw new \InvalidArgumentException('Amount must be a non-negative decimal number.');
        }
        $exponent = self::exponent($currency);
        $fraction = isset($match[2]) ? $match[2] : '';
        $fraction = str_pad($fraction, $exponent + 1, '0');
        $digits = ltrim($match[1] . substr($fraction, 0, $exponent), '0');
        if ($digits === '') {
            $digits = '0';
        }
        if ((int) $fraction[$exponent] >= 5) {
            $digits = self::increment($digits);
        }

        return $digits;
    }

    /**
     * Convert minor units ("500000") to a decimal string ("5000.00").
     *
     * @param string|int $minor
     * @param string     $currency
     * @return string
     */
    public static function fromMinor($minor, $currency)
    {
        $value = (string) $minor;
        if (!preg_match('/^\d+$/', $value)) {
            throw new \InvalidArgumentException('Minor units must be a non-negative integer.');
        }
        $value = ltrim($value, '0');
        if ($value === '') {
            $value = '0';
        }
        $exponent = self::exponent($currency);
        if ($exponent === 0) {
            return $value;
        }
        $value = str_pad($value, $exponent + 1, '0', STR_PAD_LEFT);

        return substr($value, 0, -$exponent) . '.' . substr($value, -$exponent);
    }

    /**
     * @param string $digits
     * @return string
     */
    private static function increment($digits)
    {
        $index = strlen($digits) - 1;
        while ($index >= 0) {
            if ($digits[$index] !== '9') {
                $digits[$index] = (string) ((int) $digits[$index] + 1);

                return $digits;
            }
            $digits[$index] = '0';
            $index--;
        }

        return '1' . $digits;
    }
}
