<?php

require_once '../../../lib-common.php';

if (!SEC_hasRights('videos.admin')) {
    $content = COM_showMessageText(
        $LANG_VIDEOS['access_denied'],
        $LANG_VIDEOS['admin_title'],
        true
    );
    echo COM_createHTMLDocument(
        $content,
        array(
            'pagetitle' => $LANG_VIDEOS['admin_title'],
            'headercode' => VIDEOS_adminHeaderCode()
        )
    );
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
$message = '';
$results = array();

if (!$bootstrap->isReady()) {
    $message = 'Le répertoire de données du plugin ne peut pas être initialisé.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SEC_checkToken()) {
        $message = 'Le jeton de sécurité a expiré. Veuillez recommencer.';
    } else {
        $action = isset($_POST['videos_action'])
            ? COM_applyFilter($_POST['videos_action']) : '';

        if ($action === 'save_key') {
            $key = isset($_POST['youtube_api_key'])
                ? trim($_POST['youtube_api_key']) : '';
            $message = $bootstrap->setYouTubeApiKey($key)
                ? 'La clé API a été enregistrée.'
                : 'La clé API est invalide.';
        } elseif ($action === 'test_search') {
            $query = isset($_POST['test_query'])
                ? trim(strip_tags($_POST['test_query'])) : '';
            if ($query === '' || strlen($query) > 250) {
                $message = 'La requête de test est invalide.';
            } else {
                $results = videos_admin_run_search(
                    $bootstrap,
                    $query,
                    $_VIDEOS_CONF
                );
                $message = ($results === false)
                    ? 'La recherche a échoué. Consultez l’état du quota et les journaux.'
                    : count($results['video_ids']) . ' vidéo(s) valide(s) trouvée(s).';
            }
        } elseif ($action === 'seed_discovery') {
            $query = isset($_POST['test_query'])
                ? trim(strip_tags($_POST['test_query'])) : '';
            if (!SEC_hasRights('videos.maintenance') || $query === '' ||
                strlen($query) > 250) {
                $message = $LANG_VIDEOS['discovery_seed_failed'];
            } else {
                $seedResult = videos_admin_seed_discovery(
                    $bootstrap,
                    $query,
                    $_VIDEOS_CONF
                );
                $message = !is_array($seedResult) ||
                    empty($seedResult['success'])
                    ? $LANG_VIDEOS['discovery_seed_failed']
                    : sprintf(
                        $LANG_VIDEOS['discovery_seed_success'],
                        (int) $seedResult['added'],
                        (int) $seedResult['total'],
                        (int) $seedResult['searches']
                    );
            }
        } elseif ($action === 'clear_cache') {
            if (!SEC_hasRights('videos.maintenance')) {
                $message = $LANG_VIDEOS['cache_clear_denied'];
            } else {
                $scope = isset($_POST['cache_scope'])
                    ? COM_applyFilter($_POST['cache_scope']) : '';
                $maintenance = new Videos_CacheMaintenance(
                    $bootstrap->getStore()
                );
                $clearResult = $maintenance->clear($scope);
                if (!empty($clearResult['success'])) {
                    $message = sprintf(
                        $LANG_VIDEOS['cache_cleared'],
                        (int) $clearResult['deleted']
                    );
                } else {
                    $message = sprintf(
                        $LANG_VIDEOS['cache_clear_partial'],
                        (int) $clearResult['deleted'],
                        (int) $clearResult['failed']
                    );
                }
            }
        } elseif ($action === 'rebuild_ranking') {
            if (!SEC_hasRights('videos.maintenance')) {
                $message = $LANG_VIDEOS['cache_clear_denied'];
            } else {
                $ranking = videos_admin_ranking($bootstrap);
                $rankingCount = $ranking->rebuild();
                $message = ($rankingCount === false)
                    ? $LANG_VIDEOS['ranking_rebuild_failed']
                    : sprintf(
                        $LANG_VIDEOS['ranking_rebuilt'],
                        (int) $rankingCount
                    );
            }
        } elseif (in_array(
            $action,
            array(
                'pool_rebuild',
                'pool_pin',
                'pool_remove',
                'pool_exclude',
                'pool_allow'
            ),
            true
        )) {
            if (!SEC_hasRights('videos.maintenance')) {
                $message = $LANG_VIDEOS['cache_clear_denied'];
            } else {
                $cache = new Videos_Cache($bootstrap->getStore());
                $pool = new Videos_PermanentPool(
                    $bootstrap->getStore(),
                    $cache
                );
                $ranking = videos_admin_ranking($bootstrap);
                $rankingItems = $ranking->getGlobal(500);
                if ($action === 'pool_rebuild') {
                    $poolResult = $pool->synchronize(
                        $rankingItems,
                        $_VIDEOS_CONF,
                        true
                    );
                } else {
                    $poolVideoId = isset($_POST['video_id'])
                        ? COM_applyFilter($_POST['video_id']) : '';
                    $stateMap = array(
                        'pool_pin' => 'pinned',
                        'pool_remove' => 'removed',
                        'pool_exclude' => 'excluded',
                        'pool_allow' => 'allowed'
                    );
                    $rankingItem = isset($rankingItems[$poolVideoId])
                        ? $rankingItems[$poolVideoId] : array();
                    $poolResult = $pool->setManualState(
                        $poolVideoId,
                        $stateMap[$action],
                        $rankingItem
                    );
                }
                $message = $poolResult === false
                    ? $LANG_VIDEOS['permanent_pool_action_failed']
                    : $LANG_VIDEOS['permanent_pool_action_saved'];
            }
        }
    }
}

