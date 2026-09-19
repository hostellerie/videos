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
$videoStats = new Videos_VideoStats($store);

$channelOne = 'UC1234567890123456789012';
$channelTwo = 'UCabcdefghijklmnopqrstuv';
$videos = array(
    'AlphaVid001' => array(
        'published' => '2024-01-01T10:00:00Z',
        'admitted' => '2024-06-01T10:00:00Z',
        'title' => 'Alpha video',
        'channel' => $channelOne
    ),
    'BravoVid002' => array(
        'published' => '2025-01-01T10:00:00Z',
        'admitted' => '2024-07-01T10:00:00Z',
        'title' => 'Bravo video',
        'channel' => $channelOne
    ),
    'CharlieV003' => array(
        'published' => '2023-01-01T10:00:00Z',
        'admitted' => '2024-08-01T10:00:00Z',
        'title' => 'Charlie video',
        'channel' => $channelTwo
    )
);
$poolItems = array();
foreach ($videos as $videoId => $fixture) {
    $resource = array(
        'id' => $videoId,
        'snippet' => array(
            'title' => $fixture['title'],
            'description' => $fixture['title'] . ' description',
            'publishedAt' => $fixture['published'],
            'channelId' => $fixture['channel'],
            'channelTitle' => $fixture['channel'] === $channelOne
                ? 'Fixture channel one' : 'Fixture channel two',
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

if (!$videoStats->recordView('AlphaVid001', 60, 100, $channelOne)) {
    $fail('Unable to create Alpha view fixture.');
}
if (!$videoStats->recordView('BravoVid002', 60, 100, $channelOne)
    || !$videoStats->recordView('BravoVid002', 80, 100, $channelOne)
    || !$videoStats->recordView('BravoVid002', 95, 100, $channelOne)) {
    $fail('Unable to create Bravo view fixtures.');
}
if (!$videoStats->recordView('CharlieV003', 70, 100, $channelTwo)
    || !$videoStats->recordView('CharlieV003', 90, 100, $channelTwo)) {
    $fail('Unable to create Charlie view fixtures.');
}

$single = plugin_getiteminfo_videos('BravoVid002', '*');
if (!is_array($single) ||
    !isset($single['id'], $single['title'], $single['url'], $single['date-modified']) ||
    $single['id'] !== 'BravoVid002' ||
    $single['title'] !== 'Bravo video' ||
    $single['date-modified'] !== '2024-07-01T10:00:00Z' ||
    !isset($single['hits']) || (int) $single['hits'] !== 3) {
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

$popular = plugin_getiteminfo_videos(
    '*',
    'id,hits',
    0,
    array('limit' => 10, 'order' => 'hits-desc')
);
$popularIds = videos_item_info_ids($popular);
if ($popularIds !== array('BravoVid002', 'CharlieV003', 'AlphaVid001')) {
    $fail('Item Info hits-desc ordering failed: ' . implode(', ', $popularIds));
}
if (!isset($popular[0]['hits'], $popular[1]['hits'], $popular[2]['hits'])
    || (int) $popular[0]['hits'] !== 3
    || (int) $popular[1]['hits'] !== 2
    || (int) $popular[2]['hits'] !== 1) {
    $fail('Item Info normalized hits values are incorrect.');
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

if (!function_exists('plugin_getfeedcontent_videos')) {
    $fail('Videos feed callback is not loaded.');
}
$feedLink = '';
$feedUpdate = '';
$feed = plugin_getfeedcontent_videos(
    'videos-test',
    $feedLink,
    $feedUpdate,
    'RSS',
    '2.0'
);
if ($feedLink !== 'https://example.invalid/videos/index.php') {
    $fail('Videos feed catalogue link does not match Item Info routing.');
}
if (!is_array($feed) || count($feed) !== 3) {
    $fail('Videos feed did not expose the complete editorial corpus.');
}
$feedTitles = array();
foreach ($feed as $entry) {
    if (!is_array($entry) ||
        !isset($entry['title'], $entry['summary'], $entry['link'], $entry['date'])) {
        $fail('Videos feed entry is incomplete.');
    }
    $feedTitles[] = $entry['title'];
}
if ($feedTitles !== array('Charlie video', 'Bravo video', 'Alpha video')) {
    $fail('Videos feed order diverges from modified-desc Item Info order.');
}
foreach (array('CharlieV003', 'BravoVid002', 'AlphaVid001') as $expectedId) {
    if (strpos($feedUpdate, $expectedId . '@') === false) {
        $fail('Videos feed update signature misses item: ' . $expectedId);
    }
}

$moderation = new Videos_Moderation($store);
$actorHash = str_repeat('a', 64);
if (!$moderation->setVideoState(
    'BravoVid002',
    'blocked',
    'Fixture moderation test',
    $actorHash
)) {
    $fail('Unable to block fixture video.');
}
if (plugin_getiteminfo_videos('BravoVid002', '*') !== '') {
    $fail('Blocked video remains visible through single-item Item Info.');
}
$afterVideoBlock = plugin_getiteminfo_videos(
    '*',
    'id',
    0,
    array('limit' => 10, 'order' => 'modified-desc')
);
if ($afterVideoBlock !== array('CharlieV003', 'AlphaVid001')) {
    $fail('Blocked video remains visible in Item Info collection.');
}
$blockedFeedLink = '';
$blockedFeedUpdate = '';
$blockedFeed = plugin_getfeedcontent_videos(
    'videos-test',
    $blockedFeedLink,
    $blockedFeedUpdate,
    'RSS',
    '2.0'
);
if (!is_array($blockedFeed) || count($blockedFeed) !== 2 ||
    videos_item_info_feed_titles($blockedFeed) !== array(
        'Charlie video',
        'Alpha video'
    )) {
    $fail('Blocked video remains visible in Videos feed.');
}
if (strpos($blockedFeedUpdate, 'BravoVid002@') !== false) {
    $fail('Blocked video remains present in feed update signature.');
}
if (!$moderation->setVideoState(
    'BravoVid002',
    'neutral',
    '',
    $actorHash
)) {
    $fail('Unable to restore fixture video moderation state.');
}

if (!$moderation->setChannelState(
    $channelOne,
    'blocked',
    'Fixture channel moderation test',
    $actorHash
)) {
    $fail('Unable to block fixture channel.');
}
$afterChannelBlock = plugin_getiteminfo_videos(
    '*',
    'id',
    0,
    array('limit' => 10, 'order' => 'modified-desc')
);
if ($afterChannelBlock !== array('CharlieV003')) {
    $fail('Blocked channel videos remain visible in Item Info collection.');
}
if (plugin_getiteminfo_videos('AlphaVid001', '*') !== '' ||
    plugin_getiteminfo_videos('BravoVid002', '*') !== '') {
    $fail('Blocked channel video remains visible through single-item Item Info.');
}
$channelFeedLink = '';
$channelFeedUpdate = '';
$channelFeed = plugin_getfeedcontent_videos(
    'videos-test',
    $channelFeedLink,
    $channelFeedUpdate,
    'RSS',
    '2.0'
);
if (!is_array($channelFeed) || count($channelFeed) !== 1 ||
    videos_item_info_feed_titles($channelFeed) !== array('Charlie video')) {
    $fail('Blocked channel videos remain visible in Videos feed.');
}
if (strpos($channelFeedUpdate, 'AlphaVid001@') !== false ||
    strpos($channelFeedUpdate, 'BravoVid002@') !== false) {
    $fail('Blocked channel videos remain present in feed update signature.');
}

$_CONF = $originalConf;
if ($originalVideosConf === null) {
    unset($GLOBALS['_VIDEOS_CONF']);
} else {
    $GLOBALS['_VIDEOS_CONF'] = $originalVideosConf;
}
videos_item_info_remove_tree($tempRoot);

echo 'Videos Item Info/feed contract: OK (single item, fields, hits, hits-desc, since, limit, ordering, syndication, moderation visibility)'
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

function videos_item_info_feed_titles($feed)
{
    $titles = array();
    if (!is_array($feed)) {
        return $titles;
    }
    foreach ($feed as $entry) {
        if (is_array($entry) && isset($entry['title'])) {
            $titles[] = $entry['title'];
        }
    }
    return $titles;
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
