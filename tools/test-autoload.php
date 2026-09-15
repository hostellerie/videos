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
