<?php

require_once '../../../lib-common.php';

if (!SEC_hasRights('videos.admin')) {
    echo COM_createHTMLDocument(
        COM_showMessageText($LANG_VIDEOS['access_denied'], '', true),
        array(
            'pagetitle' => $LANG_VIDEOS['admin_title'],
            'headercode' => VIDEOS_adminHeaderCode()
        )
    );
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    echo COM_createHTMLDocument(
        COM_showMessageText(VIDEOS_localizeAdminText('Le stockage du plugin Videos est indisponible.'), '', true),
        array(
            'pagetitle' => VIDEOS_localizeAdminText('Videos — Statistiques'),
            'headercode' => videos_stats_header_code()
        )
    );
    exit;
}

$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$quota = new Videos_Quota($store);
$quotaStatus = $quota->status();
$quotaData = isset($quotaStatus['data']) && is_array($quotaStatus['data'])
    ? $quotaStatus['data'] : array();
$counts = isset($quotaData['counts']) && is_array($quotaData['counts'])
    ? $quotaData['counts'] : array();
$cacheStatus = (new Videos_CacheMaintenance($store))->inspect();
$reservoirStatus = (new Videos_DiscoveryReservoir($store, $cache))->status();
$pool = new Videos_PermanentPool($store, $cache);
$poolStatus = $pool->status();
$ranking = new Videos_Ranking(
    $store,
    new Videos_RatingStats($store),
    new Videos_VideoStats($store),
    $cache
);
$videoRanking = $ranking->getGlobal(500);
$channelRanking = (new Videos_ChannelRanking($store, $cache))->getGlobal(250);
$moderation = new Videos_Moderation($store);
$priorityChannels = $moderation->getPriorityChannelIds(500);
$searchService = VIDEOS_getSearchService($bootstrap);
$searchableCount = $searchService === false
    ? 0 : count($searchService->inventory(1000));

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
    . '<section class="videos-admin-section"><h2>Contenu public et éditorial</h2>'
    . '<div class="videos-stat-grid">'
    . videos_stat_card(
        'Vidéos dans le réservoir',
        (int) $reservoirStatus['item_count'],
        'Corpus de découverte local'
    )
    . videos_stat_card(
        'Vidéos recherchables',
        $searchableCount,
        'Corpus public utilisé par Geeklog et le catalogue'
    )
    . videos_stat_card(
        'Classement global',
        count($videoRanking),
        'Vidéos ayant des signaux locaux'
    )
    . videos_stat_card(
        'Chaînes classées',
        count($channelRanking),
        'Chaînes issues du classement local'
    )
    . videos_stat_card(
        'Chaînes prioritaires',
        count($priorityChannels),
        'Décisions éditoriales actives'
    )
    . videos_stat_card(
        'Catalogue permanent',
        (int) $poolStatus['item_count'],
        'Vidéos conservées durablement'
    )
    . videos_stat_card(
        'Vidéos épinglées',
        isset($poolStatus['pinned_count'])
            ? (int) $poolStatus['pinned_count'] : 0,
        'Sélections fortes, y compris les anciens épinglages 0.17'
    )
    . videos_stat_card(
        'Exclues du fonds',
        (int) $poolStatus['excluded_count'],
        'Exclusions éditoriales explicites'
    )
    . '</div></section>';

$html .= '<section class="videos-admin-section"><h2>Activité YouTube API</h2>'
    . '<div class="videos-stat-grid videos-stat-grid-compact">'
    . videos_stat_card(
        'Recherches aujourd’hui',
        isset($counts['search']) ? (int) $counts['search'] : 0,
        'Appels search.list'
    )
    . videos_stat_card(
        'Appels vidéos',
        isset($counts['videos']) ? (int) $counts['videos'] : 0,
        'Détails videos.list'
    )
    . videos_stat_card(
        'Appels chaînes',
        isset($counts['channels']) ? (int) $counts['channels'] : 0,
        'Détails channels.list'
    )
    . videos_stat_card(
        'Quota suspendu',
        !empty($quotaData['suspended']) ? 'Oui' : 'Non',
        'Protection locale du quota'
    )
    . videos_stat_card(
        'Dernier succès',
        videos_stats_date_text(
            isset($quotaData['last_success_at'])
                ? $quotaData['last_success_at'] : null
        ),
        'Dernière réponse API valide',
        true
    )
    . '</div></section>';

$cacheLabels = array(
    'search' => 'Résultats de recherche',
    'videos' => 'Informations des vidéos',
    'channels' => 'Informations des chaînes',
    'availability' => 'Vérifications de disponibilité'
);
$html .= '<section class="videos-admin-section"><h2>Cache</h2>'
    . '<div class="videos-admin-table-wrap">'
    . '<table class="admin-list videos-admin-table"><thead><tr>'
    . '<th>Cache</th><th>Entrées</th><th>Volume</th>'
    . '<th>Entrée la plus récente</th></tr></thead><tbody>';
foreach ($cacheLabels as $scope => $label) {
    $item = isset($cacheStatus[$scope]) ? $cacheStatus[$scope] : array();
    $html .= '<tr><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</td><td>' . (isset($item['entries']) ? (int) $item['entries'] : 0)
        . '</td><td>'
        . videos_stats_bytes(isset($item['bytes']) ? $item['bytes'] : 0)
        . '</td><td>'
        . videos_stats_date(isset($item['latest_at']) ? $item['latest_at'] : null)
        . '</td></tr>';
}
$html .= '</tbody></table></div></section>';

