<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

/*
 * Each entry describes exactly one supported transition.
 *
 * Example for a future release:
 *
 * $VIDEOS_UPDATES['0.1.0'] = array(
 *     'next' => '0.2.0',
 *     'callback' => 'videos_update_0_1_0_to_0_2_0'
 * );
 */
$GLOBALS['VIDEOS_UPDATES'] = array(
    '0.1.0' => array('next' => '0.1.1', 'callback' => ''),
    '0.1.1' => array('next' => '0.1.2', 'callback' => ''),
    '0.1.2' => array('next' => '0.1.3', 'callback' => ''),
    '0.1.3' => array('next' => '0.1.4', 'callback' => ''),
    '0.1.4' => array('next' => '0.1.5', 'callback' => ''),
    '0.1.5' => array('next' => '0.1.6', 'callback' => ''),
    '0.1.6' => array('next' => '0.2.0', 'callback' => ''),
    '0.2.0' => array('next' => '0.2.1', 'callback' => ''),
    '0.2.1' => array('next' => '0.2.2', 'callback' => ''),
    '0.2.2' => array('next' => '0.2.3', 'callback' => ''),
    '0.2.3' => array('next' => '0.2.4', 'callback' => ''),
    '0.2.4' => array('next' => '0.2.5', 'callback' => ''),
    '0.2.5' => array('next' => '0.2.6', 'callback' => ''),
    '0.2.6' => array('next' => '0.2.7', 'callback' => ''),
    '0.2.7' => array('next' => '0.3.0', 'callback' => ''),
    '0.3.0' => array('next' => '0.3.1', 'callback' => ''),
    '0.3.1' => array('next' => '0.3.2', 'callback' => ''),
    '0.3.2' => array('next' => '0.4.0', 'callback' => ''),
    '0.4.0' => array('next' => '0.4.1', 'callback' => ''),
    '0.4.1' => array('next' => '0.5.0', 'callback' => ''),
    '0.5.0' => array('next' => '0.5.1', 'callback' => ''),
    '0.5.1' => array('next' => '0.5.2', 'callback' => ''),
    '0.5.2' => array('next' => '0.5.3', 'callback' => ''),
    '0.5.3' => array('next' => '0.6.0', 'callback' => ''),
    '0.6.0' => array('next' => '0.6.1', 'callback' => 'videos_update_0_6_0_to_0_6_1'),
    '0.6.1' => array('next' => '0.6.2', 'callback' => ''),
    '0.6.2' => array('next' => '0.6.3', 'callback' => ''),
    '0.6.3' => array('next' => '0.6.4', 'callback' => 'videos_update_0_6_3_to_0_6_4'),
    '0.6.4' => array('next' => '0.7.0', 'callback' => ''),
    '0.7.0' => array('next' => '0.7.1', 'callback' => ''),
    '0.7.1' => array('next' => '0.8.0', 'callback' => 'videos_update_0_7_1_to_0_8_0'),
    '0.8.0' => array('next' => '0.8.1', 'callback' => ''),
    '0.8.1' => array('next' => '0.9.0', 'callback' => ''),
    '0.9.0' => array('next' => '0.10.0', 'callback' => 'videos_update_0_9_0_to_0_10_0'),
    '0.10.0' => array('next' => '0.10.1', 'callback' => 'videos_update_0_10_0_to_0_10_1'),
    '0.10.1' => array('next' => '0.10.2', 'callback' => ''),
    '0.10.2' => array('next' => '0.10.3', 'callback' => ''),
    '0.10.3' => array('next' => '0.10.4', 'callback' => ''),
    '0.10.4' => array('next' => '0.10.5', 'callback' => ''),
    '0.10.5' => array('next' => '0.11.0', 'callback' => 'videos_update_0_10_5_to_0_11_0'),
    '0.11.0' => array('next' => '0.12.0', 'callback' => ''),
    '0.12.0' => array('next' => '0.12.1', 'callback' => ''),
    '0.12.1' => array('next' => '0.12.2', 'callback' => ''),
    '0.12.2' => array('next' => '0.13.0', 'callback' => ''),
    '0.13.0' => array('next' => '0.14.0', 'callback' => 'videos_update_0_13_0_to_0_14_0'),
    '0.14.0' => array('next' => '0.14.1', 'callback' => 'videos_update_0_14_0_to_0_14_1'),
    '0.14.1' => array('next' => '0.15.0', 'callback' => 'videos_update_0_14_1_to_0_15_0'),
    '0.15.0' => array('next' => '0.15.1', 'callback' => ''),
    '0.15.1' => array('next' => '0.16.0', 'callback' => 'videos_update_0_15_1_to_0_16_0'),
    '0.16.0' => array('next' => '0.16.1', 'callback' => 'videos_update_0_16_0_to_0_16_1'),
    '0.16.1' => array('next' => '0.16.2', 'callback' => 'videos_update_0_16_1_to_0_16_2'),
    '0.16.2' => array('next' => '0.16.3', 'callback' => ''),
    '0.16.3' => array('next' => '0.16.4', 'callback' => 'videos_update_0_16_3_to_0_16_4'),
    '0.16.4' => array('next' => '0.16.5', 'callback' => 'videos_update_0_16_4_to_0_16_5'),
    '0.16.5' => array('next' => '0.17.0', 'callback' => 'videos_update_0_16_5_to_0_17_0'),
    '0.17.0' => array('next' => '0.17.1', 'callback' => 'videos_update_0_17_0_to_0_17_1'),
    '0.17.1' => array('next' => '0.18.0', 'callback' => 'videos_update_0_17_1_to_0_18_0')
);

