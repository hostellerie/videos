<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

$videosInstallLanguage = isset($_CONF['language'])
    ? strtolower((string) $_CONF['language']) : 'english';
$videosInstallLocale = isset($_CONF['locale'])
    ? (string) $_CONF['locale'] : '';
$videosInstallIsFrench = strpos($videosInstallLanguage, 'french') !== false;
$videosInstallRegion = $videosInstallIsFrench ? 'FR' : 'US';
if (preg_match('/[_-]([A-Za-z]{2})(?:[.@]|$)/', $videosInstallLocale, $matches)) {
    $videosInstallRegion = strtoupper($matches[1]);
}

$GLOBALS['_VID_DEFAULT'] = array(
    'enabled' => 1,
    'public_title' => $videosInstallIsFrench ? 'Vidéos' : 'Videos',
    'language' => $videosInstallIsFrench ? 'fr' : 'en',
    'region' => $videosInstallRegion,
    'videos_per_page' => 12,
    'suggestion_count' => 6,
    'sharing_enabled' => 1,
    'ratings_enabled' => 1,
    'view_tracking_enabled' => 1,
    'youtube_daily_search_limit' => 20,
    'youtube_timeout' => 8,
    'search_cache_ttl' => 86400,
    'video_cache_ttl' => 86400,
    'channel_cache_ttl' => 604800,
    'availability_cache_ttl' => 86400,
    'youtube_max_results' => 20,
    'youtube_safe_search' => 'moderate',
    'discovery_enabled' => 1,
    'discovery_reservoir_size' => 500,
    'catalogue_max_videos' => 300,
    'discovery_seed_searches' => 8,
    'discovery_refresh_interval' => 86400,
    'discovery_refresh_percentage' => 10,
    'discovery_recent_percentage' => 30,
    'discovery_recent_months' => 12,
    'analysis_mode' => 'mixed',
    'manual_keywords' => '',
    'required_keywords' => '',
    'excluded_keywords' => '',
    'additional_stop_words' => '',
    'max_keywords' => 8,
    'title_weight' => 5,
    'meta_weight' => 4,
    'content_weight' => 1,
    'allowed_channels' => '',
    'priority_channels' => '',
    'blocked_channels' => '',
    'blocked_videos' => '',
    'exclude_short_videos' => 0,
    'short_filter_mode' => 'probable',
    'short_max_duration' => 180,
    'description_mode' => 'clean',
    'youtube_player_mode' => 'standard',
    'privacy_enhanced_embed' => 1,
    'autoplay' => 0,
    'rating_threshold_seconds' => 30,
    'view_threshold_seconds' => 30,
    'view_threshold_percent' => 25,
    'ranking_rebuild_interval' => 3600,
    'max_same_channel' => 2,
    'permanent_pool_enabled' => 1,
    'permanent_pool_size' => 24,
    'permanent_pool_percentage' => 25,
    'permanent_pool_auto' => 1,
    'permanent_pool_min_ratings' => 3,
    'permanent_pool_min_weighted_rating' => '4.0',
    'permanent_pool_keep_below_threshold' => 1,
    'public_rankings_enabled' => 1,
    'public_video_ranking_enabled' => 1,
    'public_channel_ranking_enabled' => 1,
    'public_ranking_limit' => 10,
    'account_history_enabled' => 1,
    'account_recommendations_enabled' => 1,
    'anonymous_tracking_enabled' => 1,
    'allow_anonymous_merge' => 1,
    'allow_user_export' => 1,
    'allow_user_deletion' => 1,
    'account_retention_days' => 0,
    'automatic_account_purge' => 0,
    'anonymous_retention_days' => 90,
    'privacy_notice' => $videosInstallIsFrench
        ? 'Ce site utilise un identifiant pseudonyme pour mémoriser les vidéos regardées et les notes.'
        : 'This site uses a pseudonymous identifier to remember watched videos and ratings.',
    'block_enabled' => 0,
    'block_isleft' => 0,
    'block_order' => 50,
    'block_mode' => 'recommended',
    'block_item_count' => 3,
    'seo_enabled' => 1,
    'seo_catalogue_index' => 1,
    'seo_rankings_index' => 1,
    'seo_structured_data' => 1,
    'seo_social_metadata' => 1,
    'seo_description_fallback' => '',
    'faq_catalogue_enabled' => 1,
    'faq_video_enabled' => 1,
    'faq_rankings_enabled' => 1,
    'faq_structured_data' => 0,
    'technical_log_days' => 14
);

