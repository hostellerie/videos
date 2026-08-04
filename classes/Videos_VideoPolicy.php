<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_VideoPolicy
{
    public static function excludesShortVideo($video, $configuration)
    {
        if (empty($configuration['exclude_short_videos']) ||
            !is_array($video)) {
            return false;
        }
        $maximum = isset($configuration['short_max_duration'])
            ? (int) $configuration['short_max_duration'] : 180;
        $maximum = max(1, min(600, $maximum));
        $duration = isset($video['videos_duration_seconds'])
            ? (int) $video['videos_duration_seconds'] : 0;
        if ($duration <= 0 &&
            isset($video['contentDetails']['duration'])) {
            $duration = self::durationSeconds(
                $video['contentDetails']['duration']
            );
        }
        if ($duration <= 0 || $duration > $maximum) {
            return false;
        }
        $mode = isset($configuration['short_filter_mode'])
            ? (string) $configuration['short_filter_mode'] : 'probable';
        if ($mode === 'strict') {
            return true;
        }
        return self::hasShortMarker($video);
    }

    private static function hasShortMarker($video)
    {
        $snippet = isset($video['snippet']) && is_array($video['snippet'])
            ? $video['snippet'] : array();
        $title = isset($snippet['title'])
            ? (string) $snippet['title'] : '';
        $description = isset($snippet['description'])
            ? (string) $snippet['description'] : '';
        if (preg_match('/(?:#|\[)shorts?\b/i', $title) ||
            preg_match('/#shorts?\b/i', $description) ||
            stripos($description, 'youtube.com/shorts/') !== false ||
            stripos($description, 'youtu.be/shorts/') !== false) {
            return true;
        }
        $tags = isset($snippet['tags']) && is_array($snippet['tags'])
            ? $snippet['tags'] : array();
        foreach ($tags as $tag) {
            $normalized = strtolower(trim((string) $tag));
            if (in_array(
                $normalized,
                array('short', 'shorts', 'youtube short', 'youtube shorts'),
                true
            )) {
                return true;
            }
        }
        return false;
    }

    private static function durationSeconds($duration)
    {
        if (!preg_match(
            '/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/',
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
