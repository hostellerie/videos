<?php

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'videos-storage-test-'
    . str_replace('.', '', uniqid('', true));
$pathData = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
    . 'S1' . DIRECTORY_SEPARATOR;
$legacy = $pathData . 'videos' . DIRECTORY_SEPARATOR;
$_CONF = array('path' => $root . DIRECTORY_SEPARATOR);

require dirname(__FILE__) . '/../classes/Videos_Validator.php';
require dirname(__FILE__) . '/../classes/Videos_JsonStore.php';
require dirname(__FILE__) . '/../classes/Videos_Bootstrap.php';

function videos_test_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function videos_test_remove_tree($path, $root)
{
    $path = realpath($path);
    $root = realpath($root);
    if ($path === false || $root === false || strpos($path, $root) !== 0) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

try {
    $legacyStore = new Videos_JsonStore($legacy, 5242880);
    videos_test_assert($legacyStore->initialize(), 'Cannot initialize legacy store.');
    $secret = str_repeat('a', 64);
    $apiKey = 'AIzaSyVideosStorageMigrationTest123456';
    $secrets = $legacyStore->createDocument(
        'videos.secrets',
        array('privacy_hmac_key' => $secret, 'youtube_api_key' => $apiKey)
    );
    videos_test_assert(
        $legacyStore->write('config/secrets.json', 'videos.secrets', $secrets),
        'Cannot write legacy secrets.'
    );
    $sample = $legacyStore->createDocument(
        'videos.test_sample',
        array('value' => 'preserved')
    );
    videos_test_assert(
        $legacyStore->write('ratings/sample.json', 'videos.test_sample', $sample),
        'Cannot write legacy sample.'
    );

    $configuration = array(
        'path' => $root . DIRECTORY_SEPARATOR,
        'path_data' => $pathData,
        'path_html' => $root . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR
    );
    $bootstrap = new Videos_Bootstrap($configuration);
    $expected = rtrim($pathData, '/\\') . '-videos' . DIRECTORY_SEPARATOR;
    videos_test_assert($bootstrap->isReady(), 'Migrated bootstrap is not ready.');
    videos_test_assert(
        $bootstrap->getDataRoot() === $expected,
        'Derived multisite path is incorrect.'
    );
    videos_test_assert($bootstrap->getSecret() === $secret, 'HMAC secret changed.');
    videos_test_assert(
        $bootstrap->getYouTubeApiKey() === $apiKey,
        'YouTube API key changed.'
    );
    videos_test_assert(
        is_file($legacy . 'config' . DIRECTORY_SEPARATOR . 'secrets.json'),
        'Legacy source was removed.'
    );
    videos_test_assert(
        is_file($expected . 'ratings' . DIRECTORY_SEPARATOR . 'sample.json'),
        'Persistent sample was not copied.'
    );
    videos_test_assert(
        is_file($expected . 'config' . DIRECTORY_SEPARATOR
            . 'storage-migration.json'),
        'Migration marker is missing.'
    );

    videos_test_remove_tree($pathData, $root);
    videos_test_assert(
        $bootstrap->getYouTubeApiKey() === $apiKey &&
        is_file($expected . 'config' . DIRECTORY_SEPARATOR . 'secrets.json'),
        'Simulated Geeklog data cleanup reached persistent storage.'
    );

    $second = new Videos_Bootstrap($configuration);
    videos_test_assert($second->isReady(), 'Second bootstrap is not ready.');
    videos_test_assert($second->getSecret() === $secret, 'Second load changed secret.');

    $s2Data = $root . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
        . 'S2' . DIRECTORY_SEPARATOR;
    $invalidConfiguration = array(
        'path' => $root . DIRECTORY_SEPARATOR,
        'path_data' => $s2Data,
        'path_html' => $root . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR,
        'videos_data_path' => $s2Data . 'persistent' . DIRECTORY_SEPARATOR
    );
    $invalid = new Videos_Bootstrap($invalidConfiguration);
    videos_test_assert(
        $invalid->getDataRoot() === rtrim($s2Data, '/\\')
            . '-videos' . DIRECTORY_SEPARATOR,
        'A custom path inside path_data was not rejected.'
    );

    $customRoot = $root . DIRECTORY_SEPARATOR . 'persistent'
        . DIRECTORY_SEPARATOR . 'S3' . DIRECTORY_SEPARATOR . 'videos'
        . DIRECTORY_SEPARATOR;
    $customConfiguration = array(
        'path' => $root . DIRECTORY_SEPARATOR,
        'path_data' => $root . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'S3' . DIRECTORY_SEPARATOR,
        'path_html' => $root . DIRECTORY_SEPARATOR . 'public_html'
            . DIRECTORY_SEPARATOR,
        'videos_data_path' => $customRoot
    );
    $custom = new Videos_Bootstrap($customConfiguration);
    videos_test_assert(
        $custom->isReady() && $custom->getDataRoot() === $customRoot,
        'A valid absolute custom path was not accepted.'
    );
    echo "storage migration test: OK\n";
} finally {
    videos_test_remove_tree($root, $root);
}
