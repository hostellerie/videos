<?php

global $_CONF, $_TABLES, $_DB_table_prefix, $_PLUGINS, $_VIDEOS_CONF;

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

/**
 * Register the Videos class autoloader.
 *
 * Kept deliberately small for PHP 5.6 compatibility. Only Videos_* classes
 * are eligible and they map directly to classes/<ClassName>.php.
 */
function VIDEOS_registerAutoloader()
{
    static $registered = false;

    if ($registered) {
        return;
    }

    spl_autoload_register('VIDEOS_autoloadClass');
    $registered = true;
}

/**
 * Load one Videos_* class from the plugin classes directory.
 */
function VIDEOS_autoloadClass($className)
{
    $className = (string) $className;
    if (strpos($className, 'Videos_') !== 0) {
        return;
    }

    if (!preg_match('/\AVideos_[A-Za-z0-9_]+\z/', $className)) {
        return;
    }

    $path = __DIR__ . '/classes/' . $className . '.php';
    if (is_file($path)) {
        require_once $path;
    }
}
