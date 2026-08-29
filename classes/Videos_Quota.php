<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Quota
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function reserve($method, $limit)
    {
        $allowed = array('search', 'videos', 'channels');
        if (!in_array($method, $allowed, true)) {
            return false;
        }
        $limit = max(0, (int) $limit);
        $path = 'quota/' . gmdate('Y-m-d') . '.json';

        $result = $this->store->update(
            $path,
            'videos.daily_quota',
            array(
                'date' => gmdate('Y-m-d'),
                'counts' => array('search' => 0, 'videos' => 0, 'channels' => 0),
                'suspended' => false,
                'last_search_at' => null,
                'last_success_at' => null,
                'last_error' => null,
                'last_rejection' => null
            ),
            function ($document) use ($method, $limit) {
                $count = isset($document['data']['counts'][$method])
                    ? (int) $document['data']['counts'][$method] : 0;
                if (!empty($document['data']['suspended'])) {
                    $document['data']['reservation_granted'] = false;
                    $document['data']['last_rejection'] = array(
                        'method' => $method, 'reason' => 'quota_suspended',
                        'count' => $count, 'limit' => $limit,
                        'at' => gmdate('Y-m-d\TH:i:s\Z')
                    );
                    return $document;
                }
                if ($limit > 0 && $count >= $limit) {
                    $document['data']['reservation_granted'] = false;
                    $document['data']['last_rejection'] = array(
                        'method' => $method, 'reason' => 'local_limit_reached',
                        'count' => $count, 'limit' => $limit,
                        'at' => gmdate('Y-m-d\TH:i:s\Z')
                    );
                    return $document;
                }
                $document['data']['counts'][$method] = $count + 1;
                $document['data']['reservation_granted'] = true;
                if ($method === 'search') {
                    $document['data']['last_search_at'] = gmdate('Y-m-d\TH:i:s\Z');
                }
                return $document;
            }
        );

        return is_array($result) &&
            !empty($result['data']['reservation_granted']);
    }

    public function recordSuccess()
    {
        return $this->changeStatus(null, false, true);
    }

    public function recordError($error)
    {
        $code = isset($error['code']) ? $error['code'] : 'unknown';
        $suspend = in_array(
            $code,
            array('quotaExceeded', 'quotaexceeded', 'dailyLimitExceeded'),
            true
        );
        return $this->changeStatus($code, $suspend, false);
    }

    public function status()
    {
        return $this->store->read(
            'quota/' . gmdate('Y-m-d') . '.json',
            'videos.daily_quota',
            array(
                'date' => gmdate('Y-m-d'),
                'counts' => array('search' => 0, 'videos' => 0, 'channels' => 0),
                'suspended' => false,
                'last_search_at' => null,
                'last_success_at' => null,
                'last_error' => null,
                'last_rejection' => null
            )
        );
    }

    private function changeStatus($error, $suspend, $success)
    {
        $path = 'quota/' . gmdate('Y-m-d') . '.json';
        return $this->store->update(
            $path,
            'videos.daily_quota',
            array(
                'date' => gmdate('Y-m-d'),
                'counts' => array('search' => 0, 'videos' => 0, 'channels' => 0),
                'suspended' => false,
                'last_search_at' => null,
                'last_success_at' => null,
                'last_error' => null,
                'last_rejection' => null
            ),
            function ($document) use ($error, $suspend, $success) {
                if ($success) {
                    $document['data']['last_success_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $document['data']['last_error'] = null;
                } else {
                    $document['data']['last_error'] = array(
                        'code' => substr((string) $error, 0, 80),
                        'at' => gmdate('Y-m-d\TH:i:s\Z')
                    );
                }
                if ($suspend) {
                    $document['data']['suspended'] = true;
                }
                return $document;
            }
        );
    }
}

