<?php

require_once '../lib-common.php';

$publicTitle = VIDEOS_getPublicTitle();
$bootstrap = new Videos_Bootstrap($_CONF);
if (empty($_VIDEOS_CONF['enabled']) || !$bootstrap->isReady()) {
    echo COM_createHTMLDocument(
        COM_showMessageText($LANG_VIDEOS['channels_unavailable'], '', true),
        array(
            'pagetitle' => $LANG_VIDEOS['channels_title'],
            'headercode' => '<meta name="robots" content="noindex,follow">'
        )
    );
    exit;
}

$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$moderation = new Videos_Moderation($store);
$ranking = (new Videos_ChannelRanking($store, $cache))->getGlobal(250);
$priorityIds = $moderation->getPriorityChannelIds(500);
$priority = array_fill_keys($priorityIds, true);
$pool = new Videos_PermanentPool($store, $cache);
$poolRecords = $pool->records();
$poolItems = isset($poolRecords['items']) && is_array($poolRecords['items'])
    ? $poolRecords['items'] : array();
$pinnedByChannel = array();
foreach ($poolItems as $videoId => $item) {
    if (empty($item['pinned'])) {
        continue;
    }
    $video = $cache->getVideo($videoId, true);
    $channelId = is_array($video) && !empty($video['snippet']['channelId'])
        ? (string) $video['snippet']['channelId'] : '';
    if (Videos_Validator::youtubeChannelId($channelId)) {
        if (!isset($pinnedByChannel[$channelId])) {
            $pinnedByChannel[$channelId] = 0;
        }
        $pinnedByChannel[$channelId]++;
    }
}

$ids = array();
foreach ($ranking as $channelId => $item) {
    if (!empty($item['video_count']) && (int) $item['video_count'] >= 2) {
        $ids[$channelId] = true;
    }
}
foreach ($priorityIds as $channelId) {
    $ids[$channelId] = true;
}
foreach ($pinnedByChannel as $channelId => $count) {
    $ids[$channelId] = true;
}

$channels = array();
foreach (array_keys($ids) as $channelId) {
    if (!VIDEOS_channelPageEligible($channelId, $bootstrap) ||
        $moderation->isChannelExcluded($channelId)) {
        continue;
    }
    $channel = $cache->getChannel($channelId, true);
    $snippet = is_array($channel) && !empty($channel['snippet']) && is_array($channel['snippet'])
        ? $channel['snippet'] : array();
    $statistics = is_array($channel) && !empty($channel['statistics']) && is_array($channel['statistics'])
        ? $channel['statistics'] : array();
    $item = isset($ranking[$channelId]) ? $ranking[$channelId] : array();
    $title = !empty($snippet['title'])
        ? trim((string) $snippet['title'])
        : (!empty($item['title']) ? trim((string) $item['title']) : $channelId);
    $thumb = '';
    if (!empty($snippet['thumbnails']) && is_array($snippet['thumbnails'])) {
        foreach (array('high', 'medium', 'default') as $size) {
            if (!empty($snippet['thumbnails'][$size]['url'])) {
                $thumb = (string) $snippet['thumbnails'][$size]['url'];
                break;
            }
        }
    }
    $channels[$channelId] = array(
        'title' => $title,
        'thumbnail' => $thumb,
        'priority' => isset($priority[$channelId]),
        'pinned_count' => isset($pinnedByChannel[$channelId]) ? (int) $pinnedByChannel[$channelId] : 0,
        'video_count' => isset($item['video_count']) ? (int) $item['video_count'] : 0,
        'view_count' => isset($item['view_count']) ? (int) $item['view_count'] : 0,
        'rating_average' => isset($item['rating_average']) ? (float) $item['rating_average'] : 0,
        'rating_count' => isset($item['rating_count']) ? (int) $item['rating_count'] : 0,
        'subscriber_count' => isset($statistics['subscriberCount']) && empty($statistics['hiddenSubscriberCount'])
            ? (int) $statistics['subscriberCount'] : null,
        'score' => isset($item['score']) ? (float) $item['score'] : 0
    );
}

