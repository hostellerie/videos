<?php

require_once '../lib-common.php';

$channelId = isset($_GET['id']) ? COM_applyFilter($_GET['id']) : '';
if (!Videos_Validator::youtubeChannelId($channelId) || empty($_VIDEOS_CONF['enabled'])) {
    echo COM_createHTMLDocument(COM_showMessageText('Chaîne vidéo indisponible.', '', true), array('pagetitle' => VIDEOS_getPublicTitle(), 'headercode' => '<meta name="robots" content="noindex,nofollow">'));
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    echo COM_createHTMLDocument(COM_showMessageText('Chaîne vidéo temporairement indisponible.', '', true), array('pagetitle' => VIDEOS_getPublicTitle(), 'headercode' => '<meta name="robots" content="noindex,nofollow">'));
    exit;
}

$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$moderation = new Videos_Moderation($store);
if ($moderation->isChannelExcluded($channelId)) {
    echo COM_createHTMLDocument(COM_showMessageText('Cette chaîne n’est pas publiée.', '', true), array('pagetitle' => VIDEOS_getPublicTitle(), 'headercode' => '<meta name="robots" content="noindex,nofollow">'));
    exit;
}

$channel = $cache->getChannel($channelId, true);
$snippet = is_array($channel) && isset($channel['snippet']) ? $channel['snippet'] : array();
$title = !empty($snippet['title']) ? trim((string) $snippet['title']) : $channelId;
$description = !empty($snippet['description']) ? trim(strip_tags((string) $snippet['description'])) : '';
$channelRanking = (new Videos_ChannelRanking($store, $cache))->getGlobal(250);
$rank = isset($channelRanking[$channelId]) ? $channelRanking[$channelId] : array();
$state = $moderation->getChannelState($channelId);
$priority = isset($state['state']) && $state['state'] === 'priority';
$remarkable = !empty($rank) && !empty($rank['video_count']) && (int) $rank['video_count'] >= 2;

$ranking = new Videos_Ranking($store, new Videos_RatingStats($store), new Videos_VideoStats($store), $cache);
$candidates = $ranking->getGlobal(500);
$videos = array();
foreach ($candidates as $videoId => $item) {
    if (empty($item['channel_id']) || (string) $item['channel_id'] !== $channelId) {
        continue;
    }
    $video = $cache->getVideo($videoId, true);
    if (!is_array($video) || $cache->isVideoUnavailable($videoId) || $moderation->isVideoBlocked($videoId) || Videos_VideoPolicy::excludesShortVideo($video, $_VIDEOS_CONF)) {
        continue;
    }
    $videos[$videoId] = $video;
    if (count($videos) >= 20) {
        break;
    }
}

$pool = new Videos_PermanentPool($store, $cache);
$poolRecords = $pool->records();
foreach (isset($poolRecords['items']) ? $poolRecords['items'] : array() as $videoId => $item) {
    $video = $cache->getVideo($videoId, true);
    if (is_array($video) && !empty($video['snippet']['channelId']) && (string) $video['snippet']['channelId'] === $channelId && !$moderation->isVideoBlocked($videoId)) {
        $videos[$videoId] = $video;
    }
}

if ((!$priority && !$remarkable) || count($videos) === 0) {
    echo COM_createHTMLDocument(COM_showMessageText('Cette chaîne ne dispose pas encore d’une sélection éditoriale suffisante.', '', true), array('pagetitle' => $title, 'headercode' => '<meta name="robots" content="noindex,follow">'));
    exit;
}

$canonical = plugin_idtourl_videos('', 'channel:' . $channelId);
$meta = $description !== '' ? $description : 'Découvrez les vidéos remarquables de ' . $title . ' sélectionnées dans ' . VIDEOS_getPublicTitle() . '.';
$meta = function_exists('MBYTE_substr') ? MBYTE_substr($meta, 0, 160) : substr($meta, 0, 160);
$header = '<link rel="canonical" href="' . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">\n<meta name="robots" content="index,follow">\n<meta name="description" content="' . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '">';

$html = '<div class="videos-page videos-channel-page">' . VIDEOS_renderNavigation('') . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
if ($priority) {
    $html .= '<p class="videos-card-badge">Chaîne prioritaire</p>';
}
if ($description !== '') {
    $html .= '<p class="videos-description">' . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
}
$html .= '<section><h2>Vidéos remarquables</h2><div class="videos-grid">';
foreach ($videos as $videoId => $video) {
    $vs = isset($video['snippet']) ? $video['snippet'] : array();
    $videoTitle = !empty($vs['title']) ? $vs['title'] : $videoId;
    $thumb = isset($vs['thumbnails']['medium']['url']) ? $vs['thumbnails']['medium']['url'] : '';
    $url = plugin_idtourl_videos('', $videoId);
    $html .= '<article class="videos-card"><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    if (strpos($thumb, 'https://') === 0) {
        $html .= '<img loading="lazy" src="' . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars(VIDEOS_thumbnailAlt($videoTitle, $title), ENT_QUOTES, 'UTF-8') . '">';
    }
    $html .= '</a><div class="videos-card-content"><h3><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($videoTitle, ENT_QUOTES, 'UTF-8') . '</a></h3></div></article>';
}
$html .= '</div></section></div>';

echo COM_createHTMLDocument($html, array('pagetitle' => $title . ' - ' . VIDEOS_getPublicTitle(), 'headercode' => $header));
