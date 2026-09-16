<?php

if (!isset($_CONF) || !is_array($_CONF)) {
    $_CONF = array();
}
$_CONF['path'] = dirname(__DIR__) . '/';
$_CONF['site_url'] = 'https://example.invalid';

require_once $_CONF['path'] . 'autoload.php';
VIDEOS_registerAutoloader();
require_once $_CONF['path'] . 'interoperability.php';

$originalConf = $_CONF;
$originalVideosConf = isset($GLOBALS['_VIDEOS_CONF'])
    ? $GLOBALS['_VIDEOS_CONF'] : null;
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'videos-sitemap-' . uniqid('', true);
$_CONF['path_data'] = $tempRoot . DIRECTORY_SEPARATOR . 'site-data'
    . DIRECTORY_SEPARATOR;
$GLOBALS['_VIDEOS_CONF'] = array('description_mode' => 'clean');

$fail = function ($message) use (&$originalConf, &$originalVideosConf, $tempRoot) {
    $_CONF = $originalConf;
    if ($originalVideosConf === null) {
        unset($GLOBALS['_VIDEOS_CONF']);
    } else {
        $GLOBALS['_VIDEOS_CONF'] = $originalVideosConf;
    }
    videos_sitemap_remove_tree($tempRoot);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    $fail('Unable to initialize sitemap fixture storage.');
}
$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$channelId = 'UC1234567890123456789012';
$fixtures = array(
    'AlphaVid001' => '2024-06-01T10:00:00Z',
    'BravoVid002' => '2024-07-01T10:00:00Z'
);
$items = array();
foreach ($fixtures as $videoId => $admittedAt) {
    if (!$cache->putVideo(
        $videoId,
        array(
            'id' => $videoId,
            'snippet' => array(
                'title' => $videoId,
                'description' => '',
                'publishedAt' => '2024-01-01T00:00:00Z',
                'channelId' => $channelId,
                'channelTitle' => 'Fixture channel'
            )
        ),
        3600,
        7200
    )) {
        $fail('Unable to cache sitemap fixture: ' . $videoId);
    }
    $items[$videoId] = array(
        'video_id' => $videoId,
        'admitted_at' => $admittedAt,
        'source' => 'manual',
        'pinned' => false
    );
}
$document = $store->createDocument(
    'videos.permanent_pool',
    array(
        'rebuilt_at' => '2024-07-02T00:00:00Z',
        'items' => $items,
        'excluded' => array()
    )
);
if (!$store->write(
    'rankings/permanent_pool.json',
    'videos.permanent_pool',
    $document
)) {
    $fail('Unable to create sitemap fixture pool.');
}

// This mirrors Geeklog XMLSitemap's fallback call when a plugin does not
// implement plugin_collectSitemapItems_PLUGIN().
$result = plugin_getiteminfo_videos(
    '*',
    'url,date-modified',
    1,
    array()
);
if (!is_array($result) || count($result) !== 2) {
    $fail('XMLSitemap Item Info fallback did not expose editorial videos.');
}
foreach ($result as $entry) {
    if (!is_array($entry) || count($entry) !== 2 ||
        !isset($entry['url'], $entry['date-modified']) ||
        strpos($entry['url'], 'https://example.invalid/videos/watch.php?v=') !== 0 ||
        strtotime($entry['date-modified']) === false) {
        $fail('XMLSitemap fallback entry has an invalid shape.');
    }
}

$moderation = new Videos_Moderation($store);
if (!$moderation->setVideoState(
    'BravoVid002',
    'blocked',
    'Sitemap visibility fixture',
    str_repeat('d', 64)
)) {
    $fail('Unable to moderate sitemap fixture.');
}
$result = plugin_getiteminfo_videos(
    '*',
    'url,date-modified',
    1,
    array()
);
if (!is_array($result) || count($result) !== 1 ||
    strpos($result[0]['url'], 'AlphaVid001') === false) {
    $fail('XMLSitemap fallback did not respect moderation visibility.');
}

$_CONF = $originalConf;
if ($originalVideosConf === null) {
    unset($GLOBALS['_VIDEOS_CONF']);
} else {
    $GLOBALS['_VIDEOS_CONF'] = $originalVideosConf;
}
videos_sitemap_remove_tree($tempRoot);

echo 'Videos XMLSitemap fallback: OK (url,date-modified collection and moderation)'
    . PHP_EOL;

function videos_sitemap_remove_tree($path)
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $items = scandir($path);
    if (is_array($items)) {
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            videos_sitemap_remove_tree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}