if (!defined('VIDEOS_DEFAULT_CONFIGURATION_JSON')) {
    define(
        'VIDEOS_DEFAULT_CONFIGURATION_JSON',
        json_encode($GLOBALS['_VID_DEFAULT'])
    );
}

function videos_default_configuration()
{
    if (!defined('VIDEOS_DEFAULT_CONFIGURATION_JSON')) {
        return array();
    }
    $defaults = json_decode(VIDEOS_DEFAULT_CONFIGURATION_JSON, true);
    return is_array($defaults) ? $defaults : array();
}

function plugin_initconfig_videos($recoverIncompleteInstallation = false)
{
    global $_CONF, $_VID_DEFAULT;

    if (!isset($_VID_DEFAULT) || !is_array($_VID_DEFAULT)) {
        return false;
    }
    $c = config::get_instance();
    if ($c->group_exists('videos')) {
        if (!videos_config_apply_missing_defaults($c)) {
            return false;
        }
        return !$recoverIncompleteInstallation ||
            videos_config_recover_installation_defaults($c);
    }

    $c->add('sg_main', null, 'subgroup', 0, 0, null, 0, true, 'videos', 0);

    videos_config_add_tab($c, 'general', 0);
    videos_config_add_value($c, 'enabled', 'select', 0, 10);
    videos_config_add_value($c, 'public_title', 'text', 0, 20);
    videos_config_add_value($c, 'language', 'text', 0, 30);
    videos_config_add_value($c, 'region', 'text', 0, 40);
    videos_config_add_value($c, 'videos_per_page', 'text', 0, 50);
    videos_config_add_value($c, 'suggestion_count', 'text', 0, 60);
    videos_config_add_value($c, 'sharing_enabled', 'select', 0, 70);
    videos_config_add_value($c, 'ratings_enabled', 'select', 0, 80);
    videos_config_add_value($c, 'view_tracking_enabled', 'select', 0, 90);

    videos_config_add_tab($c, 'youtube', 10);
    videos_config_add_value($c, 'youtube_daily_search_limit', 'text', 10, 10);
    videos_config_add_value($c, 'youtube_timeout', 'text', 10, 20);
    videos_config_add_value($c, 'search_cache_ttl', 'text', 10, 30);
    videos_config_add_value($c, 'video_cache_ttl', 'text', 10, 40);
    videos_config_add_value($c, 'channel_cache_ttl', 'text', 10, 50);
    videos_config_add_value($c, 'availability_cache_ttl', 'text', 10, 55);
    videos_config_add_value($c, 'youtube_max_results', 'text', 10, 60);
    videos_config_add_value($c, 'youtube_safe_search', 'text', 10, 70);
    videos_config_add_value($c, 'discovery_enabled', 'select', 10, 80);
    videos_config_add_value($c, 'discovery_reservoir_size', 'text', 10, 90);
    videos_config_add_value($c, 'catalogue_max_videos', 'text', 10, 100);
    videos_config_add_value($c, 'discovery_seed_searches', 'text', 10, 110);
    videos_config_add_value($c, 'discovery_refresh_interval', 'text', 10, 120);
    videos_config_add_value($c, 'discovery_refresh_percentage', 'text', 10, 130);
    videos_config_add_value($c, 'discovery_recent_percentage', 'text', 10, 140);
    videos_config_add_value($c, 'discovery_recent_months', 'text', 10, 150);

    videos_config_add_tab($c, 'analysis', 20);
    videos_config_add_value($c, 'analysis_mode', 'text', 20, 10);
    videos_config_add_value($c, 'manual_keywords', 'text', 20, 20);
    videos_config_add_value($c, 'required_keywords', 'text', 20, 30);
    videos_config_add_value($c, 'excluded_keywords', 'text', 20, 40);
    videos_config_add_value($c, 'additional_stop_words', 'text', 20, 50);
    videos_config_add_value($c, 'max_keywords', 'text', 20, 60);
    videos_config_add_value($c, 'title_weight', 'text', 20, 70);
    videos_config_add_value($c, 'meta_weight', 'text', 20, 80);
    videos_config_add_value($c, 'content_weight', 'text', 20, 90);

    videos_config_add_tab($c, 'sources', 30);
    videos_config_add_value($c, 'allowed_channels', 'text', 30, 10);
    videos_config_add_value($c, 'priority_channels', 'text', 30, 20);
    videos_config_add_value($c, 'blocked_channels', 'text', 30, 30);
    videos_config_add_value($c, 'blocked_videos', 'text', 30, 40);
    videos_config_add_value($c, 'exclude_short_videos', 'select', 30, 42);
    videos_config_add_value($c, 'short_filter_mode', 'select', 30, 43, 4);
    videos_config_add_value($c, 'short_max_duration', 'text', 30, 44);
    videos_config_add_value($c, 'privacy_enhanced_embed', 'select', 30, 50);
    videos_config_add_value($c, 'autoplay', 'select', 30, 60);
    videos_config_add_value($c, 'description_mode', 'select', 30, 70, 1);
    videos_config_add_value($c, 'youtube_player_mode', 'select', 30, 80, 2);

    videos_config_add_tab($c, 'ranking', 40);
    videos_config_add_value($c, 'rating_threshold_seconds', 'text', 40, 10);
    videos_config_add_value($c, 'view_threshold_seconds', 'text', 40, 20);
    videos_config_add_value($c, 'view_threshold_percent', 'text', 40, 30);
    videos_config_add_value($c, 'ranking_rebuild_interval', 'text', 40, 40);
    videos_config_add_value($c, 'max_same_channel', 'text', 40, 50);
    videos_config_add_value($c, 'permanent_pool_enabled', 'select', 40, 51);
    videos_config_add_value($c, 'permanent_pool_size', 'text', 40, 52);
    videos_config_add_value($c, 'permanent_pool_percentage', 'text', 40, 53);
    videos_config_add_value($c, 'permanent_pool_auto', 'select', 40, 54);
    videos_config_add_value($c, 'permanent_pool_min_ratings', 'text', 40, 55);
    videos_config_add_value(
        $c,
        'permanent_pool_min_weighted_rating',
        'text',
        40,
        56
    );
    videos_config_add_value(
        $c,
        'permanent_pool_keep_below_threshold',
        'select',
        40,
        57
    );
    videos_config_add_value($c, 'public_rankings_enabled', 'select', 40, 60);
    videos_config_add_value(
        $c,
        'public_video_ranking_enabled',
        'select',
        40,
        70
    );
    videos_config_add_value(
        $c,
        'public_channel_ranking_enabled',
        'select',
        40,
        80
    );
    videos_config_add_value($c, 'public_ranking_limit', 'text', 40, 90);

    videos_config_add_tab($c, 'privacy', 50);
    videos_config_add_value($c, 'account_history_enabled', 'select', 50, 10);
    videos_config_add_value($c, 'account_recommendations_enabled', 'select', 50, 20);
    videos_config_add_value($c, 'anonymous_tracking_enabled', 'select', 50, 30);
    videos_config_add_value($c, 'allow_anonymous_merge', 'select', 50, 40);
    videos_config_add_value($c, 'allow_user_export', 'select', 50, 50);
    videos_config_add_value($c, 'allow_user_deletion', 'select', 50, 60);
    videos_config_add_value($c, 'account_retention_days', 'text', 50, 70);
    videos_config_add_value($c, 'automatic_account_purge', 'select', 50, 80);
    videos_config_add_value($c, 'anonymous_retention_days', 'text', 50, 90);
    videos_config_add_value($c, 'privacy_notice', 'text', 50, 100);

    videos_config_add_tab($c, 'block', 60);
    videos_config_add_value($c, 'block_enabled', 'select', 60, 10);
    videos_config_add_value($c, 'block_isleft', 'select', 60, 20);
    videos_config_add_value($c, 'block_order', 'text', 60, 30);
    videos_config_add_value($c, 'block_mode', 'select', 60, 40, 3);
    videos_config_add_value($c, 'block_item_count', 'text', 60, 50);

    videos_config_add_tab($c, 'seo', 80);
    videos_config_add_value($c, 'seo_enabled', 'select', 80, 10);
    videos_config_add_value($c, 'seo_catalogue_index', 'select', 80, 20);
    videos_config_add_value($c, 'seo_rankings_index', 'select', 80, 30);
    videos_config_add_value($c, 'seo_structured_data', 'select', 80, 40);
    videos_config_add_value($c, 'seo_social_metadata', 'select', 80, 50);
    videos_config_add_value(
        $c,
        'seo_description_fallback',
        'text',
        80,
        60
    );
    videos_config_add_value($c, 'faq_catalogue_enabled', 'select', 80, 70);
    videos_config_add_value($c, 'faq_video_enabled', 'select', 80, 80);
    videos_config_add_value($c, 'faq_rankings_enabled', 'select', 80, 90);
    videos_config_add_value($c, 'faq_structured_data', 'select', 80, 100);

    videos_config_add_tab($c, 'maintenance', 70);
    videos_config_add_value($c, 'technical_log_days', 'text', 70, 10);

    if (!videos_config_verify_defaults($c)) {
        return false;
    }
    return !$recoverIncompleteInstallation ||
        videos_config_recover_installation_defaults($c);
}

