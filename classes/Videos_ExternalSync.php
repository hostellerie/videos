<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

/**
 * Explicit external synchronization boundary.
 *
 * Public rendering must not instantiate this class. Administration and
 * maintenance paths may use it when they intentionally need provider traffic.
 */
class Videos_ExternalSync
{
    private $bootstrap;
    private $configuration;
    private $store;
    private $cache;
    private $quota;
    private $provider;

    public function __construct($bootstrap, $configuration)
    {
        $this->bootstrap = $bootstrap;
        $this->configuration = is_array($configuration)
            ? $configuration : array();
        $this->store = $bootstrap->getStore();
        $this->cache = new Videos_Cache($this->store);
        $this->quota = new Videos_Quota($this->store);
        $this->provider = Videos_ProviderFactory::youtube(
            $bootstrap,
            $this->configuration
        );
    }

    public function search($query)
    {
        return Videos_ProviderFactory::youtubeService(
            $this->bootstrap,
            $this->configuration
        )->find($query, self::searchParameters($this->configuration));
    }

    public function seedDiscovery($query)
    {
        return (new Videos_DiscoveryReservoir(
            $this->store,
            $this->cache
        ))->refresh(
            $query,
            self::searchParameters($this->configuration),
            $this->configuration,
            Videos_ProviderFactory::youtubeService(
                $this->bootstrap,
                $this->configuration
            ),
            true
        );
    }

    public function fetchVideo($videoId)
    {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }

        $cached = $this->cache->getVideo($videoId, true);
        if (is_array($cached)) {
            return $cached;
        }

        if (!$this->quota->reserve('videos', 500)) {
            return false;
        }
        $videos = $this->provider->videos(array($videoId));
        if (!is_array($videos) || !isset($videos[$videoId])) {
            return false;
        }

        $video = $videos[$videoId];
        $status = isset($video['status']) && is_array($video['status'])
            ? $video['status'] : array();
        if (empty($status['embeddable']) ||
            !isset($status['privacyStatus']) ||
            $status['privacyStatus'] !== 'public' ||
            Videos_VideoPolicy::excludesShortVideo(
                $video,
                $this->configuration
            )) {
            return false;
        }

        if (isset($video['contentDetails']['duration'])) {
            $video['videos_duration_seconds'] = self::durationSeconds(
                $video['contentDetails']['duration']
            );
        }
        $videoTtl = isset($this->configuration['video_cache_ttl'])
            ? (int) $this->configuration['video_cache_ttl'] : 86400;
        if (!$this->cache->putVideo($videoId, $video, $videoTtl, 31536000)) {
            return false;
        }
        $availabilityTtl = isset(
            $this->configuration['availability_cache_ttl']
        ) ? (int) $this->configuration['availability_cache_ttl'] : 86400;
        $this->cache->putAvailability(
            $videoId,
            true,
            'available',
            $availabilityTtl
        );

        $channelId = isset($video['snippet']['channelId'])
            ? (string) $video['snippet']['channelId'] : '';
        if (Videos_Validator::youtubeChannelId($channelId) &&
            $this->quota->reserve('channels', 500)) {
            $channels = $this->provider->channels(array($channelId));
            if (is_array($channels) && isset($channels[$channelId])) {
                $channelTtl = isset(
                    $this->configuration['channel_cache_ttl']
                ) ? (int) $this->configuration['channel_cache_ttl'] : 604800;
                $this->cache->putChannel(
                    $channelId,
                    $channels[$channelId],
                    $channelTtl,
                    5184000
                );
            }
        }

        $this->quota->recordSuccess();
        return $video;
    }

    public static function searchParameters($configuration)
    {
        $configuration = is_array($configuration) ? $configuration : array();
        return array(
            'max_results' => isset($configuration['youtube_max_results'])
                ? $configuration['youtube_max_results'] : 20,
            'order' => 'relevance',
            'safe_search' => isset($configuration['youtube_safe_search'])
                ? $configuration['youtube_safe_search'] : 'moderate',
            'language' => isset($configuration['language'])
                ? $configuration['language'] : 'fr',
            'region' => isset($configuration['region'])
                ? $configuration['region'] : 'FR',
            'published_after' => '',
            'category_id' => '',
            'channel_id' => '',
            'daily_search_limit' => isset(
                $configuration['youtube_daily_search_limit']
            ) ? $configuration['youtube_daily_search_limit'] : 20,
            'cache_ttl' => isset($configuration['search_cache_ttl'])
                ? $configuration['search_cache_ttl'] : 86400,
            'video_cache_ttl' => isset($configuration['video_cache_ttl'])
                ? $configuration['video_cache_ttl'] : 86400,
            'channel_cache_ttl' => isset($configuration['channel_cache_ttl'])
                ? $configuration['channel_cache_ttl'] : 604800,
            'availability_cache_ttl' => isset(
                $configuration['availability_cache_ttl']
            ) ? $configuration['availability_cache_ttl'] : 86400,
            'blocked_videos' => isset($configuration['blocked_videos'])
                ? $configuration['blocked_videos'] : '',
            'blocked_channels' => isset($configuration['blocked_channels'])
                ? $configuration['blocked_channels'] : '',
            'allowed_channels' => isset($configuration['allowed_channels'])
                ? $configuration['allowed_channels'] : '',
            'minimum_duration' => 0,
            'maximum_duration' => 0,
            'exclude_short_videos' => !empty(
                $configuration['exclude_short_videos']
            ) ? 1 : 0,
            'short_filter_mode' => isset($configuration['short_filter_mode'])
                ? $configuration['short_filter_mode'] : 'probable',
            'short_max_duration' => isset($configuration['short_max_duration'])
                ? $configuration['short_max_duration'] : 180
        );
    }

    private static function durationSeconds($duration)
    {
        if (!preg_match(
            '/^P(?:(\\d+)D)?T?(?:(\\d+)H)?(?:(\\d+)M)?(?:(\\d+)S)?$/',
            (string) $duration,
            $match
        )) {
            return 0;
        }
        return (isset($match[1]) ? (int) $match[1] * 86400 : 0)
            + (isset($match[2]) ? (int) $match[2] * 3600 : 0)
            + (isset($match[3]) ? (int) $match[3] * 60 : 0)
            + (isset($match[4]) ? (int) $match[4] : 0);
    }
}
