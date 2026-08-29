<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

function plugin_idtourl_videos($subType, $itemId = '')
{
    global $_CONF;
    if ($itemId === '') {
        $itemId = $subType;
    }
    $itemId = (string) $itemId;
    $base = rtrim($_CONF['site_url'], '/') . '/videos/';
    if ($itemId === 'catalogue') {
        return $base . 'index.php';
    }
    if ($itemId === 'channels') {
        return $base . 'channels.php';
    }
    if ($itemId === 'rankings:videos') {
        return $base . 'rankings.php?tab=videos';
    }
    if ($itemId === 'rankings:channels') {
        return $base . 'rankings.php?tab=channels';
    }
    if (strpos($itemId, 'channel:') === 0) {
        $channelId = substr($itemId, 8);
        return Videos_Validator::youtubeChannelId($channelId)
            ? $base . 'channel.php?id=' . rawurlencode($channelId) : '';
    }
    return Videos_Validator::youtubeVideoId($itemId)
        ? $base . 'watch.php?v=' . rawurlencode($itemId) : '';
}

function plugin_urltoid_videos($url)
{
    global $_CONF;
    $url = trim((string) $url);
    $parts = parse_url($url);
    $site = parse_url($_CONF['site_url']);
    if ($url === '' || !is_array($parts) ||
        (isset($site['host'], $parts['host']) &&
         strcasecmp($site['host'], $parts['host']) !== 0)) {
        return array();
    }
    $path = isset($parts['path']) ? $parts['path'] : '';
    $query = array();
    if (!empty($parts['query'])) {
        parse_str($parts['query'], $query);
    }
    if (substr($path, -17) === '/videos/watch.php') {
        $id = isset($query['v']) ? (string) $query['v'] : '';
        return Videos_Validator::youtubeVideoId($id)
            ? array('type' => 'videos', 'id' => $id, 'subtype' => 'video') : array();
    }
    if (substr($path, -19) === '/videos/channel.php') {
        $id = isset($query['id']) ? (string) $query['id'] : '';
        return Videos_Validator::youtubeChannelId($id)
            ? array('type' => 'videos', 'id' => 'channel:' . $id, 'subtype' => 'channel') : array();
    }
    if (substr($path, -17) === '/videos/index.php') {
        return array('type' => 'videos', 'id' => 'catalogue', 'subtype' => 'collection');
    }
    if (substr($path, -20) === '/videos/channels.php') {
        return array('type' => 'videos', 'id' => 'channels', 'subtype' => 'collection');
    }
    if (substr($path, -20) === '/videos/rankings.php') {
        $tab = isset($query['tab']) && $query['tab'] === 'channels' ? 'channels' : 'videos';
        return array('type' => 'videos', 'id' => 'rankings:' . $tab, 'subtype' => 'ranking');
    }
    return array();
}

