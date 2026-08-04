<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_DiscoveryReservoir
{
    private $store;
    private $cache;
    private $moderation;

    public function __construct($store, $cache)
    {
        $this->store = $store;
        $this->cache = $cache;
        $this->moderation = new Videos_Moderation($store);
    }

    public function status()
    {
        $index = $this->readIndex();
        return array(
            'item_count' => count($this->allItems()),
            'last_refresh_at' => isset($index['last_refresh_at'])
                ? $index['last_refresh_at'] : null,
            'last_seed_at' => isset($index['last_seed_at'])
                ? $index['last_seed_at'] : null
        );
    }

    public function isDue($configuration)
    {
        $index = $this->readIndex();
        $interval = isset($configuration['discovery_refresh_interval'])
            ? max(3600, (int) $configuration['discovery_refresh_interval'])
            : 86400;
        $last = !empty($index['last_refresh_at'])
            ? strtotime($index['last_refresh_at']) : 0;
        $claim = !empty($index['refresh_claimed_at'])
            ? strtotime($index['refresh_claimed_at']) : 0;
        if ($claim > time() - 600) {
            return false;
        }
        return $last <= 0 || $last + $interval <= time();
    }

    public function refresh($query, $parameters, $configuration, $service, $seed)
    {
        if (empty($configuration['discovery_enabled']) ||
            trim((string) $query) === '') {
            return false;
        }
        if (!$seed && !$this->claimRefresh($configuration)) {
            return false;
        }
        if ($seed) {
            $searches = isset($configuration['discovery_seed_searches'])
                ? max(1, min(12, (int) $configuration['discovery_seed_searches']))
                : 8;
        } else {
            $capacity = isset($configuration['discovery_reservoir_size'])
                ? max(50, min(1000, (int) $configuration['discovery_reservoir_size']))
                : 500;
            $renewal = isset($configuration['discovery_refresh_percentage'])
                ? max(1, min(50, (int) $configuration['discovery_refresh_percentage']))
                : 10;
            $searches = max(1, min(4, (int) ceil(
                ($capacity * $renewal / 100) / 50
            )));
        }
        $recentPercent = isset($configuration['discovery_recent_percentage'])
            ? max(0, min(80, (int) $configuration['discovery_recent_percentage']))
            : 30;
        $recentSearches = $recentPercent > 0
            ? max(1, (int) ceil($searches * $recentPercent / 100)) : 0;
        $months = isset($configuration['discovery_recent_months'])
            ? max(1, min(60, (int) $configuration['discovery_recent_months']))
            : 12;
        $terms = preg_split('/\s+/', trim((string) $query), -1, PREG_SPLIT_NO_EMPTY);
        $added = 0;
        $successful = 0;

        for ($i = 0; $i < $searches; $i++) {
            $variant = $this->queryVariant($terms, $query, $i);
            $searchParameters = $parameters;
            $searchParameters['max_results'] = 50;
            if ($i < $recentSearches) {
                $searchParameters['order'] = 'date';
                $searchParameters['published_after'] = gmdate(
                    'Y-m-d\TH:i:s\Z',
                    strtotime('-' . $months . ' months')
                );
            } else {
                $orders = array('relevance', 'viewCount', 'rating');
                $searchParameters['order'] = $orders[$i % count($orders)];
                $searchParameters['published_after'] = '';
            }
            $result = $service->find($variant, $searchParameters);
            if ($result === false || empty($result['videos']) ||
                !is_array($result['videos'])) {
                continue;
            }
            $successful++;
            $added += $this->ingest($result['videos'], $variant);
        }
        if ($successful > 0) {
            $this->touchIndex($query, $seed);
            $this->trim($configuration);
        }
        return array(
            'success' => $successful > 0,
            'searches' => $successful,
            'added' => $added,
            'total' => count($this->allItems())
        );
    }

    public function ingest($videos, $query)
    {
        $buckets = array();
        $now = gmdate('Y-m-d\TH:i:s\Z');
        foreach ((array) $videos as $videoId => $video) {
            if (!Videos_Validator::youtubeVideoId($videoId) || !is_array($video)) {
                continue;
            }
            $bucket = $this->bucket($videoId);
            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = array();
            }
            $buckets[$bucket][$videoId] = array(
                'video_id' => $videoId,
                'channel_id' => isset($video['snippet']['channelId'])
                    ? (string) $video['snippet']['channelId'] : '',
                'published_at' => isset($video['snippet']['publishedAt'])
                    ? (string) $video['snippet']['publishedAt'] : null,
                'discovered_at' => $now,
                'last_seen_at' => $now,
                'query' => substr((string) $query, 0, 300)
            );
        }
        $added = 0;
        foreach ($buckets as $bucket => $incoming) {
            $result = $this->store->update(
                'discovery/reservoir-' . $bucket . '.json',
                'videos.discovery_bucket',
                array('items' => array()),
                function ($document) use ($incoming, &$added) {
                    if (!isset($document['data']['items']) ||
                        !is_array($document['data']['items'])) {
                        $document['data']['items'] = array();
                    }
                    foreach ($incoming as $videoId => $item) {
                        if (!isset($document['data']['items'][$videoId])) {
                            $added++;
                        } else {
                            $item['discovered_at'] = isset(
                                $document['data']['items'][$videoId]['discovered_at']
                            ) ? $document['data']['items'][$videoId]['discovered_at']
                                : $item['discovered_at'];
                        }
                        $document['data']['items'][$videoId] = $item;
                    }
                    return $document;
                }
            );
            if ($result === false) {
                continue;
            }
        }
        return $added;
    }

    public function videos($configuration)
    {
        $recent = array();
        $classic = array();
        $months = isset($configuration['discovery_recent_months'])
            ? max(1, min(60, (int) $configuration['discovery_recent_months']))
            : 12;
        $cutoff = strtotime('-' . $months . ' months');
        foreach ($this->allItems() as $videoId => $item) {
            $video = $this->cache->getVideo($videoId, true);
            if (!$this->allowed($videoId, $video, $configuration)) {
                continue;
            }
            $published = !empty($item['published_at'])
                ? strtotime($item['published_at']) : 0;
            if ($published >= $cutoff) {
                $recent[$videoId] = $video;
            } else {
                $classic[$videoId] = $video;
            }
        }
        $this->stableShuffle($recent, gmdate('Y-m-d') . '|recent');
        $this->stableShuffle($classic, gmdate('Y-m-d') . '|classic');
        return $this->mix($recent, $classic, $configuration);
    }

    private function mix($recent, $classic, $configuration)
    {
        $limit = isset($configuration['catalogue_max_videos'])
            ? max(50, min(500, (int) $configuration['catalogue_max_videos']))
            : 300;
        $percentage = isset($configuration['discovery_recent_percentage'])
            ? max(0, min(80, (int) $configuration['discovery_recent_percentage']))
            : 30;
        $recentTarget = min(count($recent), (int) floor($limit * $percentage / 100));
        $result = array();
        $recentUsed = 0;
        $interval = $recentTarget > 0
            ? max(1, (int) floor($limit / $recentTarget)) : $limit + 1;
        while (count($result) < $limit &&
            (count($recent) > 0 || count($classic) > 0)) {
            $useRecent = count($recent) > 0 && $recentUsed < $recentTarget &&
                (count($result) % $interval === 0 || count($classic) === 0);
            if ($useRecent) {
                $videoId = key($recent);
                $result[$videoId] = current($recent);
                unset($recent[$videoId]);
                $recentUsed++;
            } else {
                if (count($classic) === 0) {
                    $videoId = key($recent);
                    $result[$videoId] = current($recent);
                    unset($recent[$videoId]);
                } else {
                    $videoId = key($classic);
                    $result[$videoId] = current($classic);
                    unset($classic[$videoId]);
                }
            }
        }
        return $result;
    }

    private function allowed($videoId, $video, $configuration)
    {
        if (!is_array($video) || $this->cache->isVideoUnavailable($videoId) ||
            $this->moderation->isVideoBlocked($videoId)) {
            return false;
        }
        $channelId = isset($video['snippet']['channelId'])
            ? (string) $video['snippet']['channelId'] : '';
        $blockedVideos = $this->listSet(isset($configuration['blocked_videos'])
            ? $configuration['blocked_videos'] : '');
        $blockedChannels = $this->listSet(isset($configuration['blocked_channels'])
            ? $configuration['blocked_channels'] : '');
        $allowedChannels = $this->listSet(isset($configuration['allowed_channels'])
            ? $configuration['allowed_channels'] : '');
        return !$this->moderation->isChannelExcluded($channelId) &&
            !isset($blockedVideos[$videoId]) &&
            !isset($blockedChannels[$channelId]) &&
            (count($allowedChannels) === 0 || isset($allowedChannels[$channelId])) &&
            !Videos_VideoPolicy::excludesShortVideo($video, $configuration);
    }

    private function trim($configuration)
    {
        $maximum = isset($configuration['discovery_reservoir_size'])
            ? max(50, min(1000, (int) $configuration['discovery_reservoir_size']))
            : 500;
        $items = $this->allItems();
        if (count($items) <= $maximum) {
            return;
        }
        uasort($items, array($this, 'compareNewest'));
        $keep = array_slice($items, 0, $maximum, true);
        for ($bucket = 0; $bucket < 10; $bucket++) {
            $bucketItems = array();
            foreach ($keep as $videoId => $item) {
                if ($this->bucket($videoId) === $bucket) {
                    $bucketItems[$videoId] = $item;
                }
            }
            $this->store->write(
                'discovery/reservoir-' . $bucket . '.json',
                'videos.discovery_bucket',
                $this->store->createDocument(
                    'videos.discovery_bucket',
                    array('items' => $bucketItems)
                )
            );
        }
    }

    public function compareNewest($left, $right)
    {
        $leftTime = isset($left['last_seen_at']) ? strtotime($left['last_seen_at']) : 0;
        $rightTime = isset($right['last_seen_at']) ? strtotime($right['last_seen_at']) : 0;
        if ($leftTime == $rightTime) {
            return 0;
        }
        return $leftTime > $rightTime ? -1 : 1;
    }

    private function allItems()
    {
        $items = array();
        for ($bucket = 0; $bucket < 10; $bucket++) {
            $document = $this->store->read(
                'discovery/reservoir-' . $bucket . '.json',
                'videos.discovery_bucket',
                array('items' => array())
            );
            if (!empty($document['data']['items']) &&
                is_array($document['data']['items'])) {
                $items = array_merge($items, $document['data']['items']);
            }
        }
        return $items;
    }

    private function readIndex()
    {
        $document = $this->store->read(
            'discovery/index.json',
            'videos.discovery_index',
            array(
                'last_refresh_at' => null,
                'last_seed_at' => null,
                'last_query' => '',
                'refresh_claimed_at' => null
            )
        );
        return isset($document['data']) && is_array($document['data'])
            ? $document['data'] : array();
    }

    private function touchIndex($query, $seed)
    {
        return $this->store->update(
            'discovery/index.json',
            'videos.discovery_index',
            array(
                'last_refresh_at' => null,
                'last_seed_at' => null,
                'last_query' => '',
                'refresh_claimed_at' => null
            ),
            function ($document) use ($query, $seed) {
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $document['data']['last_refresh_at'] = $now;
                if ($seed) {
                    $document['data']['last_seed_at'] = $now;
                }
                $document['data']['last_query'] = substr((string) $query, 0, 300);
                $document['data']['refresh_claimed_at'] = null;
                return $document;
            }
        );
    }

    private function claimRefresh($configuration)
    {
        $interval = isset($configuration['discovery_refresh_interval'])
            ? max(3600, (int) $configuration['discovery_refresh_interval'])
            : 86400;
        $result = $this->store->update(
            'discovery/index.json',
            'videos.discovery_index',
            array(
                'last_refresh_at' => null,
                'last_seed_at' => null,
                'last_query' => '',
                'refresh_claimed_at' => null
            ),
            function ($document) use ($interval) {
                $last = !empty($document['data']['last_refresh_at'])
                    ? strtotime($document['data']['last_refresh_at']) : 0;
                $claim = !empty($document['data']['refresh_claimed_at'])
                    ? strtotime($document['data']['refresh_claimed_at']) : 0;
                $allowed = ($last <= 0 || $last + $interval <= time()) &&
                    ($claim <= time() - 600);
                $document['data']['claim_granted'] = $allowed;
                if ($allowed) {
                    $document['data']['refresh_claimed_at'] =
                        gmdate('Y-m-d\TH:i:s\Z');
                }
                return $document;
            }
        );
        return is_array($result) && !empty($result['data']['claim_granted']);
    }

    private function queryVariant($terms, $fallback, $index)
    {
        if ($index === 0 || count($terms) < 2) {
            return (string) $fallback;
        }
        $count = min(count($terms), max(2, (int) ceil(count($terms) * 0.7)));
        $offset = $index % count($terms);
        $variant = array();
        for ($i = 0; $i < $count; $i++) {
            $variant[] = $terms[($offset + $i) % count($terms)];
        }
        return implode(' ', array_unique($variant));
    }

    private function stableShuffle(&$videos, $seed)
    {
        $keys = array();
        foreach ($videos as $videoId => $video) {
            $keys[$videoId] = hash('sha256', $seed . '|' . $videoId);
        }
        asort($keys, SORT_STRING);
        $ordered = array();
        foreach ($keys as $videoId => $unused) {
            $ordered[$videoId] = $videos[$videoId];
        }
        $videos = $ordered;
    }

    private function bucket($videoId)
    {
        return hexdec(substr(hash('sha256', $videoId), 0, 2)) % 10;
    }

    private function listSet($value)
    {
        $items = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $set = array();
        foreach ($items as $item) {
            $set[trim($item)] = true;
        }
        return $set;
    }
}
