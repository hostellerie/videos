<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_RateLimiter
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function consume($subjectHash, $action, $limit, $windowSeconds)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $subjectHash) ||
            !preg_match('/^[a-z_]{2,30}$/', $action)) {
            return false;
        }
        $limit = max(1, min(1000, (int) $limit));
        $windowSeconds = max(10, min(86400, (int) $windowSeconds));
        $path = 'abuse/' . gmdate('Y-m-d') . '/'
            . substr($subjectHash, 0, 2) . '.json';
        $now = time();

        $result = $this->store->update(
            $path,
            'videos.rate_limits',
            array('subjects' => array()),
            function ($document) use (
                $subjectHash,
                $action,
                $limit,
                $windowSeconds,
                $now
            ) {
                if (!isset($document['data']['subjects'][$subjectHash])) {
                    $document['data']['subjects'][$subjectHash] = array();
                }
                $events = isset(
                    $document['data']['subjects'][$subjectHash][$action]
                ) ? $document['data']['subjects'][$subjectHash][$action]
                  : array();
                $minimum = $now - $windowSeconds;
                $events = array_values(array_filter(
                    $events,
                    function ($timestamp) use ($minimum) {
                        return is_int($timestamp) && $timestamp >= $minimum;
                    }
                ));
                if (count($events) >= $limit) {
                    $document['data']['granted'] = false;
                } else {
                    $events[] = $now;
                    $document['data']['granted'] = true;
                }
                $document['data']['subjects'][$subjectHash][$action] = $events;
                return $document;
            }
        );
        return is_array($result) && !empty($result['data']['granted']);
    }
}