function videos_update_0_17_1_to_0_18_0()
{
    return true;
}

function videos_update_0_17_0_to_0_17_1()
{
    return true;
}

function videos_update_0_16_5_to_0_17_0()
{
    global $_CONF, $_VID_DEFAULT;
    require_once $_CONF['path'] . 'plugins/videos/install_defaults.php';
    if (!isset($_VID_DEFAULT) || !is_array($_VID_DEFAULT)) {
        $_VID_DEFAULT = videos_default_configuration();
        $GLOBALS['_VID_DEFAULT'] = $_VID_DEFAULT;
    }
    return plugin_initconfig_videos(false);
}

function videos_update_0_16_4_to_0_16_5()
{
    global $_CONF, $_VID_DEFAULT;
    require_once $_CONF['path'] . 'plugins/videos/install_defaults.php';
    if (!isset($_VID_DEFAULT) || !is_array($_VID_DEFAULT)) {
        $_VID_DEFAULT = videos_default_configuration();
        $GLOBALS['_VID_DEFAULT'] = $_VID_DEFAULT;
    }
    return plugin_initconfig_videos(false);
}

function videos_update_0_16_3_to_0_16_4()
{
    global $_CONF;
    require_once $_CONF['path'] . 'plugins/videos/install_defaults.php';
    return plugin_initconfig_videos(false);
}

function videos_update_0_16_1_to_0_16_2()
{
    global $_CONF;
    require_once $_CONF['path'] . 'plugins/videos/install_defaults.php';
    return plugin_initconfig_videos();
}

function videos_update_0_16_0_to_0_16_1()
{
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (!is_array($current)) {
        return false;
    }
    $values = array(
        array('faq_catalogue_enabled', 1, 70),
        array('faq_video_enabled', 1, 80),
        array('faq_rankings_enabled', 1, 90),
        array('faq_structured_data', 0, 100)
    );
    foreach ($values as $definition) {
        if (array_key_exists($definition[0], $current)) {
            continue;
        }
        $configuration->add($definition[0], $definition[1], 'select', 0, 80, 0, $definition[2], true, 'videos', 80);
        if (function_exists('DB_error') && DB_error()) {
            return false;
        }
        $current = $configuration->get_config('videos');
    }
    foreach ($values as $definition) {
        if (!array_key_exists($definition[0], $current)) {
            return false;
        }
    }
    return true;
}

