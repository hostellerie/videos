<?php

$root = dirname(__DIR__) . DIRECTORY_SEPARATOR;

$GLOBALS['_CONF'] = array(
    'path' => $root,
    'path_system' => $root . 'system' . DIRECTORY_SEPARATOR,
    'path_html' => $root . 'public_html' . DIRECTORY_SEPARATOR,
    'path_data' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'videos-scope-test' . DIRECTORY_SEPARATOR,
    'site_url' => 'https://example.invalid',
    'site_admin_url' => 'https://example.invalid/admin',
    'language' => 'english'
);
$GLOBALS['_TABLES'] = array();
$GLOBALS['_DB_table_prefix'] = 'gl_';
$GLOBALS['_PLUGINS'] = array();

function videos_test_scoped_core_include($root)
{
    // Intentionally do not declare global $_CONF here. Geeklog may load a
    // plugin functions.inc from inside Core function scope. The plugin must
    // bind the Geeklog globals itself before checking its bootstrap guard.
    require $root . 'functions.inc';
}

videos_test_scoped_core_include($root);

if (!defined('VIDEOS_PLUGIN_VERSION') || VIDEOS_PLUGIN_VERSION !== '0.20.0') {
    fwrite(STDERR, "Videos version was not loaded from function scope.\n");
    exit(1);
}
if (!function_exists('plugin_getadminoption_videos')) {
    fwrite(STDERR, "Videos functions.inc did not finish loading from function scope.\n");
    exit(1);
}
if (!function_exists('plugin_enablestatechange_videos')) {
    fwrite(STDERR, "Videos enable-state callback is unavailable after scoped load.\n");
    exit(1);
}
if (!function_exists('plugin_getcapabilities_videos')) {
    fwrite(STDERR, "Videos interoperability callbacks are unavailable after scoped load.\n");
    exit(1);
}

echo "Videos scoped Core include: OK" . PHP_EOL;