uasort($channels, function ($left, $right) {
    if ($left['priority'] !== $right['priority']) {
        return $left['priority'] ? -1 : 1;
    }
    if ($left['pinned_count'] !== $right['pinned_count']) {
        return $left['pinned_count'] > $right['pinned_count'] ? -1 : 1;
    }
    if ($left['score'] != $right['score']) {
        return $left['score'] > $right['score'] ? -1 : 1;
    }
    return strcasecmp($left['title'], $right['title']);
});

$canonical = plugin_idtourl_videos('', 'channels');
$description = $LANG_VIDEOS['channels_meta_description'];
$header = '<link rel="canonical" href="'
    . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">\n'
    . '<meta name="robots" content="' . (count($channels) > 0 ? 'index,follow' : 'noindex,follow') . '">\n'
    . '<meta name="description" content="' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '">';

$html = '<div class="videos-page videos-channels-page">'
    . VIDEOS_renderNavigation('channels')
    . '<h1>' . htmlspecialchars($LANG_VIDEOS['channels_title'], ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<p class="videos-rankings-intro">'
    . htmlspecialchars($LANG_VIDEOS['channels_intro'], ENT_QUOTES, 'UTF-8') . '</p>';

if (count($channels) === 0) {
    $html .= '<p>' . htmlspecialchars($LANG_VIDEOS['channels_empty'], ENT_QUOTES, 'UTF-8') . '</p>';
} else {
    $html .= '<div class="videos-grid">';
    foreach ($channels as $channelId => $item) {
        $url = plugin_idtourl_videos('', 'channel:' . $channelId);
        $html .= '<article class="videos-card videos-channel-card">';
        if ($item['thumbnail'] !== '' && strpos($item['thumbnail'], 'https://') === 0) {
            $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"><img loading="lazy" src="'
                . htmlspecialchars($item['thumbnail'], ENT_QUOTES, 'UTF-8') . '" alt="'
                . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '"></a>';
        }
        $html .= '<div class="videos-card-content"><h2><a href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') . '</a></h2>';
        if ($item['priority']) {
            $html .= '<span class="videos-card-badge">'
                . htmlspecialchars($LANG_VIDEOS['channel_priority_badge'], ENT_QUOTES, 'UTF-8') . '</span>';
        }
        if ($item['pinned_count'] > 0) {
            $label = $item['pinned_count'] > 1
                ? $LANG_VIDEOS['channel_pinned_videos'] : $LANG_VIDEOS['channel_pinned_video'];
            $html .= '<span class="videos-card-badge videos-pool-badge">'
                . $item['pinned_count'] . ' ' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $html .= '<p class="videos-card-meta">'
            . htmlspecialchars($LANG_VIDEOS['channel_local_videos'], ENT_QUOTES, 'UTF-8') . ': '
            . COM_numberFormat($item['video_count']) . '</p>';
        if ($item['rating_count'] > 0) {
            $html .= '<p class="videos-card-meta">'
                . htmlspecialchars($LANG_VIDEOS['local_average'], ENT_QUOTES, 'UTF-8') . ': '
                . number_format($item['rating_average'], 2, ',', ' ') . '/5 ('
                . COM_numberFormat($item['rating_count']) . ')</p>';
        }
        if ($item['view_count'] > 0) {
            $html .= '<p class="videos-card-meta">'
                . htmlspecialchars($LANG_VIDEOS['ranking_views'], ENT_QUOTES, 'UTF-8') . ': '
                . COM_numberFormat($item['view_count']) . '</p>';
        }
        if ($item['subscriber_count'] !== null) {
            $html .= '<p class="videos-card-meta">'
                . htmlspecialchars($LANG_VIDEOS['channel_subscribers'], ENT_QUOTES, 'UTF-8') . ': '
                . COM_numberFormat($item['subscriber_count']) . '</p>';
        }
        $html .= '<p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($LANG_VIDEOS['channels_view_channel'], ENT_QUOTES, 'UTF-8')
            . '</a></p></div></article>';
    }
    $html .= '</div>';
}
$html .= '</div>';

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $LANG_VIDEOS['channels_title'] . ' - ' . $publicTitle,
        'headercode' => $header
    )
);
