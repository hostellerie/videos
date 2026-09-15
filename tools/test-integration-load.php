<?php

$_CONF = array(
    'path' => dirname(__DIR__) . '/',
    'site_url' => 'https://example.invalid',
);

require_once $_CONF['path'] . 'autoload.php';
VIDEOS_registerAutoloader();
require_once $_CONF['path'] . 'geeklog_integration.php';
require_once $_CONF['path'] . 'interoperability.php';

$requiredCallbacks = array(
    'plugin_searchtypes_videos',
    'plugin_dopluginsearch_videos',
    'plugin_statssummary_videos',
    'plugin_getiteminfo_videos',
    'plugin_idtourl_videos',
    'plugin_urltoid_videos',
    'plugin_getfeednames_videos',
    'plugin_getfeedcontent_videos',
    'plugin_autotags_videos',
);

foreach ($requiredCallbacks as $callback) {
    if (!function_exists($callback)) {
        fwrite(STDERR, 'Missing Videos callback: ' . $callback . PHP_EOL);
        exit(1);
    }
}

echo 'Videos integration load: OK' . PHP_EOL;