function videos_update_0_15_1_to_0_16_0()
{
    global $_TABLES;
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (!is_array($current)) {
        return false;
    }
    $group = DB_escapeString('videos');
    $tabName = DB_escapeString('tab_seo');
    $tabExists = DB_getItem($_TABLES['conf_values'], 'COUNT(*)', "name = '$tabName' AND group_name = '$group'");
    if ((int) $tabExists === 0) {
        $configuration->add('tab_seo', null, 'tab', 0, 80, null, 0, true, 'videos', 80);
        if (function_exists('DB_error') && DB_error()) {
            return false;
        }
    }
    $fieldsetName = DB_escapeString('fs_seo');
    $fieldsetExists = DB_getItem($_TABLES['conf_values'], 'COUNT(*)', "name = '$fieldsetName' AND group_name = '$group'");
    if ((int) $fieldsetExists === 0) {
        $configuration->add('fs_seo', null, 'fieldset', 0, 80, null, 0, true, 'videos', 80);
        if (function_exists('DB_error') && DB_error()) {
            return false;
        }
    }
    $values = array(
        array('seo_enabled', 1, 'select', 10),
        array('seo_catalogue_index', 1, 'select', 20),
        array('seo_rankings_index', 1, 'select', 30),
        array('seo_structured_data', 1, 'select', 40),
        array('seo_social_metadata', 1, 'select', 50),
        array('seo_description_fallback', '', 'text', 60)
    );
    foreach ($values as $definition) {
        if (array_key_exists($definition[0], $current)) {
            continue;
        }
        $configuration->add($definition[0], $definition[1], $definition[2], 0, 80, 0, $definition[3], true, 'videos', 80);
        if (function_exists('DB_error') && DB_error()) {
            return false;
        }
        $current = $configuration->get_config('videos');
    }
    $featureName = 'config.videos.tab_seo';
    $escapedFeature = DB_escapeString($featureName);
    $featureId = DB_getItem($_TABLES['features'], 'ft_id', "ft_name = '$escapedFeature'");
    if (empty($featureId)) {
        DB_query("INSERT INTO {$_TABLES['features']} (ft_name, ft_descr) VALUES ('" . $escapedFeature . "', 'Configure public-page SEO')");
        if (DB_error()) {
            return false;
        }
        $featureId = DB_getItem($_TABLES['features'], 'ft_id', "ft_name = '$escapedFeature'");
    }
    $groupId = DB_getItem($_TABLES['groups'], 'grp_id', "grp_name = '" . DB_escapeString('Videos Admin') . "'");
    if (empty($featureId) || empty($groupId)) {
        return false;
    }
    $featureId = (int) $featureId;
    $groupId = (int) $groupId;
    $access = DB_query("SELECT acc_ft_id FROM {$_TABLES['access']} WHERE acc_ft_id = $featureId AND acc_grp_id = $groupId");
    if (DB_error()) {
        return false;
    }
    if (DB_numRows($access) === 0) {
        DB_query("INSERT INTO {$_TABLES['access']} (acc_ft_id, acc_grp_id) VALUES ($featureId, $groupId)");
        if (DB_error()) {
            return false;
        }
    }
    foreach ($values as $definition) {
        if (!array_key_exists($definition[0], $current)) {
            return false;
        }
    }
    return true;
}

function videos_update_0_14_1_to_0_15_0()
{
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (!is_array($current)) {
        return false;
    }
    $values = array(
        array('permanent_pool_enabled', 1, 'select', 0, 51),
        array('permanent_pool_size', 24, 'text', 0, 52),
        array('permanent_pool_percentage', 25, 'text', 0, 53),
        array('permanent_pool_auto', 1, 'select', 0, 54),
        array('permanent_pool_min_ratings', 3, 'text', 0, 55),
        array('permanent_pool_min_weighted_rating', '4.0', 'text', 0, 56),
        array('permanent_pool_keep_below_threshold', 1, 'select', 0, 57)
    );
    foreach ($values as $value) {
        if (array_key_exists($value[0], $current)) {
            continue;
        }
        $configuration->add($value[0], $value[1], $value[2], 0, 40, $value[3], $value[4], true, 'videos', 40);
    }
    if (function_exists('DB_error') && DB_error()) {
        return false;
    }
    $updated = $configuration->get_config('videos');
    if (!is_array($updated)) {
        return false;
    }
    foreach ($values as $value) {
        if (!array_key_exists($value[0], $updated)) {
            return false;
        }
    }
    return true;
}

function videos_update_0_14_0_to_0_14_1()
{
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (is_array($current) && array_key_exists('short_filter_mode', $current)) {
        return true;
    }
    $configuration->add('short_filter_mode', 'probable', 'select', 0, 30, 4, 43, true, 'videos', 30);
    if (function_exists('DB_error') && DB_error()) {
        return false;
    }
    $updated = $configuration->get_config('videos');
    return is_array($updated) && array_key_exists('short_filter_mode', $updated);
}

function videos_update_0_13_0_to_0_14_0()
{
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (!is_array($current)) {
        return false;
    }
    if (!array_key_exists('exclude_short_videos', $current)) {
        $configuration->add('exclude_short_videos', 0, 'select', 0, 30, 0, 42, true, 'videos', 30);
    }
    if (!array_key_exists('short_max_duration', $current)) {
        $configuration->add('short_max_duration', 180, 'text', 0, 30, 0, 44, true, 'videos', 30);
    }
    if (function_exists('DB_error') && DB_error()) {
        return false;
    }
    $updated = $configuration->get_config('videos');
    return is_array($updated) && array_key_exists('exclude_short_videos', $updated) && array_key_exists('short_max_duration', $updated);
}

function videos_update_0_10_5_to_0_11_0()
{
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (is_array($current) && array_key_exists('availability_cache_ttl', $current)) {
        return true;
    }
    $configuration->add('availability_cache_ttl', 86400, 'text', 0, 10, 0, 55, true, 'videos', 10);
    if (function_exists('DB_error') && DB_error()) {
        return false;
    }
    $updated = $configuration->get_config('videos');
    return is_array($updated) && array_key_exists('availability_cache_ttl', $updated);
}

