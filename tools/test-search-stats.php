<?php

if (!isset($_CONF) || !is_array($_CONF)) {
    $_CONF = array();
}
$_CONF['path'] = dirname(__DIR__) . '/';
$_CONF['site_url'] = 'https://example.invalid';

if (!class_exists('SearchCriteria')) {
    class SearchCriteria
    {
        public $type;
        public $label;
        public $results = array();
        public $total = 0;
        public $rank = 0;
        public $appendQuery = true;
        public $urlRewrite = true;

        public function __construct($type, $label)
        {
            $this->type = $type;
            $this->label = $label;
        }

        public function setResults($results)
        {
            $this->results = $results;
        }

        public function setTotal($total)
        {
            $this->total = (int) $total;
        }

        public function setRank($rank)
        {
            $this->rank = (int) $rank;
        }

        public function setAppendQuery($value)
        {
            $this->appendQuery = (bool) $value;
        }

        public function setURLRewrite($value)
        {
            $this->urlRewrite = (bool) $value;
        }
    }
}
if (!function_exists('COM_numberFormat')) {
    function COM_numberFormat($number)
    {
        return (string) (int) $number;
    }
}
if (!function_exists('VIDEOS_getPublicTitle')) {
    function VIDEOS_getPublicTitle()
    {
        return 'Videos';
    }
}

require_once $_CONF['path'] . 'autoload.php';
VIDEOS_registerAutoloader();
require_once $_CONF['path'] . 'interoperability.php';
require_once $_CONF['path'] . 'geeklog_integration.php';

$originalConf = $_CONF;
$originalVideosConf = isset($GLOBALS['_VIDEOS_CONF'])
    ? $GLOBALS['_VIDEOS_CONF'] : null;
$originalGet = $_GET;
$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR
    . 'videos-search-' . uniqid('', true);
$_CONF['path_data'] = $tempRoot . DIRECTORY_SEPARATOR . 'site-data'
    . DIRECTORY_SEPARATOR;
$GLOBALS['_VIDEOS_CONF'] = array(
    'enabled' => 1,
    'discovery_enabled' => 0,
    'description_mode' => 'clean',
    'blocked_videos' => '',
    'blocked_channels' => '',
    'exclude_short_videos' => 0
);
$_GET = array();

$fail = function ($message) use (
    &$originalConf,
    &$originalVideosConf,
    &$originalGet,
    $tempRoot
) {
    $_CONF = $originalConf;
    $_GET = $originalGet;
    if ($originalVideosConf === null) {
        unset($GLOBALS['_VIDEOS_CONF']);
    } else {
        $GLOBALS['_VIDEOS_CONF'] = $originalVideosConf;
    }
    videos_search_remove_tree($tempRoot);
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    $fail('Unable to initialize search/stats fixture storage.');
}
$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$channelOne = 'UC1234567890123456789012';
$channelTwo = 'UCabcdefghijklmnopqrstuv';
$fixtures = array(
    'AlphaVid001' => array(
        'title' => 'Solar cooker basics',
        'description' => 'Build an efficient solar cooker at home.',
        'published' => '2024-01-10T10:00:00Z',
        'channel' => $channelOne,
        'channel_title' => 'Practical Ecology'
    ),
    'BravoVid002' => array(
        'title' => 'Rocket stove workshop',
        'description' => 'Efficient wood cooking and rocket stove design.',
        'published' => '2025-02-10T10:00:00Z',
        'channel' => $channelOne,
        'channel_title' => 'Practical Ecology'
    ),
    'CharlieV003' => array(
        'title' => 'Climbing rope inspection',
        'description' => 'How to inspect climbing equipment safely.',
        'published' => '2023-03-10T10:00:00Z',
        'channel' => $channelTwo,
        'channel_title' => 'Rope Access Lab'
    )
);
$poolItems = array();
foreach ($fixtures as $videoId => $fixture) {
    $resource = array(
        'id' => $videoId,
        'snippet' => array(
            'title' => $fixture['title'],
            'description' => $fixture['description'],
            'publishedAt' => $fixture['published'],
            'channelId' => $fixture['channel'],
            'channelTitle' => $fixture['channel_title']
        )
    );
    if (!$cache->putVideo($videoId, $resource, 3600, 7200)) {
        $fail('Unable to cache search fixture: ' . $videoId);
    }
    $poolItems[$videoId] = array(
        'video_id' => $videoId,
        'admitted_at' => '2025-03-01T00:00:00Z',
        'source' => 'manual',
        'pinned' => false
    );
}
$poolDocument = $store->createDocument(
    'videos.permanent_pool',
    array(
        'rebuilt_at' => '2025-03-02T00:00:00Z',
        'items' => $poolItems,
        'excluded' => array()
    )
);
if (!$store->write(
    'rankings/permanent_pool.json',
    'videos.permanent_pool',
    $poolDocument
)) {
    $fail('Unable to create search fixture pool.');
}

