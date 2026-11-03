<?php

namespace TeePay\Whmcs\Tests\Fakes;

use TeePay\Whmcs\ApiException;

/**
 * Records requests and replays queued responses.
 */
class FakeTransport
{
    /** @var array[] */
    public $requests = array();

    /** @var array[] */
    private $responses = array();

    /**
     * @param int    $status
     * @param mixed  $body    Array (JSON-encoded) or raw string.
     * @param array  $headers
     * @return $this
     */
    public function queue($status, $body, array $headers = array())
    {
        $this->responses[] = array(
            'status' => $status,
            'headers' => $headers,
            'body' => is_array($body) ? json_encode($body) : (string) $body,
        );

        return $this;
    }

    /** @return $this */
    public function queueNetworkError()
    {
        $this->responses[] = 'network';

        return $this;
    }

    public function __invoke($method, $url, array $headers, $body)
    {
        $this->requests[] = array('method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body);
        if (!$this->responses) {
            throw new \LogicException('Unexpected request: ' . $method . ' ' . $url);
        }
        $response = array_shift($this->responses);
        if ($response === 'network') {
            throw new ApiException(0, 'network_error', 'Could not reach TeePay: timeout');
        }

        return $response;
    }
}
