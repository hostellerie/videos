<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

/**
 * Central construction point for external video providers and their services.
 */
class Videos_ProviderFactory
{
    public static function youtube($bootstrap, $configuration)
    {
        $timeout = isset($configuration['youtube_timeout'])
            ? (int) $configuration['youtube_timeout'] : 8;

        return new Videos_YouTubeProvider(
            new Videos_YouTubeClient(
                $bootstrap->getYouTubeApiKey(),
                $timeout
            )
        );
    }

    public static function youtubeService($bootstrap, $configuration)
    {
        $store = $bootstrap->getStore();

        return new Videos_YouTubeService(
            self::youtube($bootstrap, $configuration),
            new Videos_Cache($store),
            new Videos_Quota($store),
            new Videos_Logger($store)
        );
    }
}
