<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_HttpClient
{
    private $timeout;
    private $lastError;

    public function __construct($timeout)
    {
        $this->timeout = max(2, min(30, (int) $timeout));
        $this->lastError = array();
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function getJson($url)
    {
        $this->lastError = array();
        if (!is_string($url) ||
            strpos($url, 'https://www.googleapis.com/youtube/v3/') !== 0) {
            return $this->fail('invalid_url', 0, 'Invalid API URL.');
        }

        if (function_exists('curl_init')) {
            $response = $this->getWithCurl($url);
        } else {
            $response = $this->getWithStreams($url);
        }
        if ($response === false) {
            return false;
        }

        $decoded = json_decode($response['body'], true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return $this->fail(
                'invalid_json',
                $response['status'],
                'YouTube returned invalid JSON.'
            );
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $reason = $this->extractApiReason($decoded);
            return $this->fail(
                $reason,
                $response['status'],
                'YouTube API request failed.'
            );
        }

        return $decoded;
    }

    private function getWithCurl($url)
    {
        $handle = curl_init();
        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $this->timeout);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt(
            $handle,
            CURLOPT_USERAGENT,
            'Geeklog-Videos/' . VIDEOS_PLUGIN_VERSION
        );
        curl_setopt($handle, CURLOPT_HTTPHEADER, array('Accept: application/json'));

        $body = curl_exec($handle);
        if ($body === false) {
            $message = curl_error($handle);
            $number = curl_errno($handle);
            curl_close($handle);
            $code = ($number === 28) ? 'timeout' : 'network_error';
            return $this->fail($code, 0, $message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        return array('status' => $status, 'body' => $body);
    }

    private function getWithStreams($url)
    {
        $context = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n"
                    . "User-Agent: Geeklog-Videos/"
                    . VIDEOS_PLUGIN_VERSION . "\r\n"
            ),
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false
            )
        ));

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return $this->fail(
                'network_error',
                0,
                'HTTPS request failed.'
            );
        }

        $status = 0;
        if (isset($http_response_header[0]) &&
            preg_match('#\s([0-9]{3})\s#', $http_response_header[0], $match)) {
            $status = (int) $match[1];
        }
        return array('status' => $status, 'body' => $body);
    }

    private function extractApiReason($decoded)
    {
        if (isset($decoded['error']['errors'][0]['reason'])) {
            return preg_replace(
                '/[^a-zA-Z0-9_.-]/',
                '',
                $decoded['error']['errors'][0]['reason']
            );
        }
        if (isset($decoded['error']['status'])) {
            return strtolower($decoded['error']['status']);
        }
        return 'api_error';
    }

    private function fail($code, $status, $message)
    {
        $this->lastError = array(
            'code' => $code,
            'http_status' => (int) $status,
            'message' => substr((string) $message, 0, 300)
        );
        return false;
    }
}