$quota = new Videos_Quota($bootstrap->getStore());
$status = $quota->status();
$statusData = isset($status['data']) && is_array($status['data'])
    ? $status['data'] : array();
$counts = isset($statusData['counts']) && is_array($statusData['counts'])
    ? $statusData['counts'] : array();
$cacheMaintenance = new Videos_CacheMaintenance($bootstrap->getStore());
$cacheStatus = $cacheMaintenance->inspect();
$ranking = videos_admin_ranking($bootstrap);
$globalRanking = $ranking->getGlobal(10);
$poolCache = new Videos_Cache($bootstrap->getStore());
$seoDiagnostic = '';
$seoDiagnosticVideoId = '';
if (count($globalRanking) > 0) {
    $rankingIds = array_keys($globalRanking);
    $seoDiagnosticVideoId = reset($rankingIds);
    $seoDiagnosticVideo = $poolCache->getVideo(
        $seoDiagnosticVideoId,
        true
    );
    if (is_array($seoDiagnosticVideo)) {
        $seoDescriptionService = new Videos_Description();
        $seoDescription = $seoDescriptionService->excerpt(
            isset($seoDiagnosticVideo['snippet']['description'])
                ? $seoDiagnosticVideo['snippet']['description'] : '',
            isset($_VIDEOS_CONF['description_mode'])
                ? $_VIDEOS_CONF['description_mode'] : 'clean'
        );
        $seoService = new Videos_Seo(
            $_CONF['site_url'],
            isset($_CONF['site_name']) ? $_CONF['site_name'] : '',
            $_VIDEOS_CONF
        );
        $seoDiagnostic = $seoService->video(
            $seoDiagnosticVideoId,
            $seoDiagnosticVideo,
            $seoDescription,
            (!empty($_VIDEOS_CONF['privacy_enhanced_embed'])
                ? 'https://www.youtube-nocookie.com'
                : 'https://www.youtube.com')
                . '/embed/' . rawurlencode($seoDiagnosticVideoId)
        );
    }
}
$permanentPool = new Videos_PermanentPool(
    $bootstrap->getStore(),
    $poolCache
);
$poolStatus = $permanentPool->status();
$poolRecords = $permanentPool->records();
$discoveryReservoir = new Videos_DiscoveryReservoir(
    $bootstrap->getStore(),
    $poolCache
);
$discoveryStatus = $discoveryReservoir->status();
$channelRanking = new Videos_ChannelRanking(
    $bootstrap->getStore(),
    new Videos_Cache($bootstrap->getStore())
);
$globalChannelRanking = $channelRanking->getGlobal(10);
$apiKeyConfigured = $bootstrap->getYouTubeApiKey() !== '';
$token = SEC_createToken();

$html = '<div class="videos-admin"><h1>'
    . htmlspecialchars($LANG_VIDEOS['admin_title'], ENT_QUOTES, 'UTF-8')
    . '</h1>';
