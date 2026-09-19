<?php

global $_CONF, $_TABLES, $_DB_table_prefix, $_PLUGINS, $_VIDEOS_CONF;

if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

if (!defined('VIDEOS_PLUGIN_VERSION')) {
    define('VIDEOS_PLUGIN_VERSION', '0.20.0');
}
if (!defined('VIDEOS_MIN_GEEKLOG_VERSION')) {
    define('VIDEOS_MIN_GEEKLOG_VERSION', '2.1.1');
}
if (!defined('VIDEOS_MIN_PHP_VERSION')) {
    define('VIDEOS_MIN_PHP_VERSION', '5.6.0');
}
if (!defined('VIDEOS_RELEASE_STATUS')) {
    define('VIDEOS_RELEASE_STATUS', 'development');
}

require_once __DIR__ . '/interoperability.php';
require_once __DIR__ . '/feed_update.php';
require_once __DIR__ . '/geeklog_integration.php';