function videos_config_schema()
{
    return array(
        'tabs' => array(
            'general' => 0,
            'youtube' => 10,
            'analysis' => 20,
            'sources' => 30,
            'ranking' => 40,
            'privacy' => 50,
            'block' => 60,
            'maintenance' => 70,
            'seo' => 80
        ),
        'values' => array(
            array('enabled', 'select', 0, 10, 0),
            array('public_title', 'text', 0, 20, 0),
            array('language', 'text', 0, 30, 0),
            array('region', 'text', 0, 40, 0),
            array('videos_per_page', 'text', 0, 50, 0),
            array('suggestion_count', 'text', 0, 60, 0),
            array('sharing_enabled', 'select', 0, 70, 0),
            array('ratings_enabled', 'select', 0, 80, 0),
            array('view_tracking_enabled', 'select', 0, 90, 0),
            array('youtube_daily_search_limit', 'text', 10, 10, 0),
            array('youtube_timeout', 'text', 10, 20, 0),
            array('search_cache_ttl', 'text', 10, 30, 0),
            array('video_cache_ttl', 'text', 10, 40, 0),
            array('channel_cache_ttl', 'text', 10, 50, 0),
            array('availability_cache_ttl', 'text', 10, 55, 0),
            array('youtube_max_results', 'text', 10, 60, 0),
            array('youtube_safe_search', 'text', 10, 70, 0),
            array('discovery_enabled', 'select', 10, 80, 0),
            array('discovery_reservoir_size', 'text', 10, 90, 0),
            array('catalogue_max_videos', 'text', 10, 100, 0),
            array('discovery_seed_searches', 'text', 10, 110, 0),
            array('discovery_refresh_interval', 'text', 10, 120, 0),
            array('discovery_refresh_percentage', 'text', 10, 130, 0),
            array('discovery_recent_percentage', 'text', 10, 140, 0),
            array('discovery_recent_months', 'text', 10, 150, 0),
            array('analysis_mode', 'text', 20, 10, 0),
            array('manual_keywords', 'text', 20, 20, 0),
            array('required_keywords', 'text', 20, 30, 0),
            array('excluded_keywords', 'text', 20, 40, 0),
            array('additional_stop_words', 'text', 20, 50, 0),
            array('max_keywords', 'text', 20, 60, 0),
            array('title_weight', 'text', 20, 70, 0),
            array('meta_weight', 'text', 20, 80, 0),
            array('content_weight', 'text', 20, 90, 0),
            array('allowed_channels', 'text', 30, 10, 0),
            array('priority_channels', 'text', 30, 20, 0),
            array('blocked_channels', 'text', 30, 30, 0),
            array('blocked_videos', 'text', 30, 40, 0),
            array('exclude_short_videos', 'select', 30, 42, 0),
            array('short_filter_mode', 'select', 30, 43, 4),
            array('short_max_duration', 'text', 30, 44, 0),
            array('privacy_enhanced_embed', 'select', 30, 50, 0),
            array('autoplay', 'select', 30, 60, 0),
            array('description_mode', 'select', 30, 70, 1),
            array('youtube_player_mode', 'select', 30, 80, 2),
            array('rating_threshold_seconds', 'text', 40, 10, 0),
            array('view_threshold_seconds', 'text', 40, 20, 0),
            array('view_threshold_percent', 'text', 40, 30, 0),
            array('ranking_rebuild_interval', 'text', 40, 40, 0),
            array('max_same_channel', 'text', 40, 50, 0),
            array('permanent_pool_enabled', 'select', 40, 51, 0),
            array('permanent_pool_size', 'text', 40, 52, 0),
            array('permanent_pool_percentage', 'text', 40, 53, 0),
            array('permanent_pool_auto', 'select', 40, 54, 0),
            array('permanent_pool_min_ratings', 'text', 40, 55, 0),
            array('permanent_pool_min_weighted_rating', 'text', 40, 56, 0),
            array('permanent_pool_keep_below_threshold', 'select', 40, 57, 0),
            array('public_rankings_enabled', 'select', 40, 60, 0),
            array('public_video_ranking_enabled', 'select', 40, 70, 0),
            array('public_channel_ranking_enabled', 'select', 40, 80, 0),
            array('public_ranking_limit', 'text', 40, 90, 0),
            array('account_history_enabled', 'select', 50, 10, 0),
            array('account_recommendations_enabled', 'select', 50, 20, 0),
            array('anonymous_tracking_enabled', 'select', 50, 30, 0),
            array('allow_anonymous_merge', 'select', 50, 40, 0),
            array('allow_user_export', 'select', 50, 50, 0),
            array('allow_user_deletion', 'select', 50, 60, 0),
            array('account_retention_days', 'text', 50, 70, 0),
            array('automatic_account_purge', 'select', 50, 80, 0),
            array('anonymous_retention_days', 'text', 50, 90, 0),
            array('privacy_notice', 'text', 50, 100, 0),
            array('block_enabled', 'select', 60, 10, 0),
            array('block_isleft', 'select', 60, 20, 0),
            array('block_order', 'text', 60, 30, 0),
            array('block_mode', 'select', 60, 40, 3),
            array('block_item_count', 'text', 60, 50, 0),
            array('technical_log_days', 'text', 70, 10, 0),
            array('seo_enabled', 'select', 80, 10, 0),
            array('seo_catalogue_index', 'select', 80, 20, 0),
            array('seo_rankings_index', 'select', 80, 30, 0),
            array('seo_structured_data', 'select', 80, 40, 0),
            array('seo_social_metadata', 'select', 80, 50, 0),
            array('seo_description_fallback', 'text', 80, 60, 0),
            array('faq_catalogue_enabled', 'select', 80, 70, 0),
            array('faq_video_enabled', 'select', 80, 80, 0),
            array('faq_rankings_enabled', 'select', 80, 90, 0),
            array('faq_structured_data', 'select', 80, 100, 0)
        )
    );
}

