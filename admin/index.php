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
$html = '<div class="videos-admin"><h1>'
    . htmlspecialchars($LANG_VIDEOS['admin_title'], ENT_QUOTES, 'UTF-8')
    . '</h1>' . videos_overview_nav($_CONF, 'overview');

if (!$bootstrap->isReady()) {
    $html .= COM_showMessageText(
        VIDEOS_localizeAdminText('Le stockage du plugin Videos est indisponible. Consultez les outils de réparation.'),
        '',
        true
    );
    $html .= '<p><a href="'
        . htmlspecialchars(
            $_CONF['site_admin_url'] . '/plugins/videos/repair.php',
            ENT_QUOTES,
            'UTF-8'
        ) . '">Ouvrir les outils de réparation</a></p></div>';
    echo COM_createHTMLDocument(
        $html,
        array(
            'pagetitle' => $LANG_VIDEOS['admin_title'],
            'headercode' => VIDEOS_adminHeaderCode()
        )
    );
    exit;
}

$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$pool = new Videos_PermanentPool($store, $cache);
$poolStatus = $pool->status();
$reservoirStatus = (new Videos_DiscoveryReservoir($store, $cache))->status();
$ranking = new Videos_Ranking(
    $store,
    new Videos_RatingStats($store),
    new Videos_VideoStats($store),
    $cache
);
$videoRankingCount = count($ranking->getGlobal(500));
$channelRankingCount = count((new Videos_ChannelRanking($store, $cache))->getGlobal(250));
$priorityCount = count((new Videos_Moderation($store))->getPriorityChannelIds(500));

$html .= '<section class="videos-admin-section"><h2>Vue générale</h2>'
    . '<div class="videos-admin-overview">'
    . videos_overview_card(
        'Actions',
        'Ajouter ou épingler des vidéos, gérer les chaînes, l’API YouTube, la maintenance et IndexNow.',
        $_CONF['site_admin_url'] . '/plugins/videos/actions.php'
    )
    . videos_overview_card(
        'Statistiques',
        'Consulter le réservoir, les classements, le fonds permanent, le quota et les caches.',
        $_CONF['site_admin_url'] . '/plugins/videos/stats.php'
    )
    . videos_overview_card(
        'Modération',
        'Bloquer, autoriser ou prioriser des vidéos et des chaînes.',
        $_CONF['site_admin_url'] . '/plugins/videos/moderation.php'
    )
    . '</div></section>';

$html .= '<section class="videos-admin-section"><h2>Repères</h2>'
    . '<ul class="videos-admin-status">'
    . '<li>Vidéos dans le réservoir : ' . (int) $reservoirStatus['item_count'] . '</li>'
    . '<li>Vidéos dans le classement global : ' . $videoRankingCount . '</li>'
    . '<li>Chaînes dans le classement : ' . $channelRankingCount . '</li>'
    . '<li>Chaînes prioritaires : ' . $priorityCount . '</li>'
    . '<li>Vidéos dans le catalogue permanent : ' . (int) $poolStatus['item_count'] . '</li>'
    . '<li>Vidéos épinglées : '
    . (isset($poolStatus['pinned_count']) ? (int) $poolStatus['pinned_count'] : 0)
    . '</li></ul>'
    . '<p><a href="'
    . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/videos/stats.php', ENT_QUOTES, 'UTF-8')
    . '">Voir toutes les statistiques</a></p></section>';

$html .= '<section class="videos-admin-section"><h2>Pages publiques</h2><ul>'
    . '<li><a href="' . htmlspecialchars(plugin_idtourl_videos('', 'catalogue'), ENT_QUOTES, 'UTF-8') . '">Catalogue vidéo</a></li>'
    . '<li><a href="' . htmlspecialchars(plugin_idtourl_videos('', 'rankings:videos'), ENT_QUOTES, 'UTF-8') . '">Classement global des vidéos</a></li>'
    . '<li><a href="' . htmlspecialchars(plugin_idtourl_videos('', 'rankings:channels'), ENT_QUOTES, 'UTF-8') . '">Classement des chaînes</a></li>'
    . '</ul></section></div>';

$html = VIDEOS_localizeAdminText($html);

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $LANG_VIDEOS['admin_title'],
        'headercode' => VIDEOS_adminHeaderCode()
    )
);

function videos_overview_nav($configuration, $active)
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
            . ($key === $active ? ' class="is-active" aria-current="page"' : '')
            . '>' . htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html . '</ul></nav>';
}

function videos_overview_card($title, $description, $url)
{
    return '<article class="videos-admin-section"><h3><a href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a></h3><p>'
        . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p></article>';
}
