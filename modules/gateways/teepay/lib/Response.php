<?php
/**
 * @license MIT
 */

namespace TeePay\Whmcs;

/**
 * A plain HTTP response produced by CallbackHandler and emitted by the callback script.
 */
class Response
{
    /** @var int */
    public $status;

    /** @var array<string, string> */
    public $headers;

    /** @var string */
    public $body;

    /**
     * @param int    $status
     * @param string $body
     * @param array  $headers
     */
    public function __construct($status, $body = '', array $headers = array())
    {
        $this->status = (int) $status;
        $this->body = (string) $body;
        $this->headers = $headers;
    }

    /**
     * @param string $url
     * @return self
     */
    public static function redirect($url)
    {
        return new self(303, '', array('Location' => $url, 'Cache-Control' => 'no-store'));
    }

    /**
     * @param int   $status
     * @param array $data
     * @return self
     */
    public static function json($status, array $data)
    {
        return new self($status, (string) json_encode($data), array('Content-Type' => 'application/json', 'Cache-Control' => 'no-store'));
    }

    /**
     * A minimal page with a message for the client and a link back to the invoice.
     *
     * @param int    $status
     * @param string $message
     * @param string $backUrl
     * @return self
     */
    public static function page($status, $message, $backUrl)
    {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex"><title>Payment</title>'
            . '<style>body{font-family:system-ui,sans-serif;background:#f6f8f6;color:#0B2A1B;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
            . 'main{background:#fff;border-radius:12px;padding:32px;max-width:440px;box-shadow:0 1px 3px rgba(0,0,0,.08)}a{color:#287A35;font-weight:600}</style>'
            . '</head><body><main><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="' . htmlspecialchars($backUrl, ENT_QUOTES, 'UTF-8') . '">Back to your invoice</a></p></main></body></html>';

        return new self($status, $html, array('Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'));
    }

    /**
     * Send the response to the browser or webhook sender.
     *
     * @return void
     */
    public function emit()
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(array("\r", "\n"), '', $value));
            }
        }
        echo $this->body;
    }
}