if (SEC_hasRights('videos.moderate')) {
    $html .= '<p><a class="videos-watch-again" href="'
        . htmlspecialchars(
            $_CONF['site_admin_url'] . '/plugins/videos/moderation.php',
            ENT_QUOTES,
            'UTF-8'
        ) . '">' . htmlspecialchars(
            $LANG_VIDEOS['moderation_title'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</a></p>';
}
if ($message !== '') {
    $html .= COM_showMessageText($message, '', true);
}
$html .= '<section class="videos-admin-section"><h2>'
    . htmlspecialchars(
        $LANG_VIDEOS['seo_diagnostic'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</h2><p>' . htmlspecialchars(
        $LANG_VIDEOS['seo_diagnostic_help'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';
if ($seoDiagnostic !== '') {
    $html .= '<p><code>' . htmlspecialchars(
        $seoDiagnosticVideoId,
        ENT_QUOTES,
        'UTF-8'
    ) . '</code></p><pre class="videos-seo-preview"><code>'
        . htmlspecialchars($seoDiagnostic, ENT_QUOTES, 'UTF-8')
        . '</code></pre>';
} else {
    $html .= '<p>' . htmlspecialchars(
        $LANG_VIDEOS['seo_diagnostic_empty'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';
}
$html .= '</section>';
$html .= '<section class="videos-admin-section"><h2>État</h2>'
    . '<ul class="videos-admin-status">'
    . '<li>Stockage JSON : ' . ($bootstrap->isReady() ? 'prêt' : 'erreur') . '</li>'
    . '<li>Clé API : ' . ($apiKeyConfigured ? 'configurée' : 'absente') . '</li>'
    . '<li>Recherches aujourd’hui : '
    . (isset($counts['search']) ? (int) $counts['search'] : 0) . '</li>'
    . '<li>Quota suspendu : '
    . (!empty($statusData['suspended']) ? 'oui' : 'non')
    . '</li></ul></section>';

$html .= '<section class="videos-admin-section"><h2>'
    . htmlspecialchars($LANG_VIDEOS['quota_status'], ENT_QUOTES, 'UTF-8')
    . '</h2><ul class="videos-admin-status"><li>'
    . htmlspecialchars($LANG_VIDEOS['quota_search_calls'], ENT_QUOTES, 'UTF-8')
    . ' : ' . (isset($counts['search']) ? (int) $counts['search'] : 0)
    . '</li><li>'
    . htmlspecialchars($LANG_VIDEOS['quota_video_calls'], ENT_QUOTES, 'UTF-8')
    . ' : ' . (isset($counts['videos']) ? (int) $counts['videos'] : 0)
    . '</li><li>'
    . htmlspecialchars($LANG_VIDEOS['quota_channel_calls'], ENT_QUOTES, 'UTF-8')
    . ' : ' . (isset($counts['channels']) ? (int) $counts['channels'] : 0)
    . '</li><li>'
    . htmlspecialchars($LANG_VIDEOS['quota_last_search'], ENT_QUOTES, 'UTF-8')
    . ' : ' . videos_admin_status_value(
        isset($statusData['last_search_at'])
            ? $statusData['last_search_at'] : null
    ) . '</li><li>'
    . htmlspecialchars($LANG_VIDEOS['quota_last_success'], ENT_QUOTES, 'UTF-8')
    . ' : ' . videos_admin_status_value(
        isset($statusData['last_success_at'])
            ? $statusData['last_success_at'] : null
    ) . '</li><li>'
    . htmlspecialchars($LANG_VIDEOS['quota_last_error'], ENT_QUOTES, 'UTF-8')
    . ' : ' . videos_admin_error_value(
        isset($statusData['last_error'])
            ? $statusData['last_error'] : null
    ) . '</li></ul></section>';

$html .= '<section class="videos-admin-section"><h2>'
    . 'Clé YouTube Data API v3</h2>'
    . '<form class="videos-admin-form" method="post" action="">'
    . '<input type="hidden" name="videos_action" value="save_key">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
    . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>Nouvelle clé API '
    . '<input type="password" name="youtube_api_key" value="" '
    . 'autocomplete="new-password" maxlength="200"></label> '
    . '<button type="submit">Enregistrer la clé</button></form></section>';

$html .= '<section class="videos-admin-section"><h2>'
    . 'Recherche d’amorçage</h2>'
    . '<form class="videos-admin-form" method="post" action="">'
    . '<input type="hidden" name="videos_action" value="test_search">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
    . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>Requête courte '
    . '<input type="text" name="test_query" maxlength="250" size="50"></label> '
    . '<button type="submit">Rechercher</button></form>';
if (SEC_hasRights('videos.maintenance')) {
    $html .= '<form class="videos-admin-form" method="post" action="" '
        . 'onsubmit="return confirm('
        . htmlspecialchars(
            json_encode($LANG_VIDEOS['discovery_seed_confirm']),
            ENT_QUOTES,
            'UTF-8'
        ) . ');">'
        . '<input type="hidden" name="videos_action" value="seed_discovery">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<label>' . htmlspecialchars(
            $LANG_VIDEOS['discovery_seed_query'], ENT_QUOTES, 'UTF-8'
        ) . ' <input type="text" name="test_query" maxlength="250" size="50">'
        . '</label> <button type="submit">' . htmlspecialchars(
            $LANG_VIDEOS['discovery_seed_button'], ENT_QUOTES, 'UTF-8'
        ) . '</button></form>';
}
$html .= '<ul class="videos-admin-status"><li>'
    . htmlspecialchars($LANG_VIDEOS['discovery_count'], ENT_QUOTES, 'UTF-8')
    . ' : ' . (int) $discoveryStatus['item_count'] . '</li><li>'
    . htmlspecialchars($LANG_VIDEOS['discovery_last_refresh'], ENT_QUOTES, 'UTF-8')
    . ' : ' . videos_admin_status_value($discoveryStatus['last_refresh_at'])
    . '</li><li>'
    . htmlspecialchars($LANG_VIDEOS['discovery_last_seed'], ENT_QUOTES, 'UTF-8')
    . ' : ' . videos_admin_status_value($discoveryStatus['last_seed_at'])
    . '</li></ul></section>';

if (is_array($results) && !empty($results['videos'])) {
    $html .= '<section class="videos-admin-section"><h2>Résultats</h2>'
        . '<div class="videos-grid videos-admin-results">';
    foreach ($results['videos'] as $videoId => $video) {
        $title = isset($video['snippet']['title'])
            ? $video['snippet']['title'] : $videoId;
        $html .= '<article class="videos-admin-result"><strong>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</strong><br><code>'
            . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8')
            . '</code></article>';
    }
    $html .= '</div></section>';
}

$cacheLabels = array(
    'search' => $LANG_VIDEOS['cache_search'],
    'videos' => $LANG_VIDEOS['cache_videos'],
    'channels' => $LANG_VIDEOS['cache_channels'],
    'availability' => $LANG_VIDEOS['cache_availability']
);
$html .= '<section class="videos-admin-section"><h2>'
    . htmlspecialchars($LANG_VIDEOS['cache_maintenance'], ENT_QUOTES, 'UTF-8')
    . '</h2><div class="videos-admin-table-wrap">'
    . '<table class="admin-list videos-admin-table"><thead><tr><th>Cache</th><th>'
    . htmlspecialchars($LANG_VIDEOS['cache_entries'], ENT_QUOTES, 'UTF-8')
    . '</th><th>'
    . htmlspecialchars($LANG_VIDEOS['cache_size'], ENT_QUOTES, 'UTF-8')
    . '</th><th>'
    . htmlspecialchars($LANG_VIDEOS['cache_latest'], ENT_QUOTES, 'UTF-8')
    . '</th></tr></thead><tbody>';
foreach ($cacheLabels as $scope => $label) {
    $cacheInfo = isset($cacheStatus[$scope])
        ? $cacheStatus[$scope] : array();
    $html .= '<tr><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</td><td>'
        . (isset($cacheInfo['entries']) ? (int) $cacheInfo['entries'] : 0)
        . '</td><td>' . videos_admin_format_bytes(
            isset($cacheInfo['bytes']) ? $cacheInfo['bytes'] : 0
        ) . '</td><td>' . videos_admin_status_value(
            isset($cacheInfo['latest_at']) ? $cacheInfo['latest_at'] : null
        ) . '</td></tr>';
}
$html .= '</tbody></table></div>';
if (SEC_hasRights('videos.maintenance')) {
    $html .= '<form class="videos-admin-form videos-admin-action" '
        . 'method="post" action="" onsubmit="return confirm('
        . htmlspecialchars(
            json_encode($LANG_VIDEOS['cache_confirm']),
            ENT_QUOTES,
            'UTF-8'
        ) . ');"><input type="hidden" name="videos_action" '
        . 'value="clear_cache"><input type="hidden" name="'
        . CSRF_TOKEN . '" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"><label>'
        . htmlspecialchars($LANG_VIDEOS['cache_scope'], ENT_QUOTES, 'UTF-8')
        . ' <select name="cache_scope"><option value="search">'
        . htmlspecialchars($LANG_VIDEOS['cache_search'], ENT_QUOTES, 'UTF-8')
        . '</option><option value="videos">'
        . htmlspecialchars($LANG_VIDEOS['cache_videos'], ENT_QUOTES, 'UTF-8')
        . '</option><option value="channels">'
        . htmlspecialchars($LANG_VIDEOS['cache_channels'], ENT_QUOTES, 'UTF-8')
        . '</option><option value="availability">'
        . htmlspecialchars($LANG_VIDEOS['cache_availability'], ENT_QUOTES, 'UTF-8')
        . '</option><option value="all">'
        . htmlspecialchars($LANG_VIDEOS['cache_all'], ENT_QUOTES, 'UTF-8')
        . '</option></select></label> <button type="submit">'
        . htmlspecialchars($LANG_VIDEOS['cache_clear'], ENT_QUOTES, 'UTF-8')
        . '</button></form>';
}
$html .= '</section>';

$html .= '<section class="videos-admin-section"><h2>'
    . htmlspecialchars(
        $LANG_VIDEOS['permanent_pool_title'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</h2><ul class="videos-admin-status"><li>'
    . htmlspecialchars(
        $LANG_VIDEOS['permanent_pool_count'],
        ENT_QUOTES,
        'UTF-8'
    ) . ' : ' . (int) $poolStatus['item_count'] . '</li><li>'
    . htmlspecialchars(
        $LANG_VIDEOS['permanent_pool_excluded_count'],
        ENT_QUOTES,
        'UTF-8'
    ) . ' : ' . (int) $poolStatus['excluded_count'] . '</li><li>'
    . htmlspecialchars(
        $LANG_VIDEOS['permanent_pool_last_rebuild'],
        ENT_QUOTES,
        'UTF-8'
    ) . ' : ' . videos_admin_status_value(
        $poolStatus['rebuilt_at']
    ) . '</li></ul>';
if (SEC_hasRights('videos.maintenance')) {
    $html .= '<div class="videos-pool-admin-actions">'
        . '<form class="videos-admin-form" method="post" action="" '
        . 'onsubmit="return confirm(' . htmlspecialchars(
            json_encode($LANG_VIDEOS['permanent_pool_confirm']),
            ENT_QUOTES,
            'UTF-8'
        ) . ');">'
        . '<input type="hidden" name="videos_action" value="pool_pin">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"><label>'
        . htmlspecialchars(
            $LANG_VIDEOS['permanent_pool_video_id'],
            ENT_QUOTES,
            'UTF-8'
        ) . '<input type="text" name="video_id" required maxlength="11">'
        . '</label><button type="submit">'
        . htmlspecialchars(
            $LANG_VIDEOS['permanent_pool_pin'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</button></form><form class="videos-admin-form" method="post" '
        . 'action="" onsubmit="return confirm(' . htmlspecialchars(
            json_encode($LANG_VIDEOS['permanent_pool_confirm']),
            ENT_QUOTES,
            'UTF-8'
        ) . ');"><input type="hidden" name="videos_action" '
        . 'value="pool_rebuild"><input type="hidden" name="' . CSRF_TOKEN
        . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        . '"><button type="submit">'
        . htmlspecialchars(
            $LANG_VIDEOS['permanent_pool_rebuild'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</button></form></div>';
}
if (!empty($poolRecords['items'])) {
    $html .= '<div class="videos-admin-table-wrap"><table class="admin-list '
        . 'videos-admin-table"><thead><tr><th>'
        . htmlspecialchars($LANG_VIDEOS['video'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars(
            $LANG_VIDEOS['permanent_pool_source'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</th><th>'
        . htmlspecialchars(
            $LANG_VIDEOS['moderation_action'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</th></tr></thead><tbody>';
    foreach ($poolRecords['items'] as $poolVideoId => $poolItem) {
        $poolVideo = $poolCache->getVideo($poolVideoId, true);
        $poolTitle = is_array($poolVideo) &&
            isset($poolVideo['snippet']['title'])
            ? $poolVideo['snippet']['title'] : $poolVideoId;
        $sourceKey = isset($poolItem['source']) &&
            $poolItem['source'] === 'manual'
            ? 'permanent_pool_source_manual'
            : 'permanent_pool_source_automatic';
        $html .= '<tr><td>'
            . htmlspecialchars($poolTitle, ENT_QUOTES, 'UTF-8')
            . '<br><code>' . htmlspecialchars(
                $poolVideoId,
                ENT_QUOTES,
                'UTF-8'
            ) . '</code></td><td>'
            . htmlspecialchars($LANG_VIDEOS[$sourceKey], ENT_QUOTES, 'UTF-8')
            . '</td><td>';
        if (SEC_hasRights('videos.maintenance')) {
            $html .= videos_admin_pool_action_form(
                'pool_remove',
                $poolVideoId,
                $LANG_VIDEOS['permanent_pool_remove'],
                $token
            ) . videos_admin_pool_action_form(
                'pool_exclude',
                $poolVideoId,
                $LANG_VIDEOS['permanent_pool_exclude'],
                $token
            );
        }
        $html .= '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
if (!empty($poolRecords['excluded']) &&
    SEC_hasRights('videos.maintenance')) {
    $html .= '<details class="videos-advanced-field"><summary>'
        . htmlspecialchars(
            $LANG_VIDEOS['permanent_pool_excluded_list'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</summary><div class="videos-pool-excluded">';
    foreach ($poolRecords['excluded'] as $poolVideoId => $excludedAt) {
        $html .= '<div><code>' . htmlspecialchars(
            $poolVideoId,
            ENT_QUOTES,
            'UTF-8'
        ) . '</code>' . videos_admin_pool_action_form(
            'pool_allow',
            $poolVideoId,
            $LANG_VIDEOS['permanent_pool_allow'],
            $token
        ) . '</div>';
    }
    $html .= '</div></details>';
}
$html .= '</section>';

$html .= '<section class="videos-admin-section"><h2>'
    . htmlspecialchars($LANG_VIDEOS['global_ranking'], ENT_QUOTES, 'UTF-8')
    . '</h2>';
if (count($globalRanking) === 0) {
    $html .= '<p>'
        . htmlspecialchars($LANG_VIDEOS['ranking_no_data'], ENT_QUOTES, 'UTF-8')
        . '</p>';
} else {
    $html .= '<div class="videos-admin-table-wrap">'
        . '<table class="admin-list videos-admin-table"><thead><tr><th>'
        . 'Vidéo</th><th>'
        . htmlspecialchars($LANG_VIDEOS['ranking_score'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($LANG_VIDEOS['local_average'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($LANG_VIDEOS['ranking_views'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars(
            $LANG_VIDEOS['ranking_watch_rate'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</th><th>'
        . htmlspecialchars(
            $LANG_VIDEOS['recommendation_accepted'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</th><th>'
        . htmlspecialchars(
            $LANG_VIDEOS['recommendation_skipped'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</th><th>'
        . htmlspecialchars(
            $LANG_VIDEOS['permanent_pool_title'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</th></tr></thead><tbody>';
    foreach ($globalRanking as $videoId => $item) {
        $itemTitle = !empty($item['title']) ? $item['title'] : $videoId;
        $html .= '<tr><td><a href="'
            . htmlspecialchars(
                $_CONF['site_url'] . '/videos/watch.php?v='
                    . rawurlencode($videoId),
                ENT_QUOTES,
                'UTF-8'
            ) . '">' . htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8')
            . '</a></td><td>'
            . number_format(
                isset($item['score']) ? (float) $item['score'] : 0,
                2,
                ',',
                ' '
            ) . '</td><td>'
            . number_format(
                isset($item['rating_average'])
                    ? (float) $item['rating_average'] : 0,
                2,
                ',',
                ' '
            ) . '/5 (' . (isset($item['rating_count'])
                ? (int) $item['rating_count'] : 0) . ')</td><td>'
            . (isset($item['view_count']) ? (int) $item['view_count'] : 0)
            . '</td><td>'
            . number_format(
                (isset($item['watch_ratio_average'])
                    ? (float) $item['watch_ratio_average'] : 0) * 100,
                1,
                ',',
                ' '
            ) . '%</td><td>'
            . (isset($item['recommendation_accepted_count'])
                ? (int) $item['recommendation_accepted_count'] : 0)
            . '</td><td>'
            . (isset($item['recommendation_skipped_count'])
                ? (int) $item['recommendation_skipped_count'] : 0)
            . '</td><td>';
        if (SEC_hasRights('videos.maintenance')) {
            $html .= videos_admin_pool_action_form(
                'pool_pin',
                $videoId,
                $LANG_VIDEOS['permanent_pool_pin'],
                $token
            );
        }
        $html .= '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
if (SEC_hasRights('videos.maintenance')) {
    $html .= '<form class="videos-admin-form videos-admin-action" '
        . 'method="post" action="" onsubmit="return confirm('
        . htmlspecialchars(
            json_encode($LANG_VIDEOS['ranking_confirm']),
            ENT_QUOTES,
            'UTF-8'
        ) . ');"><input type="hidden" name="videos_action" '
        . 'value="rebuild_ranking"><input type="hidden" name="'
        . CSRF_TOKEN . '" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
        . '"><button type="submit">'
        . htmlspecialchars($LANG_VIDEOS['ranking_rebuild'], ENT_QUOTES, 'UTF-8')
        . '</button></form>';
}
$html .= '</section>';

$html .= '<section class="videos-admin-section"><h2>'
    . htmlspecialchars($LANG_VIDEOS['channel_ranking'], ENT_QUOTES, 'UTF-8')
    . '</h2>';
if (count($globalChannelRanking) === 0) {
    $html .= '<p>'
        . htmlspecialchars(
            $LANG_VIDEOS['channel_ranking_no_data'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</p>';
} else {
    $html .= '<div class="videos-admin-table-wrap">'
        . '<table class="admin-list videos-admin-table"><thead><tr><th>'
        . htmlspecialchars($LANG_VIDEOS['channel'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($LANG_VIDEOS['ranking_score'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($LANG_VIDEOS['local_average'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($LANG_VIDEOS['channel_videos'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($LANG_VIDEOS['ranking_views'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars(
            $LANG_VIDEOS['ranking_watch_rate'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</th></tr></thead><tbody>';
    foreach ($globalChannelRanking as $channelId => $item) {
        $channelTitle = !empty($item['title'])
            ? $item['title'] : $channelId;
        $html .= '<tr><td>'
            . htmlspecialchars($channelTitle, ENT_QUOTES, 'UTF-8')
            . '</td><td>'
            . number_format(
                isset($item['score']) ? (float) $item['score'] : 0,
                2,
                ',',
                ' '
            ) . '</td><td>'
            . number_format(
                isset($item['rating_average'])
                    ? (float) $item['rating_average'] : 0,
                2,
                ',',
                ' '
            ) . '/5 (' . (isset($item['rating_count'])
                ? (int) $item['rating_count'] : 0) . ')</td><td>'
            . (isset($item['video_count'])
                ? (int) $item['video_count'] : 0)
            . '</td><td>'
            . (isset($item['view_count']) ? (int) $item['view_count'] : 0)
            . '</td><td>'
            . number_format(
                (isset($item['watch_ratio_average'])
                    ? (float) $item['watch_ratio_average'] : 0) * 100,
                1,
                ',',
                ' '
            ) . '%</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
$html .= '</section></div>';

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $LANG_VIDEOS['admin_title'],
        'headercode' => VIDEOS_adminHeaderCode()
    )
);

function videos_admin_run_search($bootstrap, $query, $configuration)
{
    $store = $bootstrap->getStore();
    $client = new Videos_YouTubeClient(
        $bootstrap->getYouTubeApiKey(),
        isset($configuration['youtube_timeout'])
            ? $configuration['youtube_timeout'] : 8
    );
    $service = new Videos_YouTubeService(
        $client,
        new Videos_Cache($store),
        new Videos_Quota($store),
        new Videos_Logger($store)
    );
    return $service->find($query, videos_build_search_parameters($configuration));
}

function videos_admin_seed_discovery($bootstrap, $query, $configuration)
{
    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    $service = new Videos_YouTubeService(
        new Videos_YouTubeClient(
            $bootstrap->getYouTubeApiKey(),
            isset($configuration['youtube_timeout'])
                ? $configuration['youtube_timeout'] : 8
        ),
        $cache,
        new Videos_Quota($store),
        new Videos_Logger($store)
    );
    return (new Videos_DiscoveryReservoir($store, $cache))->refresh(
        $query,
        videos_build_search_parameters($configuration),
        $configuration,
        $service,
        true
    );
}

function videos_admin_pool_action_form($action, $videoId, $label, $token)
{
    global $LANG_VIDEOS;

    return '<form class="videos-inline-form" method="post" action="" '
        . 'onsubmit="return confirm(' . htmlspecialchars(
            json_encode($LANG_VIDEOS['permanent_pool_confirm']),
            ENT_QUOTES,
            'UTF-8'
        ) . ');">'
        . '<input type="hidden" name="videos_action" value="'
        . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="video_id" value="'
        . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</button></form>';
}

function videos_admin_ranking($bootstrap)
{
    $store = $bootstrap->getStore();
    return new Videos_Ranking(
        $store,
        new Videos_RatingStats($store),
        new Videos_VideoStats($store),
        new Videos_Cache($store)
    );
}

function videos_build_search_parameters($configuration)
{
    return array(
        'max_results' => isset($configuration['youtube_max_results'])
            ? $configuration['youtube_max_results'] : 20,
        'order' => 'relevance',
        'safe_search' => isset($configuration['youtube_safe_search'])
            ? $configuration['youtube_safe_search'] : 'moderate',
        'language' => isset($configuration['language'])
            ? $configuration['language'] : 'fr',
        'region' => isset($configuration['region'])
            ? $configuration['region'] : 'FR',
        'published_after' => '',
        'category_id' => '',
        'channel_id' => '',
        'daily_search_limit' => isset($configuration['youtube_daily_search_limit'])
            ? $configuration['youtube_daily_search_limit'] : 20,
        'cache_ttl' => isset($configuration['search_cache_ttl'])
            ? $configuration['search_cache_ttl'] : 86400,
        'video_cache_ttl' => isset($configuration['video_cache_ttl'])
            ? $configuration['video_cache_ttl'] : 86400,
        'channel_cache_ttl' => isset($configuration['channel_cache_ttl'])
            ? $configuration['channel_cache_ttl'] : 604800,
        'availability_cache_ttl' =>
            isset($configuration['availability_cache_ttl'])
                ? $configuration['availability_cache_ttl'] : 86400,
        'blocked_videos' => isset($configuration['blocked_videos'])
            ? $configuration['blocked_videos'] : '',
        'blocked_channels' => isset($configuration['blocked_channels'])
            ? $configuration['blocked_channels'] : '',
        'allowed_channels' => isset($configuration['allowed_channels'])
            ? $configuration['allowed_channels'] : '',
        'minimum_duration' => 0,
        'maximum_duration' => 0,
        'exclude_short_videos' =>
            !empty($configuration['exclude_short_videos']) ? 1 : 0,
        'short_filter_mode' => isset(
            $configuration['short_filter_mode']
        ) ? $configuration['short_filter_mode'] : 'probable',
        'short_max_duration' => isset(
            $configuration['short_max_duration']
        ) ? $configuration['short_max_duration'] : 180
    );
}

function videos_admin_status_value($value)
{
    global $LANG_VIDEOS;

    if (empty($value)) {
        return htmlspecialchars($LANG_VIDEOS['never'], ENT_QUOTES, 'UTF-8');
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

function videos_admin_error_value($error)
{
    global $LANG_VIDEOS;

    if (!is_array($error) || empty($error['code'])) {
        return htmlspecialchars($LANG_VIDEOS['never'], ENT_QUOTES, 'UTF-8');
    }
    $value = (string) $error['code'];
    if (!empty($error['at'])) {
        $value .= ' — ' . strip_tags((string) $error['at']);
    }
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function videos_admin_format_bytes($bytes)
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
