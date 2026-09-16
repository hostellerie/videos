<?php

$_CONF = array(
    'path' => dirname(__DIR__) . '/',
);

require_once $_CONF['path'] . 'autoload.php';
VIDEOS_registerAutoloader();

$files = glob($_CONF['path'] . 'classes/Videos_*.php');
if (!is_array($files) || count($files) === 0) {
    fwrite(STDERR, 'No Videos class files found.' . PHP_EOL);
    exit(1);
}

$failures = array();
foreach ($files as $file) {
    $name = basename($file, '.php');
    if (!class_exists($name, true) && !interface_exists($name, true)) {
        $failures[] = $name;
    }
}

if (count($failures) > 0) {
    fwrite(
        STDERR,
        'Autoload failed for: ' . implode(', ', $failures) . PHP_EOL
    );
    exit(1);
}

echo 'Videos autoload: OK (' . count($files) . ' class files)' . PHP_EOL;

// Configuration is another plugin-wide declarative contract. Run it here so
// the same PHP 5.6/7.4/8.1 matrix guards schema/default drift and verifies that
// initial installation is driven by videos_config_schema().
require __DIR__ . '/test-config-schema.php';

// Lifecycle interoperability must stay stable across the same supported PHP
// matrix because downstream plugins rely on PLG_itemSaved/PLG_itemDeleted.
require __DIR__ . '/test-lifecycle-signals.php';

// Search API and statistics must operate on the same moderated local corpus
// without provider access.
require __DIR__ . '/test-search-stats.php';

// Geeklog XMLSitemap falls back to Item Info with the exact
// url,date-modified collection contract when no specialized collector exists.
require __DIR__ . '/test-xmlsitemap-fallback.php';
