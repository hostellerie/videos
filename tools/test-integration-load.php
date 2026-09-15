<?php

$_CONF = array(
    'path' => dirname(__DIR__) . '/',
    'site_url' => 'https://example.invalid',
    'language' => 'english',
    'locale' => 'en_US.UTF-8',
);

require_once $_CONF['path'] . 'autoload.php';
VIDEOS_registerAutoloader();
require_once $_CONF['path'] . 'geeklog_integration.php';
require_once $_CONF['path'] . 'interoperability.php';
require_once $_CONF['path'] . 'feed_update.php';
require_once $_CONF['path'] . 'install_defaults.php';

$requiredCallbacks = array(
    'plugin_searchtypes_videos',
    'plugin_dopluginsearch_videos',
    'plugin_statssummary_videos',
    'plugin_getiteminfo_videos',
    'plugin_idtourl_videos',
    'plugin_urltoid_videos',
    'plugin_getfeednames_videos',
    'plugin_getfeedcontent_videos',
    'plugin_feedupdatecheck_videos',
    'plugin_autotags_videos',
);

foreach ($requiredCallbacks as $callback) {
    if (!function_exists($callback)) {
        fwrite(STDERR, 'Missing Videos callback: ' . $callback . PHP_EOL);
        exit(1);
    }
}

$videoId = 'H5nzrlARuCo';
$channelId = 'UC1234567890123456789012';
$urlCases = array(
    $videoId => array(
        'url' => 'https://example.invalid/videos/watch.php?v=' . $videoId,
        'subtype' => 'video'
    ),
    'channel:' . $channelId => array(
        'url' => 'https://example.invalid/videos/channel.php?id=' . $channelId,
        'subtype' => 'channel'
    ),
    'catalogue' => array(
        'url' => 'https://example.invalid/videos/index.php',
        'subtype' => 'collection'
    ),
    'channels' => array(
        'url' => 'https://example.invalid/videos/channels.php',
        'subtype' => 'collection'
    ),
    'rankings:videos' => array(
        'url' => 'https://example.invalid/videos/rankings.php?tab=videos',
        'subtype' => 'ranking'
    ),
    'rankings:channels' => array(
        'url' => 'https://example.invalid/videos/rankings.php?tab=channels',
        'subtype' => 'ranking'
    )
);
foreach ($urlCases as $id => $expected) {
    $url = plugin_idtourl_videos('', $id);
    if ($url !== $expected['url']) {
        fwrite(
            STDERR,
            'Unexpected Videos URL for ' . $id . ': ' . $url . PHP_EOL
        );
        exit(1);
    }
    $resolved = plugin_urltoid_videos($url);
    if (!is_array($resolved) ||
        !isset($resolved['type'], $resolved['id'], $resolved['subtype']) ||
        $resolved['type'] !== 'videos' ||
        $resolved['id'] !== $id ||
        $resolved['subtype'] !== $expected['subtype']) {
        fwrite(STDERR, 'Videos URL round-trip failed for: ' . $id . PHP_EOL);
        exit(1);
    }
}
if (plugin_idtourl_videos('', 'not-a-video-id') !== '') {
    fwrite(STDERR, 'Invalid Videos item ID unexpectedly produced a URL.' . PHP_EOL);
    exit(1);
}
if (plugin_urltoid_videos(
    'https://outside.invalid/videos/watch.php?v=' . $videoId
) !== array()) {
    fwrite(STDERR, 'External host unexpectedly resolved as a Videos item.' . PHP_EOL);
    exit(1);
}

$schema = videos_config_schema();
$defaults = videos_default_configuration();
if (!isset($schema['tabs'], $schema['values']) ||
    !is_array($schema['tabs']) || !is_array($schema['values'])) {
    fwrite(STDERR, 'Invalid Videos configuration schema.' . PHP_EOL);
    exit(1);
}

$schemaNames = array();
$orders = array();
foreach ($schema['values'] as $definition) {
    if (!is_array($definition) || count($definition) < 4) {
        fwrite(STDERR, 'Invalid Videos configuration definition.' . PHP_EOL);
        exit(1);
    }
    $name = (string) $definition[0];
    $fieldset = (int) $definition[2];
    $order = (int) $definition[3];
    if (isset($schemaNames[$name])) {
        fwrite(STDERR, 'Duplicate configuration key: ' . $name . PHP_EOL);
        exit(1);
    }
    if (!in_array($fieldset, array_values($schema['tabs']), true)) {
        fwrite(STDERR, 'Unknown configuration fieldset for: ' . $name . PHP_EOL);
        exit(1);
    }
    $orderKey = $fieldset . ':' . $order;
    if (isset($orders[$orderKey])) {
        fwrite(STDERR, 'Duplicate configuration order: ' . $orderKey . PHP_EOL);
        exit(1);
    }
    $orders[$orderKey] = true;
    $schemaNames[$name] = true;
}

$defaultNames = array_fill_keys(array_keys($defaults), true);
$missingDefaults = array_diff_key($schemaNames, $defaultNames);
$missingSchema = array_diff_key($defaultNames, $schemaNames);
if (count($missingDefaults) > 0 || count($missingSchema) > 0) {
    fwrite(
        STDERR,
        'Configuration schema/default mismatch. Schema-only: '
        . implode(', ', array_keys($missingDefaults))
        . '; defaults-only: ' . implode(', ', array_keys($missingSchema))
        . PHP_EOL
    );
    exit(1);
}

if (!class_exists('Videos_TemplateRenderer', true)) {
    fwrite(STDERR, 'Videos template renderer is not autoloadable.' . PHP_EOL);
    exit(1);
}
foreach (array('catalogue.thtml', 'video-card.thtml') as $template) {
    $path = $_CONF['path'] . 'templates/default/' . $template;
    if (!is_file($path) || filesize($path) === 0) {
        fwrite(STDERR, 'Missing Videos template: ' . $template . PHP_EOL);
        exit(1);
    }
}

if (!class_exists('Videos_ExternalSync', true)) {
    fwrite(STDERR, 'Videos external sync boundary is not autoloadable.' . PHP_EOL);
    exit(1);
}
$syncParameters = Videos_ExternalSync::searchParameters($defaults);
foreach (array(
    'max_results',
    'daily_search_limit',
    'cache_ttl',
    'video_cache_ttl',
    'channel_cache_ttl',
    'availability_cache_ttl',
    'safe_search',
    'language',
    'region'
) as $requiredParameter) {
    if (!array_key_exists($requiredParameter, $syncParameters)) {
        fwrite(
            STDERR,
            'Missing external sync parameter: ' . $requiredParameter . PHP_EOL
        );
        exit(1);
    }
}

$adminActions = file_get_contents($_CONF['path'] . 'admin/actions.php');
if (!is_string($adminActions)) {
    fwrite(STDERR, 'Unable to inspect admin/actions.php.' . PHP_EOL);
    exit(1);
}
foreach (array(
    'new Videos_YouTubeClient',
    'new Videos_YouTubeService'
) as $forbiddenConstruction) {
    if (strpos($adminActions, $forbiddenConstruction) !== false) {
        fwrite(
            STDERR,
            'Admin bypasses external sync boundary: '
            . $forbiddenConstruction . PHP_EOL
        );
        exit(1);
    }
}
if (substr_count($adminActions, 'Videos_ExternalSync') < 3) {
    fwrite(STDERR, 'Admin external sync boundary is not fully wired.' . PHP_EOL);
    exit(1);
}

echo 'Videos integration load: OK (' . count($schemaNames)
    . ' configuration keys, URL round-trips, template and external sync boundaries present)'
    . PHP_EOL;
