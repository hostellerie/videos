<?php

require_once '../lib-common.php';

$channelId = isset($_GET['id']) ? COM_applyFilter($_GET['id']) : '';
if (!Videos_Validator::youtubeChannelId($channelId) || empty($_VIDEOS_CONF['enabled'])) {
    echo COM_createHTMLDocument(
        COM_showMessageText('Chaîne vidéo indisponible.', '', true),
        array(
            'pagetitle' => VIDEOS_getPublicTitle(),
            'headercode' => '<meta name="robots" content="noindex,nofollow">'
        )
    );
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    echo COM_createHTMLDocument(
        COM_showMessageText('Chaîne vidéo temporairement indisponible.', '', true),
        array(
            'pagetitle' => VIDEOS_getPublicTitle(),
            'headercode' => '<meta name="robots" content="noindex,nofollow">'
        )
    );
    exit;
}

$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$moderation = new Videos_Moderation($store);
if ($moderation->isChannelExcluded($channelId)) {
    echo COM_createHTMLDocument(
        COM_showMessageText('Cette chaîne n’est pas publiée.', '', true),
        array(
            'pagetitle' => VIDEOS_getPublicTitle(),
            'headercode' => '<meta name="robots" content="noindex,nofollow">'
        )
    );
    exit;
}

$channel = $cache->getChannel($channelId, true);
$snippet = is_array($channel) && isset($channel['snippet']) && is_array($channel['snippet'])
    ? $channel['snippet'] : array();
$statistics = is_array($channel) && isset($channel['statistics']) && is_array($channel['statistics'])
    ? $channel['statistics'] : array();
$title = !empty($snippet['title']) ? trim((string) $snippet['title']) : $channelId;
$description = !empty($snippet['description'])
    ? trim(strip_tags((string) $snippet['description'])) : '';
$thumbnail = '';
if (!empty($snippet['thumbnails']) && is_array($snippet['thumbnails'])) {
    foreach (array('high', 'medium', 'default') as $size) {
        if (!empty($snippet['thumbnails'][$size]['url'])) {
            $thumbnail = (string) $snippet['thumbnails'][$size]['url'];
            break;
        }
    }
}
$publishedAt = !empty($snippet['publishedAt']) ? strtotime($snippet['publishedAt']) : false;
$subscriberCount = isset($statistics['subscriberCount']) ? (int) $statistics['subscriberCount'] : null;
$viewCount = isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null;
$youtubeVideoCount = isset($statistics['videoCount']) ? (int) $statistics['videoCount'] : null;
$hiddenSubscribers = !empty($statistics['hiddenSubscriberCount']);

$channelRanking = (new Videos_ChannelRanking($store, $cache))->getGlobal(250);
$rank = isset($channelRanking[$channelId]) ? $channelRanking[$channelId] : array();
$state = $moderation->getChannelState($channelId);
$priority = isset($state['state']) && $state['state'] === 'priority';

$ranking = new Videos_Ranking(
    $store,
    new Videos_RatingStats($store),
    new Videos_VideoStats($store),
    $cache
);
$candidates = $ranking->getGlobal(500);
$videos = array();
foreach ($candidates as $videoId => $item) {
    if (empty($item['channel_id']) || (string) $item['channel_id'] !== $channelId) {
        continue;
    }
    $video = $cache->getVideo($videoId, true);
    if (!is_array($video) || $cache->isVideoUnavailable($videoId) ||
        $moderation->isVideoBlocked($videoId) ||
        Videos_VideoPolicy::excludesShortVideo($video, $_VIDEOS_CONF)) {
        continue;
    }
    $videos[$videoId] = $video;
    if (count($videos) >= 20) {
        break;
    }
}

$pool = new Videos_PermanentPool($store, $cache);
$poolRecords = $pool->records();
$poolItems = isset($poolRecords['items']) && is_array($poolRecords['items'])
    ? $poolRecords['items'] : array();
$pinnedCount = 0;
$permanentCount = 0;
foreach ($poolItems as $videoId => $item) {
    $video = $cache->getVideo($videoId, true);
    if (!is_array($video) || empty($video['snippet']['channelId']) ||
        (string) $video['snippet']['channelId'] !== $channelId ||
        $cache->isVideoUnavailable($videoId) ||
        $moderation->isVideoBlocked($videoId)) {
        continue;
    }
    $permanentCount++;
    if (!empty($item['pinned'])) {
        $pinnedCount++;
    }
    $videos[$videoId] = $video;
}

if (!VIDEOS_channelPageEligible($channelId, $bootstrap) || count($videos) === 0) {
    echo COM_createHTMLDocument(
        COM_showMessageText(
            'Cette chaîne ne dispose pas encore d’une sélection éditoriale suffisante.',
            '',
            true
        ),
        array(
            'pagetitle' => $title,
            'headercode' => '<meta name="robots" content="noindex,follow">'
        )
    );
    exit;
}

$canonical = plugin_idtourl_videos('', 'channel:' . $channelId);
$meta = $description !== ''
    ? $description
    : 'Découvrez les vidéos remarquables de ' . $title
        . ' sélectionnées dans ' . VIDEOS_getPublicTitle() . '.';
$meta = function_exists('MBYTE_substr')
    ? MBYTE_substr($meta, 0, 160) : substr($meta, 0, 160);
