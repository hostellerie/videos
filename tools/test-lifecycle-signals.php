<?php

if (!isset($_CONF) || !is_array($_CONF)) {
    $_CONF = array();
}
if (!isset($_CONF['path'])) {
    $_CONF['path'] = dirname(__DIR__) . '/';
}
if (!isset($_CONF['site_url'])) {
    $_CONF['site_url'] = 'https://example.invalid';
}

if (!function_exists('PLG_itemSaved')) {
    function PLG_itemSaved($id, $type)
    {
        $GLOBALS['_VIDEOS_TEST_SAVED'][] = array($id, $type);
    }
}
if (!function_exists('PLG_itemDeleted')) {
    function PLG_itemDeleted($id, $type)
    {
        $GLOBALS['_VIDEOS_TEST_DELETED'][] = array($id, $type);
    }
}

require_once $_CONF['path'] . 'autoload.php';
VIDEOS_registerAutoloader();
require_once $_CONF['path'] . 'interoperability.php';

$originalConf = $_CONF;
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'videos-lifecycle-' . uniqid('', true);
$_CONF['path_data'] = $tempRoot . DIRECTORY_SEPARATOR . 'site-data'
    . DIRECTORY_SEPARATOR;
$GLOBALS['_VIDEOS_TEST_SAVED'] = array();
$GLOBALS['_VIDEOS_TEST_DELETED'] = array();

$fail = function ($message) use (&$originalConf, $tempRoot) {
    $_CONF = $originalConf;
    videos_lifecycle_remove_tree($tempRoot);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    $fail('Unable to initialize lifecycle test storage.');
}
$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$channelOne = 'UC1234567890123456789012';
$channelTwo = 'UCabcdefghijklmnopqrstuv';
$fixtures = array(
    'AlphaVid001' => $channelOne,
    'BravoVid002' => $channelOne,
    'CharlieV003' => $channelTwo
);
$items = array();
foreach ($fixtures as $videoId => $channelId) {
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
        $fail('Unable to cache lifecycle fixture: ' . $videoId);
    }
    $items[$videoId] = array(
        'video_id' => $videoId,
        'admitted_at' => '2024-06-01T00:00:00Z',
        'source' => 'manual',
        'pinned' => false
    );
}
$document = $store->createDocument(
    'videos.permanent_pool',
    array(
        'rebuilt_at' => '2024-06-02T00:00:00Z',
        'items' => $items,
        'excluded' => array()
    )
);
if (!$store->write(
    'rankings/permanent_pool.json',
    'videos.permanent_pool',
    $document
)) {
    $fail('Unable to create lifecycle fixture pool.');
}

$moderation = new Videos_Moderation($store);
$actorHash = str_repeat('b', 64);
if (!$moderation->setVideoState(
    'BravoVid002',
    'blocked',
    'Lifecycle test',
    $actorHash
)) {
    $fail('Unable to block lifecycle fixture video.');
}
videos_lifecycle_assert_event(
    '_VIDEOS_TEST_DELETED',
    'BravoVid002',
    $fail,
    'Blocking a published video did not emit delete.'
);
videos_lifecycle_assert_event(
    '_VIDEOS_TEST_SAVED',
    'catalogue',
    $fail,
    'Blocking a video did not invalidate catalogue.'
);
videos_lifecycle_assert_event(
    '_VIDEOS_TEST_SAVED',
    'rankings:videos',
    $fail,
    'Blocking a video did not invalidate video rankings.'
);

$GLOBALS['_VIDEOS_TEST_SAVED'] = array();
$GLOBALS['_VIDEOS_TEST_DELETED'] = array();
if (!$moderation->setVideoState(
    'BravoVid002',
    'neutral',
    '',
    $actorHash
)) {
    $fail('Unable to restore lifecycle fixture video.');
}
videos_lifecycle_assert_event(
    '_VIDEOS_TEST_SAVED',
    'BravoVid002',
    $fail,
    'Unblocking a published video did not emit save.'
);

$GLOBALS['_VIDEOS_TEST_SAVED'] = array();
$GLOBALS['_VIDEOS_TEST_DELETED'] = array();
if (!$moderation->setChannelState(
    $channelOne,
    'blocked',
    'Lifecycle channel test',
    $actorHash
)) {
    $fail('Unable to block lifecycle fixture channel.');
}
foreach (array('channel:' . $channelOne, 'AlphaVid001', 'BravoVid002') as $id) {
    videos_lifecycle_assert_event(
        '_VIDEOS_TEST_DELETED',
        $id,
        $fail,
        'Blocking a channel missed delete event for: ' . $id
    );
}
foreach (array('catalogue', 'channels', 'rankings:channels', 'rankings:videos') as $id) {
    videos_lifecycle_assert_event(
        '_VIDEOS_TEST_SAVED',
        $id,
        $fail,
        'Blocking a channel missed invalidation event for: ' . $id
    );
}
if (videos_lifecycle_has_event('_VIDEOS_TEST_DELETED', 'CharlieV003')) {
    $fail('Blocking one channel emitted delete for unrelated video.');
}

$_CONF = $originalConf;
videos_lifecycle_remove_tree($tempRoot);
unset($GLOBALS['_VIDEOS_TEST_SAVED'], $GLOBALS['_VIDEOS_TEST_DELETED']);

echo 'Videos lifecycle signals: OK (video block/unblock and channel exclusion)'
    . PHP_EOL;

function videos_lifecycle_assert_event($bucket, $id, $fail, $message)
{
    if (!videos_lifecycle_has_event($bucket, $id)) {
        $fail($message);
    }
}

function videos_lifecycle_has_event($bucket, $id)
{
    $events = isset($GLOBALS[$bucket]) && is_array($GLOBALS[$bucket])
        ? $GLOBALS[$bucket] : array();
    foreach ($events as $event) {
        if (is_array($event) && isset($event[0], $event[1]) &&
            $event[0] === $id && $event[1] === 'videos') {
            return true;
        }
    }
    return false;
}

function videos_lifecycle_remove_tree($path)
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
            videos_lifecycle_remove_tree(
                $path . DIRECTORY_SEPARATOR . $item
            );
        }
    }
    @rmdir($path);
}
