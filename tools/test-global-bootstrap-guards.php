<?php

$root = dirname(__DIR__);
$targets = array(
    $root . '/functions.inc',
    $root . '/version.php',
    $root . '/autoinstall.php',
    $root . '/autoload.php',
    $root . '/configuration_validation.php',
    $root . '/feed_update.php',
    $root . '/geeklog_integration.php',
    $root . '/install_defaults.php',
    $root . '/install_updates.php',
    $root . '/interoperability.php',
    $root . '/services.inc.php'
);

foreach (glob($root . '/classes/Videos_*.php') as $path) {
    $targets[] = $path;
}

$bad = array();
foreach ($targets as $path) {
    $content = file_get_contents($path);
    if ($content === false) {
        $bad[] = basename($path) . ': unreadable';
        continue;
    }
    if (strpos($content, 'if (!isset($_CONF))') !== false) {
        $bad[] = str_replace($root . '/', '', $path);
    }
}

if (!empty($bad)) {
    fwrite(STDERR, "Scope-sensitive Geeklog guards remain:\n"
        . implode("\n", $bad) . "\n");
    exit(1);
}

echo "Videos global bootstrap guards: OK" . PHP_EOL;
