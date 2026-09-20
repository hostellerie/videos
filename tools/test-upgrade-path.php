<?php

$_CONF = array();
require dirname(__DIR__) . '/install_updates.php';

$fail = function ($message) {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (!isset($GLOBALS['VIDEOS_UPDATES']['0.19.0'])) {
    $fail('Missing Videos 0.19.0 upgrade transition.');
}

$step = $GLOBALS['VIDEOS_UPDATES']['0.19.0'];
if (!isset($step['next']) || $step['next'] !== '0.20.0') {
    $fail('Videos 0.19.0 transition does not target 0.20.0.');
}
if (!isset($step['callback']) ||
    $step['callback'] !== 'videos_update_0_19_0_to_0_20_0') {
    $fail('Videos 0.19.0 transition callback is incorrect.');
}
if (!function_exists('videos_update_0_19_0_to_0_20_0') ||
    videos_update_0_19_0_to_0_20_0() !== true) {
    $fail('Videos 0.19.0 -> 0.20.0 callback failed.');
}
if (!videos_apply_updates('0.19.0', '0.20.0')) {
    $fail('videos_apply_updates() rejected 0.19.0 -> 0.20.0.');
}
if (!videos_apply_updates('0.18.0', '0.20.0')) {
    $fail('videos_apply_updates() rejected chained 0.18.0 -> 0.20.0.');
}

echo "Videos upgrade path 0.19.0 -> 0.20.0: OK" . PHP_EOL;
