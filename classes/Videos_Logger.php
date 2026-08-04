<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Logger
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function log($level, $code, $message, $context)
    {
        $allowedLevels = array('info', 'warning', 'error', 'security');
        if (!in_array($level, $allowedLevels, true)) {
            $level = 'error';
        }

        $entry = array(
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'code' => substr(preg_replace('/[^a-zA-Z0-9_.-]/', '', $code), 0, 64),
            'message' => substr(strip_tags((string) $message), 0, 500),
            'context' => $this->sanitizeContext($context)
        );
        $relative = 'logs/' . gmdate('Y-m-d') . '.json';

        return $this->store->update(
            $relative,
            'videos.technical_log',
            array('entries' => array()),
            function ($document) use ($entry) {
                $document['data']['entries'][] = $entry;
                if (count($document['data']['entries']) > 500) {
                    $document['data']['entries'] = array_slice(
                        $document['data']['entries'],
                        -500
                    );
                }
                return $document;
            }
        );
    }

    private function sanitizeContext($context)
    {
        $result = array();
        if (!is_array($context)) {
            return $result;
        }
        $allowed = array(
            'endpoint',
            'http_status',
            'api_reason',
            'context_hash',
            'video_id',
            'channel_id'
        );
        foreach ($allowed as $key) {
            if (isset($context[$key]) && is_scalar($context[$key])) {
                $result[$key] = substr((string) $context[$key], 0, 128);
            }
        }
        return $result;
    }
}

