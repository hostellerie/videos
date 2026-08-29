<?php

require_once '../../../lib-common.php';

if (!SEC_hasRights('videos.admin')) {
    echo COM_createHTMLDocument(
        COM_showMessageText($LANG_VIDEOS['access_denied'], '', true),
        array('pagetitle' => $LANG_VIDEOS['admin_title'], 'headercode' => VIDEOS_adminHeaderCode())
    );
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    echo COM_createHTMLDocument(
        COM_showMessageText('Le stockage du plugin Videos est indisponible.', '', true),
        array('pagetitle' => 'Videos — Statistiques', 'headercode' => VIDEOS_adminHeaderCode())
    );
    exit;
}

$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$quota = new Videos_Quota($store);
$quotaStatus = $quota->status();
$quotaData = isset($quotaStatus['data']) && is_array($quotaStatus['data']) ? $quotaStatus['data'] : array();
$counts = isset($quotaData['counts']) && is_array($quotaData['counts']) ? $quotaData['counts'] : array();
$cacheStatus = (new Videos_CacheMaintenance($store))->inspect();
$reservoirStatus = (new Videos_DiscoveryReservoir($store, $cache))->status();
$pool = new Videos_PermanentPool($store, $cache);
$poolStatus = $pool->status();
$ranking = new Videos_Ranking($store, new Videos_RatingStats($store), new Videos_VideoStats($store), $cache);
$videoRanking = $ranking->getGlobal(500);
$channelRanking = (new Videos_ChannelRanking($store, $cache))->getGlobal(250);
$moderation = new Videos_Moderation($store);
$priorityChannels = $moderation->getPriorityChannelIds(500);

$seoDiagnostic = '';
$seoDiagnosticVideoId = '';
if (count($videoRanking) > 0) {
    $rankingIds = array_keys($videoRanking);
    $seoDiagnosticVideoId = reset($rankingIds);
    $seoDiagnosticVideo = $cache->getVideo($seoDiagnosticVideoId, true);
    if (is_array($seoDiagnosticVideo)) {
        $descriptionService = new Videos_Description();
        $description = $descriptionService->excerpt(
            isset($seoDiagnosticVideo['snippet']['description'])
                ? $seoDiagnosticVideo['snippet']['description'] : '',
            isset($_VIDEOS_CONF['description_mode'])
                ? $_VIDEOS_CONF['description_mode'] : 'clean'
        );
        $seo = new Videos_Seo(
            $_CONF['site_url'],
            isset($_CONF['site_name']) ? $_CONF['site_name'] : '',
            $_VIDEOS_CONF
        );
        $embedHost = !empty($_VIDEOS_CONF['privacy_enhanced_embed'])
            ? 'https://www.youtube-nocookie.com' : 'https://www.youtube.com';
        $seoDiagnostic = $seo->video(
            $seoDiagnosticVideoId,
            $seoDiagnosticVideo,
            $description,
            $embedHost . '/embed/' . rawurlencode($seoDiagnosticVideoId)
        );
    }
}

$html = '<div class="videos-admin"><h1>Videos — Statistiques</h1>'
    . videos_stats_nav($_CONF, 'stats')
    . '<section class="videos-admin-section"><h2>Contenu</h2><ul class="videos-admin-status">'
    . '<li>Vidéos dans le réservoir : ' . (int) $reservoirStatus['item_count'] . '</li>'
    . '<li>Vidéos dans le classement global : ' . count($videoRanking) . '</li>'
    . '<li>Chaînes dans le classement : ' . count($channelRanking) . '</li>'
    . '<li>Chaînes prioritaires : ' . count($priorityChannels) . '</li>'
    . '<li>Vidéos conservées dans le catalogue permanent : ' . (int) $poolStatus['item_count'] . '</li>'
    . '<li>Vidéos épinglées : ' . (isset($poolStatus['pinned_count']) ? (int) $poolStatus['pinned_count'] : 0) . '</li>'
    . '<li>Vidéos exclues du fonds : ' . (int) $poolStatus['excluded_count'] . '</li>'
    . '</ul></section>';

$html .= '<section class="videos-admin-section"><h2>Quota YouTube</h2><ul class="videos-admin-status">'
    . '<li>Recherches aujourd’hui : ' . (isset($counts['search']) ? (int) $counts['search'] : 0) . '</li>'
    . '<li>Appels vidéos : ' . (isset($counts['videos']) ? (int) $counts['videos'] : 0) . '</li>'
    . '<li>Appels chaînes : ' . (isset($counts['channels']) ? (int) $counts['channels'] : 0) . '</li>'
    . '<li>Quota suspendu : ' . (!empty($quotaData['suspended']) ? 'oui' : 'non') . '</li>'
    . '<li>Dernier succès : ' . videos_stats_date(isset($quotaData['last_success_at']) ? $quotaData['last_success_at'] : null) . '</li>'
    . '</ul></section>';

