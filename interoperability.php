<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

/**
 * Return the canonical local URL for a Videos item.
 *
 * The two-argument signature is compatible with Geeklog 2.2.2 while the
 * implementation also tolerates the legacy one-argument direct call.
 */
function plugin_idtourl_videos($subType, $itemId = '')
{
    global $_CONF;

    if ($itemId === '') {
        $itemId = $subType;
    }
    $itemId = (string) $itemId;
    if (!Videos_Validator::youtubeVideoId($itemId)) {
        return '';
    }

    return rtrim($_CONF['site_url'], '/') . '/videos/watch.php?v=' . rawurlencode($itemId);
}

/**
 * Resolve a canonical Videos URL back to its content identity.
 */
function plugin_urltoid_videos($url)
{
    global $_CONF;

    $url = trim((string) $url);
    if ($url === '') {
        return array();
    }
    $site = parse_url($_CONF['site_url']);
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return array();
    }
    if (isset($site['host']) && isset($parts['host']) &&
        strcasecmp($site['host'], $parts['host']) !== 0) {
        return array();
    }
    $path = isset($parts['path']) ? $parts['path'] : '';
    if (substr($path, -17) !== '/videos/watch.php') {
        return array();
    }
    $query = array();
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    $id = isset($query['v']) ? (string) $query['v'] : '';
    if (!Videos_Validator::youtubeVideoId($id)) {
        return array();
    }

    return array('type' => 'videos', 'id' => $id, 'subtype' => 'video');
}

function VIDEOS_interopBootstrap()
{
    global $_CONF;

    $bootstrap = new Videos_Bootstrap($_CONF);
    return $bootstrap->isReady() ? $bootstrap : false;
}

function VIDEOS_publicPoolRecords($bootstrap)
{
    $pool = new Videos_PermanentPool(
        $bootstrap->getStore(),
        new Videos_Cache($bootstrap->getStore())
    );
    $records = $pool->records();
    return isset($records['items']) && is_array($records['items'])
        ? $records['items'] : array();
}

function VIDEOS_itemInfoRecord($videoId, $bootstrap, $poolItem = array())
{
    if (!Videos_Validator::youtubeVideoId($videoId)) {
        return array();
    }
    $cache = new Videos_Cache($bootstrap->getStore());
    $moderation = new Videos_Moderation($bootstrap->getStore());
    $video = $cache->getVideo($videoId, true);
    if (!is_array($video) || $cache->isVideoUnavailable($videoId)) {
        return array();
    }
    $snippet = isset($video['snippet']) && is_array($video['snippet'])
        ? $video['snippet'] : array();
    $channelId = isset($snippet['channelId']) ? (string) $snippet['channelId'] : '';
    if ($moderation->isVideoBlocked($videoId) ||
        $moderation->isChannelExcluded($channelId)) {
        return array();
    }

    $title = isset($snippet['title']) ? trim((string) $snippet['title']) : $videoId;
    $channel = isset($snippet['channelTitle']) ? trim((string) $snippet['channelTitle']) : '';
    $descriptionService = new Videos_Description();
    $description = $descriptionService->excerpt(
        isset($snippet['description']) ? $snippet['description'] : '',
        isset($GLOBALS['_VIDEOS_CONF']['description_mode'])
            ? $GLOBALS['_VIDEOS_CONF']['description_mode'] : 'clean'
    );
    $thumbnail = '';
    if (isset($snippet['thumbnails']) && is_array($snippet['thumbnails'])) {
        foreach (array('maxres', 'standard', 'high', 'medium', 'default') as $size) {
            if (!empty($snippet['thumbnails'][$size]['url'])) {
                $thumbnail = (string) $snippet['thumbnails'][$size]['url'];
                break;
            }
        }
    }
    $created = !empty($snippet['publishedAt'])
        ? (string) $snippet['publishedAt'] : '';
    $modified = !empty($poolItem['admitted_at'])
        ? (string) $poolItem['admitted_at'] : $created;

    return array(
        'id' => $videoId,
        'type' => 'videos',
        'subtype' => 'video',
        'title' => $title,
        'url' => plugin_idtourl_videos('video', $videoId),
        'description' => $description,
        'excerpt' => $description,
        'image' => $thumbnail,
        'date-created' => $created,
        'date-modified' => $modified,
        'uid' => 0,
        'author' => $channel
    );
}