function VIDEOS_signalSaved($id)
{
    if (function_exists('PLG_itemSaved')) {
        PLG_itemSaved($id, 'videos');
    }
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
    $created = !empty($snippet['publishedAt']) ? (string) $snippet['publishedAt'] : '';
    $modified = !empty($poolItem['admitted_at']) ? (string) $poolItem['admitted_at'] : $created;
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

function VIDEOS_structureInfoRecord($id, $bootstrap)
{
    global $LANG_VIDEOS;
    $now = gmdate('Y-m-d\\TH:i:s\\Z');
    if ($id === 'catalogue') {
        return array('id' => $id, 'type' => 'videos', 'subtype' => 'collection',
            'title' => VIDEOS_getPublicTitle(), 'url' => plugin_idtourl_videos('', $id),
            'description' => VIDEOS_getPublicTitle(), 'excerpt' => VIDEOS_getPublicTitle(),
            'image' => '', 'date-created' => '', 'date-modified' => $now, 'uid' => 0, 'author' => '');
    }
    if ($id === 'channels') {
        $title = isset($LANG_VIDEOS['channels_title'])
            ? $LANG_VIDEOS['channels_title'] : 'Recommended video channels';
        return array('id' => $id, 'type' => 'videos', 'subtype' => 'collection',
            'title' => $title, 'url' => plugin_idtourl_videos('', $id),
            'description' => $title, 'excerpt' => $title, 'image' => '',
            'date-created' => '', 'date-modified' => $now, 'uid' => 0, 'author' => '');
    }
    if ($id === 'rankings:videos' || $id === 'rankings:channels') {
        $title = isset($LANG_VIDEOS['public_rankings_title'])
            ? $LANG_VIDEOS['public_rankings_title'] : 'Classements vidéos';
        return array('id' => $id, 'type' => 'videos', 'subtype' => 'ranking',
            'title' => $title, 'url' => plugin_idtourl_videos('', $id),
            'description' => $title, 'excerpt' => $title, 'image' => '',
            'date-created' => '', 'date-modified' => $now, 'uid' => 0, 'author' => '');
    }
    if (strpos($id, 'channel:') === 0) {
        $channelId = substr($id, 8);
        if (!Videos_Validator::youtubeChannelId($channelId)) {
            return array();
        }
        $cache = new Videos_Cache($bootstrap->getStore());
        $channel = $cache->getChannel($channelId, true);
        $snippet = is_array($channel) && isset($channel['snippet'])
            ? $channel['snippet'] : array();
        $title = !empty($snippet['title']) ? trim((string) $snippet['title']) : $channelId;
        $description = !empty($snippet['description'])
            ? trim(strip_tags((string) $snippet['description'])) : $title;
        return array('id' => $id, 'type' => 'videos', 'subtype' => 'channel',
            'title' => $title, 'url' => plugin_idtourl_videos('', $id),
            'description' => $description, 'excerpt' => $description,
            'image' => '', 'date-created' => '', 'date-modified' => $now,
            'uid' => 0, 'author' => $title);
    }
    return array();
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
        $fields = preg_split('/\\s*,\\s*/', $what, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($fields) || count($fields) === 0) {
        return $record;
    }
    if (count($fields) === 1) {
        return isset($record[$fields[0]]) ? $record[$fields[0]] : '';
    }
    $result = array();
    foreach ($fields as $field) {
        if (array_key_exists($field, $record)) {
            $result[$field] = $record[$field];
        }
    }
    return $result;
}

function plugin_getiteminfo_videos($id, $what, $uid = 0, $options = array())
{
    $bootstrap = VIDEOS_interopBootstrap();
    if ($bootstrap === false) {
        return $id === '*' ? array() : '';
    }
    $poolItems = VIDEOS_publicPoolRecords($bootstrap);
    if ($id !== '*') {
        if (Videos_Validator::youtubeVideoId($id)) {
            $record = VIDEOS_itemInfoRecord(
                $id,
                $bootstrap,
                isset($poolItems[$id]) ? $poolItems[$id] : array()
            );
        } else {
            $record = VIDEOS_structureInfoRecord($id, $bootstrap);
        }
        return empty($record) ? '' : VIDEOS_filterItemInfo($record, $what);
    }

    // Collections intentionally expose the editorial/persistent corpus to
    // consumers such as Hello, not every transient discovery-cache entry.
    $since = isset($options['since']) ? $options['since'] : 0;
    if (!is_numeric($since)) {
        $since = strtotime((string) $since);
    }
    $since = $since === false ? 0 : (int) $since;
    $limit = isset($options['limit']) ? max(1, min(500, (int) $options['limit'])) : 20;
    $order = isset($options['order']) ? (string) $options['order'] : 'modified-desc';
    $records = array();
    foreach ($poolItems as $videoId => $poolItem) {
        $record = VIDEOS_itemInfoRecord($videoId, $bootstrap, $poolItem);
        if (empty($record)) {
            continue;
        }
        $modified = !empty($record['date-modified']) ? strtotime($record['date-modified']) : 0;
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
    return $channel !== '' ? $title . ' - ' . $channel : $title;
}

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
    $poolItems = $bootstrap === false ? array() : VIDEOS_publicPoolRecords($bootstrap);
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