$html .= '<section class="videos-admin-section"><h2>Intégration Geeklog</h2>'
    . '<div class="videos-integration-grid">'
    . '<div><strong>Recherche native</strong><span>Active via '
    . '<code>plugin_searchtypes_videos()</code> et '
    . '<code>plugin_dopluginsearch_videos()</code>.</span></div>'
    . '<div><strong>Statistiques natives</strong><span>Active via '
    . '<code>plugin_statssummary_videos()</code> et '
    . '<code>plugin_showstats_videos()</code>.</span></div>'
    . '<div><strong>Recherche publique</strong><span>Le même corpus local est '
    . 'réutilisé sur le catalogue, sans appel YouTube supplémentaire.</span></div>'
    . '</div></section>';

$html .= '<section class="videos-admin-section"><h2>Diagnostic SEO</h2>'
    . '<p>Prévisualisation des balises produites pour la première vidéo '
    . 'du classement global.</p>';
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
    . '<li><a href="'
    . htmlspecialchars(plugin_idtourl_videos('', 'catalogue'), ENT_QUOTES, 'UTF-8')
    . '">Catalogue</a></li>'
    . '<li><a href="'
    . htmlspecialchars(
        plugin_idtourl_videos('', 'rankings:videos'),
        ENT_QUOTES,
        'UTF-8'
    ) . '">Classement global des vidéos</a></li>'
    . '<li><a href="'
    . htmlspecialchars(
        plugin_idtourl_videos('', 'rankings:channels'),
        ENT_QUOTES,
        'UTF-8'
    ) . '">Classement des chaînes</a></li>'
    . '</ul><p>Les vidéos permanentes et les chaînes éligibles possèdent '
    . 'également leur propre URL canonique.</p></section></div>';

$html = VIDEOS_localizeAdminText($html);

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => VIDEOS_localizeAdminText('Videos — Statistiques'),
        'headercode' => videos_stats_header_code()
    )
);

function videos_stats_nav($configuration, $active)
{
    global $LANG_VIDEOS;
    $base = $configuration['site_admin_url'] . '/plugins/videos/';
    $items = array(
        'overview' => array('index.php', $LANG_VIDEOS['admin_nav_overview']),
        'actions' => array('actions.php', $LANG_VIDEOS['admin_nav_actions']),
        'stats' => array('stats.php', $LANG_VIDEOS['admin_nav_stats']),
        'moderation' => array('moderation.php', $LANG_VIDEOS['admin_nav_moderation'])
    );
    $html = '<nav class="videos-navigation" aria-label="' . htmlspecialchars($LANG_VIDEOS['admin_navigation'], ENT_QUOTES, 'UTF-8') . '"><ul>';
    foreach ($items as $key => $item) {
        $html .= '<li><a href="'
            . htmlspecialchars($base . $item[0], ENT_QUOTES, 'UTF-8') . '"'
            . ($key === $active
                ? ' class="is-active" aria-current="page"' : '') . '>'
            . htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html . '</ul></nav>';
}

function videos_stat_card($label, $value, $detail, $smallValue = false)
{
    return '<article class="videos-stat-card">'
        . '<span class="videos-stat-value'
        . ($smallValue ? ' is-small' : '') . '">'
        . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8')
        . '</span><strong class="videos-stat-label">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</strong><span class="videos-stat-detail">'
        . htmlspecialchars($detail, ENT_QUOTES, 'UTF-8')
        . '</span></article>';
}

function videos_stats_header_code()
{
    return VIDEOS_adminHeaderCode() . "\n" . '<style>'
        . '.videos-stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem}'
        . '.videos-stat-card{display:flex;flex-direction:column;min-height:110px;padding:1rem;border:1px solid rgba(127,127,127,.28);border-radius:.6rem;background:rgba(127,127,127,.055);box-sizing:border-box}'
        . '.videos-stat-value{font-size:1.8rem;font-weight:700;line-height:1.05;margin-bottom:.45rem;overflow-wrap:anywhere}'
        . '.videos-stat-value.is-small{font-size:1.05rem;line-height:1.3}'
        . '.videos-stat-label{font-size:.95rem;line-height:1.25}'
        . '.videos-stat-detail{margin-top:auto;padding-top:.45rem;font-size:.78rem;line-height:1.3;opacity:.7}'
        . '.videos-integration-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:1rem}'
        . '.videos-integration-grid>div{display:flex;flex-direction:column;gap:.35rem;padding:1rem;border:1px solid rgba(127,127,127,.25);border-radius:.55rem}'
        . '.videos-integration-grid span{font-size:.88rem;line-height:1.4;opacity:.82}'
        . '</style>';
}

function videos_stats_date_text($value)
{
    if (empty($value)) {
        return VIDEOS_localizeAdminText('Jamais');
    }
    $timestamp = strtotime((string) $value);
    if ($timestamp !== false && function_exists('COM_getUserDateTimeFormat')) {
        $formatted = COM_getUserDateTimeFormat($timestamp);
        if (is_array($formatted) && isset($formatted[0])) {
            return $formatted[0];
        }
    }
    return (string) $value;
}

function videos_stats_date($value)
{
    return htmlspecialchars(videos_stats_date_text($value), ENT_QUOTES, 'UTF-8');
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