function videos_config_apply_missing_defaults($c)
{
    global $_VID_DEFAULT;

    if (!isset($_VID_DEFAULT) || !is_array($_VID_DEFAULT)) {
        return false;
    }
    $schema = videos_config_schema();
    if (!videos_config_entry_exists('sg_main')) {
        $c->add(
            'sg_main',
            null,
            'subgroup',
            0,
            0,
            null,
            0,
            true,
            'videos',
            0
        );
        if (function_exists('DB_error') && DB_error()) {
            return false;
        }
    }
    foreach ($schema['tabs'] as $name => $fieldset) {
        if (!videos_config_entry_exists('tab_' . $name)) {
            videos_config_add_structure(
                $c,
                'tab_' . $name,
                'tab',
                $fieldset
            );
            if (function_exists('DB_error') && DB_error()) {
                return false;
            }
        }
        if (!videos_config_entry_exists('fs_' . $name)) {
            videos_config_add_structure(
                $c,
                'fs_' . $name,
                'fieldset',
                $fieldset
            );
            if (function_exists('DB_error') && DB_error()) {
                return false;
            }
        }
    }
    foreach ($schema['values'] as $definition) {
        if (videos_config_entry_exists($definition[0])) {
            continue;
        }
        videos_config_add_value(
            $c,
            $definition[0],
            $definition[1],
            $definition[2],
            $definition[3],
            $definition[4]
        );
        if (function_exists('DB_error') && DB_error()) {
            return false;
        }
    }
    return videos_config_verify_defaults($c);
}

