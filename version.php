<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

if (!defined('VIDEOS_PLUGIN_VERSION')) {
    define('VIDEOS_PLUGIN_VERSION', '0.18.0');
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