function VIDEOS_filterItemInfo($record, $what)
{
    if (!is_array($record) || empty($record)) {
        return array();
    }
    if (is_array($what)) {
        $fields = $what;
    } else {
        $what = trim((string) $what);
        if ($what === '' || $what === '*') {
            return $record;
        }
        $fields = preg_split('/\s*,\s*/', $what, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($fields) || count($fields) === 0) {
        return $record;
    }
    if (count($fields) === 1) {
        $field = $fields[0];
        return isset($record[$field]) ? $record[$field] : '';
    }
    $result = array();
    foreach ($fields as $field) {
        if (array_key_exists($field, $record)) {
            $result[$field] = $record[$field];
        }
    }
    return $result;
}

/**
 * Structured item metadata and collection API for Hello, Hub, XML Sitemap and
 * other generic Geeklog consumers.
 */
function plugin_getiteminfo_videos($id, $what, $uid = 0, $options = array())
{
    $bootstrap = VIDEOS_interopBootstrap();
    if ($bootstrap === false) {
        return $id === '*' ? array() : '';
    }
    $poolItems = VIDEOS_publicPoolRecords($bootstrap);

    if ($id !== '*') {
        if (!isset($poolItems[$id])) {
            return '';
        }
        return VIDEOS_filterItemInfo(
            VIDEOS_itemInfoRecord($id, $bootstrap, $poolItems[$id]),
            $what
        );
    }

    $since = isset($options['since']) ? $options['since'] : 0;
    if (!is_numeric($since)) {
        $since = strtotime((string) $since);
    }
    $since = $since === false ? 0 : (int) $since;
    $limit = isset($options['limit']) ? (int) $options['limit'] : 20;
    $limit = max(1, min(500, $limit));
    $order = isset($options['order']) ? (string) $options['order'] : 'modified-desc';

    $records = array();
    foreach ($poolItems as $videoId => $poolItem) {
        $record = VIDEOS_itemInfoRecord($videoId, $bootstrap, $poolItem);
        if (empty($record)) {
            continue;
        }
        $modified = !empty($record['date-modified'])
            ? strtotime($record['date-modified']) : 0;
        if ($since > 0 && ($modified === false || $modified < $since)) {
            continue;
        }
        $records[] = $record;
    }
    usort($records, function ($left, $right) use ($order) {
        $field = $order === 'created-desc' ? 'date-created' : 'date-modified';
        $leftTime = !empty($left[$field]) ? strtotime($left[$field]) : 0;
        $rightTime = !empty($right[$field]) ? strtotime($right[$field]) : 0;
        if ($leftTime == $rightTime) {
            return strcmp($left['id'], $right['id']);
        }
        return $leftTime > $rightTime ? -1 : 1;
    });
    $records = array_slice($records, 0, $limit);

    $result = array();
    foreach ($records as $record) {
        $result[] = VIDEOS_filterItemInfo($record, $what);
    }
    return $result;
}

function VIDEOS_thumbnailAlt($title, $channel = '')
{
    $title = trim(strip_tags((string) $title));
    $channel = trim(strip_tags((string) $channel));
    if ($channel !== '') {
        return $title . ' - ' . $channel;
    }
    return $title;
}

/**
 * Geeklog autotag: [videos:VIDEO_ID] or [videos:VIDEO_ID player].
 */
function plugin_autotags_videos($op, $content = '', $autotag = '')
{
    if ($op === 'tagname' || $op === 'permission' || $op === 'nopermission') {
        return 'videos';
    }
    if ($op !== 'parse' || !is_array($autotag)) {
        return $content;
    }
    $videoId = isset($autotag['parm1']) ? trim((string) $autotag['parm1']) : '';
    if (!Videos_Validator::youtubeVideoId($videoId)) {
        return $content;
    }
    $bootstrap = VIDEOS_interopBootstrap();
    if ($bootstrap === false) {
        return $content;
    }
    $poolItems = VIDEOS_publicPoolRecords($bootstrap);
    if (!isset($poolItems[$videoId])) {
        return $content;
    }
    $record = VIDEOS_itemInfoRecord($videoId, $bootstrap, $poolItems[$videoId]);
    if (empty($record)) {
        return $content;
    }
    $mode = isset($autotag['parm2']) ? strtolower(trim((string) $autotag['parm2'])) : '';
    $safeUrl = htmlspecialchars($record['url'], ENT_QUOTES, 'UTF-8');
    $safeTitle = htmlspecialchars($record['title'], ENT_QUOTES, 'UTF-8');
    $replacement = '';
    if ($mode === 'player') {
        $replacement = '<div class="videos-autotag-player"><iframe src="https://www.youtube-nocookie.com/embed/'
            . rawurlencode($videoId) . '?rel=0" title="' . $safeTitle
            . '" loading="lazy" allow="accelerometer; encrypted-media; picture-in-picture" allowfullscreen></iframe>'
            . '<p><a href="' . $safeUrl . '">' . $safeTitle . '</a></p></div>';
    } else {
        $replacement = '<article class="videos-autotag-card"><a href="' . $safeUrl . '">';
        if (!empty($record['image']) && strpos($record['image'], 'https://') === 0) {
            $replacement .= '<img loading="lazy" src="'
                . htmlspecialchars($record['image'], ENT_QUOTES, 'UTF-8')
                . '" alt="' . htmlspecialchars(
                    VIDEOS_thumbnailAlt($record['title'], $record['author']),
                    ENT_QUOTES,
                    'UTF-8'
                ) . '">';
        }
        $replacement .= '<span>' . $safeTitle . '</span></a></article>';
    }

    return str_replace($autotag['tagstr'], $replacement, $content);
}