function videos_config_add_structure($c, $name, $type, $fieldset)
{
    $c->add(
        $name,
        null,
        $type,
        0,
        $fieldset,
        null,
        0,
        true,
        'videos',
        $fieldset
    );
}

function videos_config_verify_defaults($c)
{
    global $_VID_DEFAULT;

    if (!isset($_VID_DEFAULT) || !is_array($_VID_DEFAULT)) {
        return false;
    }
    $stored = $c->get_config('videos');
    if (!is_array($stored)) {
        return false;
    }
    foreach ($_VID_DEFAULT as $name => $value) {
        if (!array_key_exists($name, $stored)) {
            return false;
        }
    }
    return true;
}

function videos_config_recover_installation_defaults($c)
{
    global $_TABLES, $_VID_DEFAULT;

    if (!isset($_VID_DEFAULT) || !is_array($_VID_DEFAULT)) {
        return false;
    }
    if ((int) DB_count($_TABLES['plugins'], 'pi_name', 'videos') > 0) {
        return videos_config_verify_defaults($c);
    }
    $stored = $c->get_config('videos');
    if (!is_array($stored)) {
        return false;
    }
    foreach ($_VID_DEFAULT as $name => $defaultValue) {
        if ($defaultValue === '' || !array_key_exists($name, $stored)) {
            continue;
        }
        if ($stored[$name] === '' || $stored[$name] === null) {
            $c->set($name, $defaultValue, 'videos');
            $c->set_default($name, $defaultValue, 'videos');
            if (function_exists('DB_error') && DB_error()) {
                return false;
            }
        }
    }
    return videos_config_verify_defaults($c);
}

