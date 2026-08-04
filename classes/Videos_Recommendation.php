<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Recommendation
{
    private $cache;
    private $selector;
    private $ranking;
    private $moderation;

    public function __construct($cache, $selector, $ranking)
    {
        $this->cache = $cache;
        $this->selector = $selector;
        $this->ranking = $ranking;
        $this->moderation = new Videos_Moderation($cache->getStore());
    }

    public function nextVideos(
        $currentVideoId,
        $contextKey,
        $configuration,
        $uid,
        $additionalViewed = array()
    ) {
        if (!Videos_Validator::youtubeVideoId($currentVideoId)) {
            return array();
        }
        $videos = array();
        $query = 'global-ranking';
        if (is_string($contextKey) &&
            preg_match('/^[a-f0-9]{64}$/', $contextKey)) {
            $cached = $this->cache->getSearch($contextKey, true);
            if ($cached !== false &&
                isset($cached['data']['result']['videos']) &&
                is_array($cached['data']['result']['videos'])) {
                $videos = $cached['data']['result']['videos'];
                if (!empty($cached['data']['result']['query'])) {
                    $query = (string) $cached['data']['result']['query'];
                }
            }
        }
        if (count($videos) === 0) {
            $items = $this->ranking->getGlobal(100);
            foreach ($items as $videoId => $unused) {
                $video = $this->cache->getVideo($videoId, true);
                if (is_array($video)) {
                    $videos[$videoId] = $video;
                }
            }
        }
        if (is_array($additionalViewed)) {
            foreach ($additionalViewed as $viewedVideoId) {
                if (Videos_Validator::youtubeVideoId($viewedVideoId)) {
                    unset($videos[$viewedVideoId]);
                }
            }
        }
        foreach (array_keys($videos) as $videoId) {
            $video = $videos[$videoId];
            $channelId = isset($video['snippet']['channelId'])
                ? (string) $video['snippet']['channelId'] : '';
            if ($this->cache->isVideoUnavailable($videoId) ||
                $this->moderation->isVideoBlocked($videoId) ||
                $this->moderation->isChannelExcluded($channelId)) {
                unset($videos[$videoId]);
            }
        }

        $selectionConfiguration = $configuration;
        $priorityIds = $this->moderation->getPriorityChannelIds(500);
        if (count($priorityIds) > 0) {
            $existingPriority = isset(
                $selectionConfiguration['priority_channels']
            ) ? $selectionConfiguration['priority_channels'] : '';
            $selectionConfiguration['priority_channels'] =
                trim($existingPriority . ',' . implode(',', $priorityIds), ',');
        }
        if (Videos_Validator::accountUid($uid)) {
            $selectionConfiguration['_exclude_viewed'] = true;
        }
        $selection = $this->selector->select(
            $videos,
            $query,
            $selectionConfiguration,
            $uid
        );
        $limit = isset($configuration['suggestion_count'])
            ? max(1, min(20, (int) $configuration['suggestion_count'])) : 6;
        $unseen = array();
        foreach ($selection['videos'] as $videoId => $video) {
            if ($videoId === $currentVideoId) {
                continue;
            }
            $metadata = isset($selection['metadata'][$videoId])
                ? $selection['metadata'][$videoId] : array();
            $item = array(
                'video_id' => $videoId,
                'video' => $video,
                'metadata' => $metadata
            );
            if (empty($metadata['viewed'])) {
                $unseen[] = $item;
            }
        }
        return array_slice($unseen, 0, $limit);
    }
}
