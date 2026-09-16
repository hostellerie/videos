<?php

if (!isset($_CONF) || !is_array($_CONF)) {
    fwrite(STDERR, "Videos Item Info test requires plugin bootstrap context.\n");
    exit(1);
}
if (!function_exists('plugin_getiteminfo_videos')) {
    fwrite(STDERR, "Videos Item Info callback is not loaded.\n");
    exit(1);
}

$originalConf = $_CONF;
$originalVideosConf = isset($GLOBALS['_VIDEOS_CONF'])
    ? $GLOBALS['_VIDEOS_CONF'] : null;
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'videos-item-info-' . uniqid('', true);
$dataRoot = $tempRoot . DIRECTORY_SEPARATOR . 'site-data' . DIRECTORY_SEPARATOR;

$_CONF['path_data'] = $dataRoot;
$GLOBALS['_VIDEOS_CONF'] = array('description_mode' => 'clean');

$fail = function ($message) use (
    &$originalConf,
    &$originalVideosConf,
    $tempRoot
) {
    $_CONF = $originalConf;
    if ($originalVideosConf === null) {
        unset($GLOBALS['_VIDEOS_CONF']);
    } else {
        $GLOBALS['_VIDEOS_CONF'] = $originalVideosConf;
    }
    videos_item_info_remove_tree($tempRoot);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    $fail('Unable to initialize temporary Videos storage.');
}
$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);

$videos = array(
    'AlphaVid001' => array(
        'published' => '2024-01-01T10:00:00Z',
        'admitted' => '2024-06-01T10:00:00Z',
        'title' => 'Alpha video'
    ),
    'BravoVid002' => array(
        'published' => '2025-01-01T10:00:00Z',
        'admitted' => '2024-07-01T10:00:00Z',
        'title' => 'Bravo video'
    ),
    'CharlieV003' => array(
        'published' => '2023-01-01T10:00:00Z',
        'admitted' => '2024-08-01T10:00:00Z',
        'title' => 'Charlie video'
    )
);
$channelId = 'UC1234567890123456789012';
$poolItems = array();
foreach ($videos as $videoId => $fixture) {
    $resource = array(
        'id' => $videoId,
        'snippet' => array(
            'title' => $fixture['title'],
            'description' => $fixture['title'] . ' description',
            'publishedAt' => $fixture['published'],
            'channelId' => $channelId,
            'channelTitle' => 'Fixture channel',
            'thumbnails' => array(
                'medium' => array(
                    'url' => 'https://img.example.invalid/' . $videoId . '.jpg'
                )
            )
        )
    );
    if (!$cache->putVideo($videoId, $resource, 3600, 7200)) {
        $fail('Unable to cache fixture video: ' . $videoId);
    }
    $poolItems[$videoId] = array(
        'video_id' => $videoId,
        'admitted_at' => $fixture['admitted'],
        'source' => 'manual',
        'pinned' => false
    );
}

$poolDocument = $store->createDocument(
    'videos.permanent_pool',
    array(
        'rebuilt_at' => '2024-08-02T10:00:00Z',
        'items' => $poolItems,
        'excluded' => array()
    )
);
if (!$store->write(
    'rankings/permanent_pool.json',
    'videos.permanent_pool',
    $poolDocument
)) {
    $fail('Unable to create fixture permanent pool.');
}

$single = plugin_getiteminfo_videos('BravoVid002', '*');
if (!is_array($single) ||
    !isset($single['id'], $single['title'], $single['url'], $single['date-modified']) ||
    $single['id'] !== 'BravoVid002' ||
    $single['title'] !== 'Bravo video' ||
    $single['date-modified'] !== '2024-07-01T10:00:00Z') {
    $fail('Single-item Item Info contract failed.');
}

$titleOnly = plugin_getiteminfo_videos('BravoVid002', 'title');
if ($titleOnly !== 'Bravo video') {
    $fail('Single-field Item Info filtering failed.');
}
$filtered = plugin_getiteminfo_videos(
    'BravoVid002',
    array('id', 'title', 'url')
);
if (!is_array($filtered) || count($filtered) !== 3 ||
    $filtered['id'] !== 'BravoVid002' ||
    $filtered['title'] !== 'Bravo video') {
    $fail('Multi-field Item Info filtering failed.');
}

$modified = plugin_getiteminfo_videos(
    '*',
    '*',
    0,
    array('limit' => 10, 'order' => 'modified-desc')
);
$modifiedIds = videos_item_info_ids($modified);
if ($modifiedIds !== array('CharlieV003', 'BravoVid002', 'AlphaVid001')) {
    $fail('Item Info modified-desc ordering failed: ' . implode(', ', $modifiedIds));
}

$created = plugin_getiteminfo_videos(
    '*',
    'id,title',
    0,
    array('limit' => 10, 'order' => 'created-desc')
);
$createdIds = videos_item_info_ids($created);
if ($createdIds !== array('BravoVid002', 'AlphaVid001', 'CharlieV003')) {
    $fail('Item Info created-desc ordering failed: ' . implode(', ', $createdIds));
}
foreach ($created as $record) {
    if (!is_array($record) || count($record) !== 2 ||
        !isset($record['id'], $record['title'])) {
        $fail('Collection field filtering failed.');
    }
}

$limited = plugin_getiteminfo_videos(
    '*',
    'id',
    0,
    array('limit' => 2, 'order' => 'modified-desc')
);
if ($limited !== array('CharlieV003', 'BravoVid002')) {
    $fail('Item Info limit handling failed.');
}

$since = plugin_getiteminfo_videos(
    '*',
    'id',
    0,
    array(
        'since' => '2024-07-15T00:00:00Z',
        'limit' => 10,
        'order' => 'modified-desc'
    )
);
if ($since !== array('CharlieV003')) {
    $fail('Item Info since filtering failed.');
}

$_CONF = $originalConf;
if ($originalVideosConf === null) {
    unset($GLOBALS['_VIDEOS_CONF']);
} else {
    $GLOBALS['_VIDEOS_CONF'] = $originalVideosConf;
}
videos_item_info_remove_tree($tempRoot);

echo 'Videos Item Info contract: OK (single item, fields, since, limit, ordering)'
    . PHP_EOL;

function videos_item_info_ids($records)
{
    $ids = array();
    if (!is_array($records)) {
        return $ids;
    }
    foreach ($records as $record) {
        if (is_array($record) && isset($record['id'])) {
            $ids[] = $record['id'];
        } elseif (is_string($record)) {
            $ids[] = $record;
        }
    }
    return $ids;
}

function videos_item_info_remove_tree($path)
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
            videos_item_info_remove_tree(
                $path . DIRECTORY_SEPARATOR . $item
            );
        }
    }
    @rmdir($path);
}
