<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_YouTubeService
{
    private $client;
    private $cache;
    private $quota;
    private $logger;
    private $moderation;

    public function __construct($client, $cache, $quota, $logger)
    {
        $this->client = $client;
        $this->cache = $cache;
        $this->quota = $quota;
        $this->logger = $logger;
        $this->moderation = new Videos_Moderation($cache->getStore());
    }

    public function find($query, $parameters)
    {
        $context = array('query' => $query, 'parameters' => $parameters);
        $key = $this->cache->searchKey($context);
        $cached = $this->cache->getSearch($key, false);
        if ($cached !== false) {
            return $this->filterUnavailableResult(
                $cached['data']['result']
            );
        }

        $searchLimit = isset($parameters['daily_search_limit'])
            ? (int) $parameters['daily_search_limit'] : 20;
        if (!$this->quota->reserve('search', $searchLimit)) {
            return $this->fallback(
                $key,
                'local_quota_exceeded',
                $context
            );
        }

        $ids = $this->client->search($query, $parameters);
        if ($ids === false) {
            return $this->apiFailure($key, $context);
        }
        if (!$this->quota->reserve('videos', 500)) {
            return $this->fallback(
                $key,
                'details_quota_exceeded',
                $context
            );
        }
        $videos = $this->client->videos($ids);
        if ($videos === false) {
            return $this->apiFailure($key, $context);
        }
        $availabilityTtl = isset($parameters['availability_cache_ttl'])
            ? (int) $parameters['availability_cache_ttl'] : 86400;
        $this->recordAvailability($ids, $videos, $availabilityTtl);

        $videos = $this->filterVideos($videos, $parameters);
        $channelIds = array();
        foreach ($videos as $video) {
            if (isset($video['snippet']['channelId'])) {
                $channelIds[] = $video['snippet']['channelId'];
            }
        }
        $channels = array();
        if (count($channelIds) > 0 &&
            $this->quota->reserve('channels', 500)) {
            $channels = $this->client->channels($channelIds);
            if ($channels === false) {
                $channels = array();
            }
        }

        $videoTtl = isset($parameters['video_cache_ttl'])
            ? (int) $parameters['video_cache_ttl'] : 86400;
        foreach ($videos as $videoId => $video) {
            $this->cache->putVideo($videoId, $video, $videoTtl, 31536000);
        }
        $channelTtl = isset($parameters['channel_cache_ttl'])
            ? (int) $parameters['channel_cache_ttl'] : 604800;
        foreach ($channels as $channelId => $channel) {
            $this->cache->putChannel(
                $channelId,
                $channel,
                $channelTtl,
                5184000
            );
        }

        $result = array(
            'video_ids' => array_keys($videos),
            'videos' => $videos,
            'channels' => $channels,
            'query' => $query,
            'cache_key' => $key
        );
        $ttl = isset($parameters['cache_ttl'])
            ? (int) $parameters['cache_ttl'] : 86400;
        $this->cache->putSearch($key, $context, $result, $ttl, 2592000);
        $this->quota->recordSuccess();
        return $result;
    }

    private function filterVideos($videos, $parameters)
    {
        $result = array();
        $blockedVideos = $this->listSet(
            isset($parameters['blocked_videos'])
                ? $parameters['blocked_videos'] : ''
        );
        $blockedChannels = $this->listSet(
            isset($parameters['blocked_channels'])
                ? $parameters['blocked_channels'] : ''
        );
        $allowedChannels = $this->listSet(
            isset($parameters['allowed_channels'])
                ? $parameters['allowed_channels'] : ''
        );
        $minimum = isset($parameters['minimum_duration'])
            ? (int) $parameters['minimum_duration'] : 0;
        $maximum = isset($parameters['maximum_duration'])
            ? (int) $parameters['maximum_duration'] : 0;

        foreach ($videos as $id => $video) {
            $channel = isset($video['snippet']['channelId'])
                ? $video['snippet']['channelId'] : '';
            $public = isset($video['status']['privacyStatus']) &&
                $video['status']['privacyStatus'] === 'public';
            $embeddable = !empty($video['status']['embeddable']);
            $moderationState = $this->moderation->getChannelState($channel);
            $moderationAllowed = isset($moderationState['state']) &&
                $moderationState['state'] === 'allowed';
            $duration = isset($video['contentDetails']['duration'])
                ? $this->durationSeconds($video['contentDetails']['duration'])
                : 0;

            if ($this->moderation->isVideoBlocked($id) ||
                $this->moderation->isChannelExcluded($channel) ||
                isset($blockedVideos[$id]) ||
                isset($blockedChannels[$channel]) ||
                (count($allowedChannels) > 0 &&
                 !isset($allowedChannels[$channel]) &&
                 !$moderationAllowed) ||
                !$public || !$embeddable ||
                ($minimum > 0 && $duration < $minimum) ||
                ($maximum > 0 && $duration > $maximum) ||
                Videos_VideoPolicy::excludesShortVideo(
                    $video,
                    $parameters
                )) {
                continue;
            }
            $video['videos_duration_seconds'] = $duration;
            $result[$id] = $video;
        }
        return $result;
    }

    private function durationSeconds($duration)
    {
        if (!preg_match(
            '/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/',
            $duration,
            $match
        )) {
            return 0;
        }
        return (isset($match[1]) ? (int) $match[1] * 86400 : 0)
            + (isset($match[2]) ? (int) $match[2] * 3600 : 0)
            + (isset($match[3]) ? (int) $match[3] * 60 : 0)
            + (isset($match[4]) ? (int) $match[4] : 0);
    }

    private function listSet($value)
    {
        $items = is_array($value)
            ? $value
            : preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $set = array();
        foreach ($items as $item) {
            $set[trim($item)] = true;
        }
        return $set;
    }

    private function recordAvailability($ids, $videos, $ttl)
    {
        foreach ((array) $ids as $videoId) {
            if (!Videos_Validator::youtubeVideoId($videoId)) {
                continue;
            }
            if (!isset($videos[$videoId]) ||
                !is_array($videos[$videoId])) {
                $this->cache->putAvailability(
                    $videoId,
                    false,
                    'not_found_or_private',
                    $ttl
                );
                continue;
            }
            $status = isset($videos[$videoId]['status']) &&
                is_array($videos[$videoId]['status'])
                ? $videos[$videoId]['status'] : array();
            $public = isset($status['privacyStatus']) &&
                $status['privacyStatus'] === 'public';
            $embeddable = !empty($status['embeddable']);
            $reason = 'available';
            if (!$public) {
                $reason = 'not_public';
            } elseif (!$embeddable) {
                $reason = 'not_embeddable';
            }
            $this->cache->putAvailability(
                $videoId,
                $public && $embeddable,
                $reason,
                $ttl
            );
        }
    }

    private function filterUnavailableResult($result)
    {
        if (!is_array($result) ||
            !isset($result['videos']) ||
            !is_array($result['videos'])) {
            return $result;
        }
        foreach (array_keys($result['videos']) as $videoId) {
            $video = $result['videos'][$videoId];
            $channelId = isset($video['snippet']['channelId'])
                ? (string) $video['snippet']['channelId'] : '';
            if ($this->cache->isVideoUnavailable($videoId) ||
                $this->moderation->isVideoBlocked($videoId) ||
                $this->moderation->isChannelExcluded($channelId)) {
                unset($result['videos'][$videoId]);
            }
        }
        $result['video_ids'] = array_keys($result['videos']);
        return $result;
    }

    private function apiFailure($key, $context)
    {
        $error = $this->client->getLastError();
        $this->quota->recordError($error);
        $this->logger->log(
            'error',
            isset($error['code']) ? $error['code'] : 'youtube_error',
            'YouTube API request failed.',
            array(
                'http_status' => isset($error['http_status'])
                    ? $error['http_status'] : 0,
                'api_reason' => isset($error['code']) ? $error['code'] : ''
            )
        );
        return $this->fallback(
            $key,
            isset($error['code']) ? $error['code'] : 'youtube_error',
            $context
        );
    }

    private function fallback($key, $reason, $context)
    {
        $cached = $this->cache->getSearch($key, true);
        if ($cached !== false) {
            $result = $this->filterFallbackResult(
                $cached['data']['result'],
                $context['parameters']
            );
            $result['served_stale'] = true;
            $result['fallback_reason'] = $reason;
            return $result;
        }
        $compatible = $this->cache->findCompatibleSearch($context, 500);
        if ($compatible !== false &&
            isset($compatible['data']['result'])) {
            $result = $this->filterFallbackResult(
                $compatible['data']['result'],
                $context['parameters']
            );
            if (count($result['videos']) === 0) {
                return false;
            }
            $sourceKey = isset($compatible['data']['key'])
                ? (string) $compatible['data']['key'] : '';
            $result['cache_key'] = $key;
            $result['query'] = $context['query'];
            $result['served_stale'] = true;
            $result['served_compatible_cache'] = true;
            $result['compatibility_source_key'] = $sourceKey;
            $result['fallback_reason'] = $reason;
            $this->cache->putSearch(
                $key,
                $context,
                $result,
                300,
                2592000
            );
            $this->logger->log(
                'info',
                'compatible_cache_fallback',
                'A compatible YouTube search cache was used.',
                array(
                    'fallback_reason' => $reason,
                    'video_count' => count($result['videos'])
                )
            );
            return $result;
        }
        return false;
    }

    private function filterFallbackResult($result, $parameters)
    {
        $result = $this->filterUnavailableResult($result);
        if (!is_array($result) ||
            !isset($result['videos']) ||
            !is_array($result['videos'])) {
            return array('video_ids' => array(), 'videos' => array());
        }
        $result['videos'] = $this->filterVideos(
            $result['videos'],
            $parameters
        );
        $result['video_ids'] = array_keys($result['videos']);
        return $result;
    }
}