function videos_config_entry_exists($name)
{
    global $_TABLES;

    $escapedName = DB_escapeString($name);
    $escapedGroup = DB_escapeString('videos');
    $count = DB_getItem(
        $_TABLES['conf_values'],
        'COUNT(*)',
        "name = '$escapedName' AND group_name = '$escapedGroup'"
    );
    return (int) $count > 0;
}

function videos_config_add_tab($c, $name, $fieldset)
{
    $c->add(
        'tab_' . $name,
        null,
        'tab',
        0,
        $fieldset,
        null,
        0,
        true,
        'videos',
        $fieldset
    );
    $c->add(
        'fs_' . $name,
        null,
        'fieldset',
        0,
        $fieldset,
        null,
        0,
        true,
        'videos',
        $fieldset
    );
}

function videos_config_add_value(
    $c,
    $name,
    $type,
    $fieldset,
    $order,
    $selection = 0
)
{
    global $_VID_DEFAULT;

    if (!isset($_VID_DEFAULT) || !is_array($_VID_DEFAULT) ||
        !array_key_exists($name, $_VID_DEFAULT)) {
        return false;
    }

    $c->add(
        $name,
        $_VID_DEFAULT[$name],
        $type,
        0,
        $fieldset,
        $selection,
        $order,
        true,
        'videos',
        $fieldset
    );
}
