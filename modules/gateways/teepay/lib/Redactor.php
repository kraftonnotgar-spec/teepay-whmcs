<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * Removes credentials and personal data before anything is written to the gateway log.
 */
class Redactor
{
    /** @var string[] Array keys whose values are always hidden. */
    private static $sensitiveKeys = array(
        'apikey', 'api_key', 'liveapikey', 'testapikey', 'secret', 'webhooksecret', 'livewebhooksecret',
        'testwebhooksecret', 'authorization', 'token', 'password', 'phone', 'customer_phone', 'email',
    );

    /**
     * @param mixed $value
     * @return mixed
     */
    public static function redact($value)
    {
        if (is_array($value)) {
            $result = array();
            foreach ($value as $key => $item) {
                if (is_string($key) && in_array(strtolower($key), self::$sensitiveKeys, true)) {
                    $result[$key] = '[redacted]';
                    continue;
                }
                $result[$key] = self::redact($item);
            }

            return $result;
        }
        if (!is_string($value)) {
            return $value;
        }

        $value = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $value);
        $value = preg_replace('/\btp_(live|test)_[A-Za-z0-9._-]+/', 'tp_$1_[redacted]', $value);
        $value = preg_replace('/\bwhsec_[A-Za-z0-9._-]+/', 'whsec_[redacted]', $value);
        $value = preg_replace_callback('/(?<!\d)(\+?255|0)([67]\d{8})(?!\d)/', static function ($m) {
            return $m[1] . substr($m[2], 0, 2) . '*****' . substr($m[2], -2);
        }, $value);

        return $value;
    }
}
