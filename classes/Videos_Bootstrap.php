<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Bootstrap
{
    private $store;
    private $secret;
    private $ready;

    public function __construct($geeklogConfig)
    {
        $this->ready = false;
        $this->secret = '';

        $base = isset($geeklogConfig['path_data'])
            ? $geeklogConfig['path_data']
            : $geeklogConfig['path'] . 'data' . DIRECTORY_SEPARATOR;
        $root = rtrim($base, '/\\') . DIRECTORY_SEPARATOR
            . 'videos' . DIRECTORY_SEPARATOR;

        $this->store = new Videos_JsonStore($root, 5242880);
        if (!$this->store->initialize()) {
            return;
        }
        if (!$this->loadOrCreateSecret()) {
            return;
        }

        $this->ready = true;
    }

    public function isReady()
    {
        return $this->ready;
    }

    public function getStore()
    {
        return $this->store;
    }

    public function getSecret()
    {
        return $this->secret;
    }

    public function getYouTubeApiKey()
    {
        $document = $this->store->read(
            'config/secrets.json',
            'videos.secrets',
            array('privacy_hmac_key' => '', 'youtube_api_key' => '')
        );
        return isset($document['data']['youtube_api_key'])
            ? (string) $document['data']['youtube_api_key']
            : '';
    }

    public function setYouTubeApiKey($apiKey)
    {
        $apiKey = trim((string) $apiKey);
        if ($apiKey !== '' &&
            (strlen($apiKey) < 20 || strlen($apiKey) > 200 ||
             !preg_match('/^[A-Za-z0-9_-]+$/', $apiKey))) {
            return false;
        }

        $result = $this->store->update(
            'config/secrets.json',
            'videos.secrets',
            array('privacy_hmac_key' => $this->secret, 'youtube_api_key' => ''),
            function ($document) use ($apiKey) {
                $document['data']['youtube_api_key'] = $apiKey;
                return $document;
            }
        );
        return ($result !== false);
    }

    private function loadOrCreateSecret()
    {
        $document = $this->store->read(
            'config/secrets.json',
            'videos.secrets',
            array('privacy_hmac_key' => '', 'youtube_api_key' => '')
        );

        if (isset($document['data']['privacy_hmac_key']) &&
            preg_match(
                '/^[a-f0-9]{64,}$/',
                $document['data']['privacy_hmac_key']
            )) {
            $this->secret = $document['data']['privacy_hmac_key'];
            return true;
        }

        $secret = $this->generateSecret();
        if ($secret === false) {
            return false;
        }
        $document['data']['privacy_hmac_key'] = $secret;
        if (!isset($document['data']['youtube_api_key'])) {
            $document['data']['youtube_api_key'] = '';
        }
        $document['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        if (!$this->store->write(
            'config/secrets.json',
            'videos.secrets',
            $document
        )) {
            return false;
        }

        $this->secret = $secret;
        return true;
    }

    private function generateSecret()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes(32, $strong);
            if ($bytes !== false && $strong) {
                return bin2hex($bytes);
            }
        }
        return false;
    }
}