$criteria = plugin_dopluginsearch_videos(
    'rocket stove',
    '',
    '',
    '',
    'videos',
    0,
    'all',
    1,
    10
);
if (!($criteria instanceof SearchCriteria) ||
    $criteria->total !== 1 ||
    count($criteria->results) !== 1 ||
    $criteria->results[0]['id'] !== 'BravoVid002' ||
    $criteria->rank !== 3 ||
    $criteria->appendQuery !== false ||
    $criteria->urlRewrite !== false) {
    $fail('Geeklog search callback did not return expected local result.');
}

$criteria = plugin_dopluginsearch_videos(
    'Practical Ecology',
    '',
    '',
    '',
    'all',
    0,
    'phrase',
    1,
    10
);
if (!($criteria instanceof SearchCriteria) || $criteria->total !== 2) {
    $fail('Geeklog search callback did not search channel metadata.');
}

$_GET['title'] = '1';
$criteria = plugin_dopluginsearch_videos(
    'Practical Ecology',
    '',
    '',
    '',
    'videos',
    0,
    'phrase',
    1,
    10
);
unset($_GET['title']);
if (!($criteria instanceof SearchCriteria) || $criteria->total !== 0) {
    $fail('Title-only Geeklog search unexpectedly searched channel metadata.');
}

$criteria = plugin_dopluginsearch_videos(
    'rocket',
    '2025-01-01',
    '2025-12-31',
    '',
    'videos',
    0,
    'phrase',
    1,
    10
);
if (!($criteria instanceof SearchCriteria) ||
    $criteria->total !== 1 ||
    $criteria->results[0]['id'] !== 'BravoVid002') {
    $fail('Geeklog search date filtering failed.');
}

if (plugin_dopluginsearch_videos(
    'rocket', '', '', '', 'stories', 0, 'phrase', 1, 10
) !== false) {
    $fail('Geeklog search accepted unsupported search type.');
}
$criteria = plugin_dopluginsearch_videos(
    'rocket', '', '', '', 'videos', 42, 'phrase', 1, 10
);
if (!($criteria instanceof SearchCriteria) || $criteria->total !== 0) {
    $fail('Geeklog search author constraint should yield no Videos results.');
}

$summary = plugin_statssummary_videos();
if (!is_array($summary) || $summary !== array('Videos', '3')) {
    $fail('Videos statistics summary did not count public local inventory.');
}

$moderation = new Videos_Moderation($store);
if (!$moderation->setVideoState(
    'BravoVid002',
    'blocked',
    'Search stats fixture',
    str_repeat('c', 64)
)) {
    $fail('Unable to moderate search fixture.');
}
$criteria = plugin_dopluginsearch_videos(
    'rocket', '', '', '', 'videos', 0, 'phrase', 1, 10
);
if (!($criteria instanceof SearchCriteria) || $criteria->total !== 0) {
    $fail('Blocked video remains visible through Geeklog search callback.');
}
$summary = plugin_statssummary_videos();
if (!is_array($summary) || $summary !== array('Videos', '2')) {
    $fail('Videos statistics summary did not respect moderation visibility.');
}

$_CONF = $originalConf;
$_GET = $originalGet;
if ($originalVideosConf === null) {
    unset($GLOBALS['_VIDEOS_CONF']);
} else {
    $GLOBALS['_VIDEOS_CONF'] = $originalVideosConf;
}
videos_search_remove_tree($tempRoot);

echo 'Videos search/stats callbacks: OK (local search, dates, title-only, moderation, summary)'
    . PHP_EOL;

function videos_search_remove_tree($path)
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
            videos_search_remove_tree($path . DIRECTORY_SEPARATOR . $item);
        }
    }
    @rmdir($path);
}