$header = '<link rel="canonical" href="'
    . htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') . '">\n'
    . '<meta name="robots" content="index,follow">\n'
    . '<meta name="description" content="'
    . htmlspecialchars($meta, ENT_QUOTES, 'UTF-8') . '">\n'
    . '<style>'
    . '.videos-channel-header{display:flex;gap:1.25rem;align-items:center;margin:0 0 1.25rem}'
    . '.videos-channel-avatar{width:112px;height:112px;border-radius:50%;object-fit:cover;flex:0 0 112px}'
    . '.videos-channel-heading h1{margin:0 0 .45rem}'
    . '.videos-channel-facts{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;margin:1rem 0 1.5rem}'
    . '.videos-channel-fact{padding:.85rem;border:1px solid rgba(127,127,127,.28);border-radius:.5rem;background:rgba(127,127,127,.05)}'
    . '.videos-channel-fact strong,.videos-channel-fact span{display:block}.videos-channel-fact strong{font-size:1.15rem}.videos-channel-fact span{margin-top:.25rem;opacity:.75;font-size:.88rem}'
    . '.videos-channel-about{margin:1.25rem 0}.videos-channel-about p{line-height:1.6}'
    . '.videos-channel-external{margin:1rem 0 1.5rem}'
    . '@media(max-width:600px){.videos-channel-header{align-items:flex-start}.videos-channel-avatar{width:80px;height:80px;flex-basis:80px}}'
    . '</style>';

$html = '<div class="videos-page videos-channel-page">'
    . VIDEOS_renderNavigation('')
    . '<header class="videos-channel-header">';
if ($thumbnail !== '' && strpos($thumbnail, 'https://') === 0) {
    $html .= '<img class="videos-channel-avatar" src="'
        . htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8')
        . '" alt="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '">';
}
$html .= '<div class="videos-channel-heading"><h1>'
    . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
if ($priority) {
    $html .= '<span class="videos-card-badge">Chaîne prioritaire</span>';
}
if ($pinnedCount > 0) {
    $html .= '<span class="videos-card-badge videos-pool-badge">'
        . $pinnedCount . ' vidéo' . ($pinnedCount > 1 ? 's' : '') . ' épinglée'
        . ($pinnedCount > 1 ? 's' : '') . '</span>';
}
$html .= '</div></header>';

$html .= '<section class="videos-channel-facts" aria-label="Informations sur la chaîne">';
if ($subscriberCount !== null && !$hiddenSubscribers) {
    $html .= videos_channel_fact('Abonnés', COM_numberFormat($subscriberCount));
}
if ($viewCount !== null) {
    $html .= videos_channel_fact('Vues YouTube', COM_numberFormat($viewCount));
}
if ($youtubeVideoCount !== null) {
    $html .= videos_channel_fact('Vidéos sur YouTube', COM_numberFormat($youtubeVideoCount));
}
if (!empty($rank['video_count'])) {
    $html .= videos_channel_fact('Vidéos remarquables locales', COM_numberFormat((int) $rank['video_count']));
}
if ($permanentCount > 0) {
    $html .= videos_channel_fact('Sélection permanente', COM_numberFormat($permanentCount));
}
if ($publishedAt !== false) {
    $html .= videos_channel_fact('Chaîne créée', date('d/m/Y', $publishedAt));
}
$html .= '</section>';

if ($description !== '') {
    $html .= '<section class="videos-channel-about"><h2>À propos de la chaîne</h2><p>'
        . nl2br(htmlspecialchars($description, ENT_QUOTES, 'UTF-8'))
        . '</p></section>';
}

$html .= '<p class="videos-channel-external"><a rel="noopener noreferrer" target="_blank" href="https://www.youtube.com/channel/'
    . rawurlencode($channelId) . '">Voir la chaîne sur YouTube</a></p>';

$html .= '<section><h2>Vidéos remarquables</h2><div class="videos-grid">';
foreach ($videos as $videoId => $video) {
    $vs = isset($video['snippet']) ? $video['snippet'] : array();
    $videoTitle = !empty($vs['title']) ? $vs['title'] : $videoId;
    $thumb = isset($vs['thumbnails']['medium']['url'])
        ? $vs['thumbnails']['medium']['url'] : '';
    $url = plugin_idtourl_videos('', $videoId);
    $html .= '<article class="videos-card"><a href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    if (strpos($thumb, 'https://') === 0) {
        $html .= '<img loading="lazy" src="'
            . htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8')
            . '" alt="'
            . htmlspecialchars(VIDEOS_thumbnailAlt($videoTitle, $title), ENT_QUOTES, 'UTF-8')
            . '">';
    }
    $html .= '</a><div class="videos-card-content"><h3><a href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($videoTitle, ENT_QUOTES, 'UTF-8')
        . '</a></h3>';
    if (isset($poolItems[$videoId])) {
        $html .= '<span class="videos-card-badge videos-pool-badge">Sélection permanente</span>';
        if (!empty($poolItems[$videoId]['pinned'])) {
            $html .= '<span class="videos-card-badge">Épinglée</span>';
        }
    }
    $html .= '</div></article>';
}
$html .= '</div></section></div>';

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $title . ' - ' . VIDEOS_getPublicTitle(),
        'headercode' => $header
    )
);

function videos_channel_fact($label, $value)
{
    return '<div class="videos-channel-fact"><strong>'
        . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
        . '</strong><span>'
        . htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8')
        . '</span></div>';
}
