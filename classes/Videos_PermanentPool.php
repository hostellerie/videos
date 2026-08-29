<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_PermanentPool
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
        $data = $this->readData();
        return array(
            'rebuilt_at' => $data['rebuilt_at'],
            'item_count' => count($data['items']),
            'excluded_count' => count($data['excluded'])
        );
    }

    public function records()
    {
        return $this->readData();
    }

    public function markDirty()
    {
        $result = $this->store->update(
            'rankings/permanent_pool.json',
            'videos.permanent_pool',
            $this->emptyData(),
            function ($document) {
                $document['data']['rebuilt_at'] = null;
                return $document;
            }
        );
        return $result !== false;
    }

    public function setManualState($videoId, $state, $rankingItem)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) ||
            !in_array($state, array('pinned', 'removed', 'excluded', 'allowed'), true)) {
            return false;
        }
        if ($state === 'pinned' && !is_array($this->cache->getVideo($videoId, true))) {
            return false;
        }

        $before = $this->readData();
        $wasPublished = isset($before['items'][$videoId]);
        $result = $this->store->update(
            'rankings/permanent_pool.json',
            'videos.permanent_pool',
            $this->emptyData(),
            function ($document) use ($videoId, $state, $rankingItem) {
                $data = isset($document['data']) && is_array($document['data'])
                    ? $document['data'] : $this->emptyData();
                if (!isset($data['items']) || !is_array($data['items'])) {
                    $data['items'] = array();
                }
                if (!isset($data['excluded']) || !is_array($data['excluded'])) {
                    $data['excluded'] = array();
                }
                if ($state === 'pinned') {
                    unset($data['excluded'][$videoId]);
                    $data['items'][$videoId] = $this->poolItem(
                        $videoId,
                        is_array($rankingItem) ? $rankingItem : array(),
                        'manual',
                        gmdate('Y-m-d\TH:i:s\Z')
                    );
                } elseif ($state === 'excluded') {
                    unset($data['items'][$videoId]);
                    $data['excluded'][$videoId] = gmdate('Y-m-d\TH:i:s\Z');
                    if (count($data['excluded']) > 500) {
                        $data['excluded'] = array_slice($data['excluded'], -500, null, true);
                    }
                } elseif ($state === 'allowed') {
                    unset($data['excluded'][$videoId]);
                } else {
                    unset($data['items'][$videoId]);
                }
                $data['rebuilt_at'] = null;
                $document['data'] = $data;
                return $document;
            }
        );
        if ($result === false) {
            return false;
        }

        if ($state === 'pinned' && !$wasPublished && function_exists('PLG_itemSaved')) {
            PLG_itemSaved($videoId, 'videos');
        }
        return true;
    }

    public function synchronize($rankingItems, $configuration, $force)
    {
        $data = $this->readData();
        $previousItems = $data['items'];
        $interval = isset($configuration['ranking_rebuild_interval'])
            ? max(60, (int) $configuration['ranking_rebuild_interval']) : 3600;
        $last = !empty($data['rebuilt_at']) ? strtotime($data['rebuilt_at']) : false;
        if (!$force && $last !== false && time() - $last < $interval) {
            return $data;
        }

        $maximum = isset($configuration['permanent_pool_size'])
            ? max(1, min(100, (int) $configuration['permanent_pool_size'])) : 24;
        $minimumRatings = isset($configuration['permanent_pool_min_ratings'])
            ? max(1, min(1000, (int) $configuration['permanent_pool_min_ratings'])) : 3;
        $minimumWeighted = isset($configuration['permanent_pool_min_weighted_rating'])
            ? max(0, min(5, (float) $configuration['permanent_pool_min_weighted_rating'])) : 4.0;
        $automatic = !empty($configuration['permanent_pool_auto']);
        $keepBelow = !empty($configuration['permanent_pool_keep_below_threshold']);
        $items = array();

        foreach ($data['items'] as $videoId => $item) {
            if (!Videos_Validator::youtubeVideoId($videoId) || !isset($item['source'])) {
                continue;
            }
            if ($item['source'] === 'manual' || ($keepBelow && $item['source'] === 'automatic')) {
                $items[$videoId] = $item;
            }
        }
        if ($automatic && is_array($rankingItems)) {
            foreach ($rankingItems as $videoId => $rankingItem) {
                if (count($items) >= $maximum ||
                    !Videos_Validator::youtubeVideoId($videoId) ||
                    isset($data['excluded'][$videoId])) {
                    continue;
                }
                $ratingCount = isset($rankingItem['rating_count']) ? (int) $rankingItem['rating_count'] : 0;
                $weighted = isset($rankingItem['weighted_rating']) ? (float) $rankingItem['weighted_rating'] : 0;
                if ($ratingCount < $minimumRatings || $weighted < $minimumWeighted) {
                    continue;
                }
                $admittedAt = isset($items[$videoId]['admitted_at'])
                    ? $items[$videoId]['admitted_at'] : gmdate('Y-m-d\TH:i:s\Z');
                $items[$videoId] = $this->poolItem($videoId, $rankingItem, 'automatic', $admittedAt);
            }
        }
        uasort($items, array($this, 'comparePoolItems'));
        if (count($items) > $maximum) {
            $items = array_slice($items, 0, $maximum, true);
        }
        $document = $this->store->createDocument(
            'videos.permanent_pool',
            array(
                'rebuilt_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'items' => $items,
                'excluded' => $data['excluded']
            )
        );
        if (!$this->store->write('rankings/permanent_pool.json', 'videos.permanent_pool', $document)) {
            return false;
        }

        if (function_exists('PLG_itemSaved')) {
            foreach ($items as $videoId => $item) {
                if (!isset($previousItems[$videoId])) {
                    PLG_itemSaved($videoId, 'videos');
                }
            }
        }
        return $document['data'];
    }

    public function videos($rankingItems, $configuration)
    {
        if (empty($configuration['permanent_pool_enabled'])) {
            return array();
        }
        $data = $this->synchronize($rankingItems, $configuration, false);
        if (!is_array($data)) {
            return array();
        }
        $blockedVideos = $this->listSet(isset($configuration['blocked_videos']) ? $configuration['blocked_videos'] : '');
        $blockedChannels = $this->listSet(isset($configuration['blocked_channels']) ? $configuration['blocked_channels'] : '');
        $videos = array();
        foreach ($data['items'] as $videoId => $item) {
            $video = $this->cache->getVideo($videoId, true);
            if (!is_array($video)) {
                continue;
            }
            $channelId = isset($video['snippet']['channelId']) ? (string) $video['snippet']['channelId'] : '';
            if ($this->cache->isVideoUnavailable($videoId) ||
                $this->moderation->isVideoBlocked($videoId) ||
                $this->moderation->isChannelExcluded($channelId) ||
                isset($blockedVideos[$videoId]) ||
                isset($blockedChannels[$channelId]) ||
                Videos_VideoPolicy::excludesShortVideo($video, $configuration)) {
                continue;
            }
            $videos[$videoId] = $video;
        }
        return $videos;
    }

    public function mergeSelections($discovery, $permanent, $configuration)
    {
        $discoveryVideos = isset($discovery['videos']) && is_array($discovery['videos']) ? $discovery['videos'] : array();
        $discoveryMetadata = isset($discovery['metadata']) && is_array($discovery['metadata']) ? $discovery['metadata'] : array();
        $permanentVideos = isset($permanent['videos']) && is_array($permanent['videos']) ? $permanent['videos'] : array();
        $permanentMetadata = isset($permanent['metadata']) && is_array($permanent['metadata']) ? $permanent['metadata'] : array();
        $target = isset($configuration['catalogue_max_videos'])
            ? max(50, min(500, (int) $configuration['catalogue_max_videos'])) : 300;
        $percentage = isset($configuration['permanent_pool_percentage'])
            ? max(0, min(50, (int) $configuration['permanent_pool_percentage'])) : 25;
        $poolQuota = (int) floor($target * $percentage / 100);
        $maximumPerChannel = isset($configuration['max_same_channel'])
            ? max(1, (int) $configuration['max_same_channel']) : 2;
        $result = array();
        $metadata = array();
        $lastChannel = null;
        $consecutive = 0;
        $poolUsed = 0;
        $interval = $poolQuota > 0 ? max(2, (int) round($target / $poolQuota)) : $target + 1;

        while (count($result) < $target &&
            (count($discoveryVideos) > 0 || ($poolUsed < $poolQuota && count($permanentVideos) > 0))) {
            $usePool = $poolUsed < $poolQuota && count($permanentVideos) > 0 &&
                (count($result) % $interval === 0 || count($discoveryVideos) === 0);
            if ($usePool) {
                $videoId = key($permanentVideos);
                $video = current($permanentVideos);
                unset($permanentVideos[$videoId]);
                unset($discoveryVideos[$videoId]);
                $itemMetadata = isset($permanentMetadata[$videoId]) ? $permanentMetadata[$videoId] : array();
                $itemMetadata['permanent_pool'] = true;
                $poolUsed++;
            } else {
                $videoId = key($discoveryVideos);
                $video = current($discoveryVideos);
                unset($discoveryVideos[$videoId]);
                unset($permanentVideos[$videoId]);
                $itemMetadata = isset($discoveryMetadata[$videoId]) ? $discoveryMetadata[$videoId] : array();
            }
            $channelId = isset($video['snippet']['channelId']) ? (string) $video['snippet']['channelId'] : '';
            if ($channelId !== '' && $channelId === $lastChannel && $consecutive >= $maximumPerChannel) {
                continue;
            }
            $result[$videoId] = $video;
            $metadata[$videoId] = $itemMetadata;
            if ($channelId !== '' && $channelId === $lastChannel) {
                $consecutive++;
            } else {
                $lastChannel = $channelId;
                $consecutive = 1;
            }
        }
        return array('videos' => $result, 'metadata' => $metadata);
    }

    public function comparePoolItems($left, $right)
    {
        $leftManual = isset($left['source']) && $left['source'] === 'manual';
        $rightManual = isset($right['source']) && $right['source'] === 'manual';
        if ($leftManual !== $rightManual) {
            return $leftManual ? -1 : 1;
        }
        $leftScore = isset($left['score']) ? (float) $left['score'] : 0;
        $rightScore = isset($right['score']) ? (float) $right['score'] : 0;
        if ($leftScore == $rightScore) {
            return strcmp($left['video_id'], $right['video_id']);
        }
        return $leftScore > $rightScore ? -1 : 1;
    }

    private function poolItem($videoId, $rankingItem, $source, $admittedAt)
    {
        return array(
            'video_id' => $videoId,
            'source' => $source,
            'admitted_at' => $admittedAt,
            'score' => isset($rankingItem['score']) ? round((float) $rankingItem['score'], 4) : 0,
            'rating_count' => isset($rankingItem['rating_count']) ? max(0, (int) $rankingItem['rating_count']) : 0,
            'weighted_rating' => isset($rankingItem['weighted_rating']) ? round((float) $rankingItem['weighted_rating'], 4) : 0
        );
    }

    private function readData()
    {
        $document = $this->store->read('rankings/permanent_pool.json', 'videos.permanent_pool', $this->emptyData());
        $data = isset($document['data']) && is_array($document['data']) ? $document['data'] : $this->emptyData();
        $data['items'] = isset($data['items']) && is_array($data['items']) ? $data['items'] : array();
        $data['excluded'] = isset($data['excluded']) && is_array($data['excluded']) ? $data['excluded'] : array();
        $data['rebuilt_at'] = isset($data['rebuilt_at']) ? $data['rebuilt_at'] : null;
        return $data;
    }

    private function emptyData()
    {
        return array('rebuilt_at' => null, 'items' => array(), 'excluded' => array());
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