$cacheLabels = array(
    'search' => 'Résultats de recherche',
    'videos' => 'Informations des vidéos',
    'channels' => 'Informations des chaînes',
    'availability' => 'Vérifications de disponibilité'
);
$html .= '<section class="videos-admin-section"><h2>Cache</h2><div class="videos-admin-table-wrap">'
    . '<table class="admin-list videos-admin-table"><thead><tr><th>Cache</th><th>Entrées</th><th>Volume</th><th>Entrée la plus récente</th></tr></thead><tbody>';
foreach ($cacheLabels as $scope => $label) {
    $item = isset($cacheStatus[$scope]) ? $cacheStatus[$scope] : array();
    $html .= '<tr><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</td><td>'
        . (isset($item['entries']) ? (int) $item['entries'] : 0) . '</td><td>'
        . videos_stats_bytes(isset($item['bytes']) ? $item['bytes'] : 0) . '</td><td>'
        . videos_stats_date(isset($item['latest_at']) ? $item['latest_at'] : null) . '</td></tr>';
}
$html .= '</tbody></table></div></section>';

$html .= '<section class="videos-admin-section"><h2>Diagnostic SEO</h2>'
    . '<p>Prévisualisation des balises produites pour la première vidéo du classement global.</p>';
if ($seoDiagnostic !== '') {
    $html .= '<p>Vidéo test : <code>'
        . htmlspecialchars($seoDiagnosticVideoId, ENT_QUOTES, 'UTF-8')
        . '</code></p><pre class="videos-seo-preview"><code>'
        . htmlspecialchars($seoDiagnostic, ENT_QUOTES, 'UTF-8')
        . '</code></pre>';
} else {
    $html .= '<p>Aucune vidéo disponible pour le diagnostic SEO.</p>';
}
$html .= '</section>';

$html .= '<section class="videos-admin-section"><h2>Pages publiques adressables</h2><ul>'
    . '<li><a href="' . htmlspecialchars(plugin_idtourl_videos('', 'catalogue'), ENT_QUOTES, 'UTF-8') . '">Catalogue</a></li>'
    . '<li><a href="' . htmlspecialchars(plugin_idtourl_videos('', 'rankings:videos'), ENT_QUOTES, 'UTF-8') . '">Classement global des vidéos</a></li>'
    . '<li><a href="' . htmlspecialchars(plugin_idtourl_videos('', 'rankings:channels'), ENT_QUOTES, 'UTF-8') . '">Classement des chaînes</a></li>'
    . '</ul><p>Les vidéos permanentes et les chaînes éligibles possèdent également leur propre URL canonique.</p></section></div>';

echo COM_createHTMLDocument(
    $html,
    array('pagetitle' => 'Videos — Statistiques', 'headercode' => VIDEOS_adminHeaderCode())
);

function videos_stats_nav($configuration, $active)
{
    $base = $configuration['site_admin_url'] . '/plugins/videos/';
    $items = array(
        'overview' => array('index.php', 'Vue générale'),
        'actions' => array('actions.php', 'Actions'),
        'stats' => array('stats.php', 'Statistiques'),
        'moderation' => array('moderation.php', 'Modération')
    );
    $html = '<nav class="videos-navigation" aria-label="Administration Videos"><ul>';
    foreach ($items as $key => $item) {
        $html .= '<li><a href="' . htmlspecialchars($base . $item[0], ENT_QUOTES, 'UTF-8') . '"'
            . ($key === $active ? ' class="is-active" aria-current="page"' : '') . '>'
            . htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html . '</ul></nav>';
}

function videos_stats_date($value)
{
    if (empty($value)) {
        return 'jamais';
    }
    $timestamp = strtotime((string) $value);
    if ($timestamp !== false && function_exists('COM_getUserDateTimeFormat')) {
        $formatted = COM_getUserDateTimeFormat($timestamp);
        if (is_array($formatted) && isset($formatted[0])) {
            return htmlspecialchars($formatted[0], ENT_QUOTES, 'UTF-8');
        }
    }
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function videos_stats_bytes($bytes)
{
    $bytes = max(0, (int) $bytes);
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', ' ') . ' MiB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', ' ') . ' KiB';
    }
    return $bytes . ' o';
}