function videos_update_0_10_0_to_0_10_1()
{
    global $_TABLES;
    $name = DB_escapeString('block_mode');
    $group = DB_escapeString('videos');
    DB_query("UPDATE {$_TABLES['conf_values']} SET type = 'select', selectionArray = 3 WHERE name = '$name' AND group_name = '$group'");
    if (DB_error()) {
        return false;
    }
    $result = DB_query("SELECT type, selectionArray FROM {$_TABLES['conf_values']} WHERE name = '$name' AND group_name = '$group'");
    if (DB_error() || DB_numRows($result) !== 1) {
        return false;
    }
    $row = DB_fetchArray($result);
    return isset($row['type'], $row['selectionArray']) && $row['type'] === 'select' && (int) $row['selectionArray'] === 3;
}

function videos_update_0_9_0_to_0_10_0()
{
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (is_array($current) && array_key_exists('block_item_count', $current)) {
        return true;
    }
    $configuration->add('block_item_count', 3, 'text', 0, 60, 0, 50, true, 'videos', 60);
    if (function_exists('DB_error') && DB_error()) {
        return false;
    }
    $updated = $configuration->get_config('videos');
    return is_array($updated) && array_key_exists('block_item_count', $updated);
}

function videos_update_0_7_1_to_0_8_0()
{
    $configuration = config::get_instance();
    $values = array(
        'public_rankings_enabled' => array(1, 'select', 0, 40, 0, 60),
        'public_video_ranking_enabled' => array(1, 'select', 0, 40, 0, 70),
        'public_channel_ranking_enabled' => array(1, 'select', 0, 40, 0, 80),
        'public_ranking_limit' => array(10, 'text', 0, 40, 0, 90)
    );
    $current = $configuration->get_config('videos');
    foreach ($values as $name => $definition) {
        if (is_array($current) && array_key_exists($name, $current)) {
            continue;
        }
        $configuration->add($name, $definition[0], $definition[1], $definition[2], $definition[3], $definition[4], $definition[5], true, 'videos', 40);
        if (function_exists('DB_error') && DB_error()) {
            return false;
        }
        $current = $configuration->get_config('videos');
    }
    foreach ($values as $name => $definition) {
        if (!is_array($current) || !array_key_exists($name, $current)) {
            return false;
        }
    }
    return true;
}

function videos_update_0_6_3_to_0_6_4()
{
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (is_array($current) && array_key_exists('youtube_player_mode', $current)) {
        return true;
    }
    $configuration->add('youtube_player_mode', 'standard', 'select', 0, 30, 2, 80, true, 'videos', 30);
    if (function_exists('DB_error') && DB_error()) {
        return false;
    }
    $updated = $configuration->get_config('videos');
    return is_array($updated) && array_key_exists('youtube_player_mode', $updated);
}

function videos_update_0_6_0_to_0_6_1()
{
    $configuration = config::get_instance();
    $current = $configuration->get_config('videos');
    if (is_array($current) && array_key_exists('description_mode', $current)) {
        return true;
    }
    $configuration->add('description_mode', 'clean', 'select', 0, 30, 1, 70, true, 'videos', 30);
    if (function_exists('DB_error') && DB_error()) {
        return false;
    }
    $updated = $configuration->get_config('videos');
    return is_array($updated) && array_key_exists('description_mode', $updated);
}

function videos_apply_updates($installedVersion, $targetVersion)
{
    $updates = isset($GLOBALS['VIDEOS_UPDATES']) && is_array($GLOBALS['VIDEOS_UPDATES'])
        ? $GLOBALS['VIDEOS_UPDATES'] : array();
    if ($installedVersion === $targetVersion) {
        return true;
    }
    if (version_compare($installedVersion, $targetVersion, '>')) {
        return false;
    }
    $currentVersion = $installedVersion;
    $visited = array();
    while ($currentVersion !== $targetVersion) {
        if (isset($visited[$currentVersion]) || !isset($updates[$currentVersion])) {
            return false;
        }
        $visited[$currentVersion] = true;
        $step = $updates[$currentVersion];
        if (!isset($step['next']) || !is_string($step['next']) || version_compare($step['next'], $currentVersion, '<=')) {
            return false;
        }
        if (version_compare($step['next'], $targetVersion, '>')) {
            return false;
        }
        if (!empty($step['callback'])) {
            if (!is_string($step['callback']) || !function_exists($step['callback']) || call_user_func($step['callback']) !== true) {
                return false;
            }
        }
        $currentVersion = $step['next'];
    }
    return true;
}
