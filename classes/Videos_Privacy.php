<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Privacy
{
    private $store;
    private $secret;

    public function __construct($store, $secret)
    {
        $this->store = $store;
        $this->secret = $secret;
    }

    public function accountHash($uid)
    {
        if (!Videos_Validator::accountUid($uid)) {
            return false;
        }
        return hash_hmac('sha256', 'account:' . (string) $uid, $this->secret);
    }

    public function visitorHash($visitorId)
    {
        if (!is_string($visitorId) ||
            !preg_match('/^[a-f0-9]{32,128}$/', $visitorId)) {
            return false;
        }
        return hash_hmac('sha256', 'visitor:' . $visitorId, $this->secret);
    }

    public function accountPath($uid)
    {
        $hash = $this->accountHash($uid);
        if ($hash === false) {
            return false;
        }
        return 'users/' . substr($hash, 0, 2) . '/' . $hash;
    }

    public function deleteAccountData($uid)
    {
        $base = $this->accountPath($uid);
        if ($base === false) {
            return false;
        }
        $subjectHash = $this->accountHash($uid);

        $ratingsDocument = $this->store->read(
            $base . '/ratings.json',
            'videos.user_ratings',
            array('ratings' => array())
        );
        $accountRatings = isset($ratingsDocument['data']['ratings']) &&
            is_array($ratingsDocument['data']['ratings'])
            ? $ratingsDocument['data']['ratings'] : array();

        $index = $this->store->read(
            $base . '/views-index.json',
            'videos.user_views_index',
            array('months' => array())
        );
        $months = isset($index['data']['months']) &&
            is_array($index['data']['months'])
            ? $index['data']['months']
            : array();

        $result = true;
        foreach ($accountRatings as $videoId => $ratingData) {
            if (!Videos_Validator::youtubeVideoId($videoId)) {
                $result = false;
                continue;
            }
            $ratingPath = 'ratings/' . substr($videoId, 0, 2) . '/'
                . $videoId . '/' . substr($subjectHash, 0, 2) . '.json';
            $updated = $this->store->update(
                $ratingPath,
                'videos.ratings',
                array('video_id' => $videoId, 'ratings' => array()),
                function ($document) use ($subjectHash) {
                    unset($document['data']['ratings'][$subjectHash]);
                    return $document;
                }
            );
            if ($updated === false) {
                $result = false;
            }
        }

        foreach ($months as $month) {
            if (!is_string($month) ||
                !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month)) {
                $result = false;
                continue;
            }
            if (!$this->store->delete(
                $base . '/views-' . $month . '.json'
            )) {
                $result = false;
            }
        }

        $files = array(
            'profile.json',
            'ratings.json',
            'recommendations.json',
            'views-index.json'
        );
        foreach ($files as $file) {
            if (!$this->store->delete($base . '/' . $file)) {
                $result = false;
            }
        }

        return $result;
    }

    public function exportAccountData($uid)
    {
        $base = $this->accountPath($uid);
        if ($base === false) {
            return false;
        }

        $viewsIndex = $this->store->read(
            $base . '/views-index.json',
            'videos.user_views_index',
            array('months' => array())
        );
        $views = array();
        $months = isset($viewsIndex['data']['months']) &&
            is_array($viewsIndex['data']['months'])
            ? $viewsIndex['data']['months'] : array();
        foreach ($months as $month) {
            if (!is_string($month) ||
                !preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month)) {
                continue;
            }
            $document = $this->store->read(
                $base . '/views-' . $month . '.json',
                'videos.user_views',
                array('month' => $month, 'views' => array())
            );
            $views[$month] = isset($document['data']['views']) &&
                is_array($document['data']['views'])
                ? $document['data']['views'] : array();
        }

        return array(
            'format' => 'videos.account_export',
            'format_version' => 1,
            'exported_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'profile' => $this->documentData($this->store->read(
                $base . '/profile.json',
                'videos.user_profile',
                array()
            )),
            'ratings' => $this->documentData($this->store->read(
                $base . '/ratings.json',
                'videos.user_ratings',
                array('ratings' => array())
            )),
            'recommendations' => $this->documentData($this->store->read(
                $base . '/recommendations.json',
                'videos.user_recommendations',
                array('video_ids' => array())
            )),
            'views' => $views
        );
    }

    public function accountHistory($uid)
    {
        $base = $this->accountPath($uid);
        if ($base === false) {
            return false;
        }
        $index = $this->store->read(
            $base . '/views-index.json',
            'videos.user_views_index',
            array('months' => array())
        );
        $views = array();
        if (isset($index['data']['months']) &&
            is_array($index['data']['months'])) {
            foreach ($index['data']['months'] as $month) {
                if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/', $month)) {
                    continue;
                }
                $document = $this->store->read(
                    $base . '/views-' . $month . '.json',
                    'videos.user_views',
                    array('month' => $month, 'views' => array())
                );
                if (isset($document['data']['views']) &&
                    is_array($document['data']['views'])) {
                    $views = array_merge($views, $document['data']['views']);
                }
            }
        }
        $ratings = $this->store->read(
            $base . '/ratings.json',
            'videos.user_ratings',
            array('ratings' => array())
        );
        return array(
            'views' => $views,
            'ratings' => isset($ratings['data']['ratings'])
                ? $ratings['data']['ratings'] : array()
        );
    }

    public function anonymousViewedVideoIds($visitorId, $monthCount)
    {
        $subjectHash = $this->visitorHash($visitorId);
        if ($subjectHash === false) {
            return array();
        }
        $monthCount = max(1, min(12, (int) $monthCount));
        $videoIds = array();
        for ($offset = 0; $offset < $monthCount; $offset++) {
            $month = gmdate(
                'Y-m',
                strtotime('-' . $offset . ' month', strtotime('first day of this month'))
            );
            $document = $this->store->read(
                'views/' . $month . '/' . substr($subjectHash, 0, 2)
                    . '.json',
                'videos.anonymous_views',
                array('month' => $month, 'views' => array())
            );
            $views = isset($document['data']['views']) &&
                is_array($document['data']['views'])
                ? $document['data']['views'] : array();
            $prefix = $subjectHash . ':';
            foreach ($views as $key => $unused) {
                if (strpos($key, $prefix) !== 0) {
                    continue;
                }
                $videoId = substr($key, strlen($prefix));
                if (Videos_Validator::youtubeVideoId($videoId)) {
                    $videoIds[$videoId] = true;
                }
            }
        }
        return array_keys($videoIds);
    }

    private function documentData($document)
    {
        return isset($document['data']) && is_array($document['data'])
            ? $document['data'] : array();
    }
}
