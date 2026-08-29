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
$message = '';
$searchResults = array();
if (!$bootstrap->isReady()) {
    $message = 'Le stockage du plugin Videos est indisponible.';
}
$store = $bootstrap->isReady() ? $bootstrap->getStore() : null;
$cache = $store ? new Videos_Cache($store) : null;
$pool = $store ? new Videos_PermanentPool($store, $cache) : null;
$moderation = $store ? new Videos_Moderation($store) : null;
$ranking = $store ? new Videos_Ranking(
    $store,
    new Videos_RatingStats($store),
    new Videos_VideoStats($store),
    $cache
) : null;

if ($store && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SEC_checkToken()) {
        $message = 'Le jeton de sécurité a expiré. Veuillez recommencer.';
    } else {
        $action = isset($_POST['videos_action']) ? COM_applyFilter($_POST['videos_action']) : '';
        if ($action === 'save_key') {
            $key = isset($_POST['youtube_api_key']) ? trim((string) $_POST['youtube_api_key']) : '';
            $message = $bootstrap->setYouTubeApiKey($key)
                ? 'La clé YouTube Data API a été enregistrée.'
                : 'La clé API est invalide.';
        } elseif ($action === 'test_search') {
            $query = isset($_POST['test_query']) ? trim(strip_tags((string) $_POST['test_query'])) : '';
            if ($query === '' || strlen($query) > 250) {
                $message = 'La requête de test est invalide.';
            } else {
                $searchResults = videos_actions_test_search($bootstrap, $query, $_VIDEOS_CONF);
                $message = $searchResults === false
                    ? 'La recherche de test a échoué.'
                    : count($searchResults['video_ids']) . ' vidéo(s) valide(s) trouvée(s).';
            }
        } elseif ($action === 'seed_discovery' && SEC_hasRights('videos.maintenance')) {
            $query = isset($_POST['seed_query']) ? trim(strip_tags((string) $_POST['seed_query'])) : '';
            if ($query === '' || strlen($query) > 250) {
                $message = 'La requête d’amorçage est invalide.';
            } else {
                $result = videos_actions_seed_discovery($bootstrap, $query, $_VIDEOS_CONF);
                $message = is_array($result) && !empty($result['success'])
                    ? (int) $result['added'] . ' vidéo(s) ajoutée(s) au réservoir.'
                    : 'L’amorçage du réservoir a échoué.';
            }
        } elseif ($action === 'clear_cache' && SEC_hasRights('videos.maintenance')) {
            $scope = isset($_POST['cache_scope']) ? COM_applyFilter($_POST['cache_scope']) : '';
            $result = (new Videos_CacheMaintenance($store))->clear($scope);
            $message = !empty($result['success'])
                ? (int) $result['deleted'] . ' entrée(s) de cache supprimée(s).'
                : 'Nettoyage partiel : ' . (int) $result['deleted'] . ' supprimée(s), '
                    . (int) $result['failed'] . ' échec(s).';
        } elseif ($action === 'rebuild_ranking' && SEC_hasRights('videos.maintenance')) {
            $count = $ranking->rebuild();
            $message = $count === false
                ? 'La reconstruction des classements a échoué.'
                : 'Classements reconstruits : ' . (int) $count . ' vidéo(s) classée(s).';
        } elseif ($action === 'pool_rebuild' && SEC_hasRights('videos.maintenance')) {
            $result = $pool->synchronize($ranking->getGlobal(500), $_VIDEOS_CONF, true);
            $message = $result === false
                ? 'La reconstruction du catalogue permanent a échoué.'
                : 'Le catalogue permanent a été reconstruit.';
        } elseif ($action === 'add_video') {
            $input = isset($_POST['video_input']) ? trim((string) $_POST['video_input']) : '';
            $videoId = videos_admin_extract_video_id($input);
            if ($videoId === '') {
                $message = 'ID ou URL YouTube invalide.';
            } else {
                $video = $cache->getVideo($videoId, true);
                if (!is_array($video)) {
                    $video = videos_admin_fetch_single_video($bootstrap, $cache, $videoId, $_VIDEOS_CONF);
                }
                if (!is_array($video)) {
                    $message = 'La vidéo est introuvable, privée, non intégrable ou refusée par la politique du plugin.';
                } elseif ($moderation->isVideoBlocked($videoId)) {
                    $message = 'Cette vidéo est actuellement bloquée par la modération.';
                } else {
                    $global = $ranking->getGlobal(500);
                    $rankingItem = isset($global[$videoId]) ? $global[$videoId] : array();
                    $saved = $pool->setManualState($videoId, 'added', $rankingItem);
                    $message = $saved
                        ? 'Vidéo ajoutée au catalogue permanent et signalée aux consommateurs Geeklog.'
                        : 'Impossible d’ajouter la vidéo au catalogue permanent.';
                }
            }
        } elseif (in_array(
            $action,
            array('pool_add', 'pool_pin', 'pool_unpin', 'pool_remove', 'pool_exclude', 'pool_allow'),
            true
        )) {
            $videoId = isset($_POST['video_id']) ? COM_applyFilter($_POST['video_id']) : '';
            $stateMap = array(
                'pool_add' => 'added',
                'pool_pin' => 'pinned',
                'pool_unpin' => 'unpinned',
                'pool_remove' => 'removed',
                'pool_exclude' => 'excluded',
                'pool_allow' => 'allowed'
            );
            $global = $ranking->getGlobal(500);
            $rankingItem = isset($global[$videoId]) ? $global[$videoId] : array();
            $message = $pool->setManualState($videoId, $stateMap[$action], $rankingItem)
                ? 'Décision éditoriale enregistrée.'
                : 'Impossible d’enregistrer cette décision.';
        } elseif ($action === 'channel_state' && SEC_hasRights('videos.moderate')) {
            $channelId = isset($_POST['channel_id']) ? COM_applyFilter($_POST['channel_id']) : '';
            $state = isset($_POST['channel_state']) ? COM_applyFilter($_POST['channel_state']) : '';
            $allowed = array('neutral', 'allowed', 'priority', 'blocked', 'disabled');
            if (!Videos_Validator::youtubeChannelId($channelId) || !in_array($state, $allowed, true)) {
                $message = 'Décision de chaîne invalide.';
            } else {
                $actorHash = hash_hmac(
                    'sha256',
                    'moderator:' . (int) $_USER['uid'],
                    $bootstrap->getSecret()
                );
                $saved = $moderation->setChannelState(
                    $channelId,
                    $state,
                    'Décision éditoriale depuis la page Actions Videos',
                    $actorHash
                );
                $message = $saved ? 'Décision sur la chaîne enregistrée.' : 'Impossible de modifier la chaîne.';
            }
        } elseif ($action === 'signal_public_pages') {
            $urls = videos_admin_public_urls(
                $store,
                $cache,
                $pool,
                $moderation,
                $ranking,
                $_VIDEOS_CONF
            );
            if (count($urls) === 0) {
                $message = 'Aucune URL publique Videos à signaler.';
            } elseif (function_exists('send_to_indexnow')) {
                $result = send_to_indexnow(
                    $urls,
                    array('item_type' => 'videos', 'event' => 'manual-sync')
                );
                $message = $result === false
                    ? 'Le batch IndexNow n’a pas pu être envoyé.'
                    : count($urls) . ' URL(s) Videos envoyée(s) à IndexNow en un seul batch.';
            } else {
                $message = 'Le plugin IndexNow n’est pas disponible. Aucune fausse création de contenu n’a été émise.';
            }
        }
    }
}

$token = SEC_createToken();
$records = $pool ? $pool->records() : array('items' => array(), 'excluded' => array());
$priorityChannels = $moderation ? $moderation->getPriorityChannelIds(100) : array();

$html = '<div class="videos-admin"><h1>Videos — Actions</h1>'
    . videos_admin_section_nav($_CONF, 'actions');
if ($message !== '') {
    $message = VIDEOS_localizeAdminText($message);
    $html .= COM_showMessageText($message, '', true);
}
$html .= '<section class="videos-admin-section"><h2>Curation vidéo</h2>'
    . '<p>Ajoutez directement une vidéo par son ID ou son URL YouTube. Elle est récupérée, mise en cache, ajoutée au catalogue permanent puis signalée via les événements Geeklog.</p>'
    . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="add_video">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>ID ou URL YouTube <input type="text" name="video_input" maxlength="500" size="60" placeholder="H5nzrlARuCo ou https://youtu.be/…" required></label> '
    . '<button type="submit">Ajouter au catalogue permanent</button></form></section>';

$html .= '<section class="videos-admin-section"><h2>Catalogue permanent</h2>';
if (empty($records['items'])) {
    $html .= '<p>Aucune vidéo conservée.</p>';
} else {
    $html .= '<div class="videos-admin-table-wrap"><table class="admin-list videos-admin-table"><thead><tr>'
        . '<th>Vidéo</th><th>État</th><th>Actions</th></tr></thead><tbody>';
    foreach ($records['items'] as $videoId => $item) {
        $video = $cache->getVideo($videoId, true);
        $title = is_array($video) && !empty($video['snippet']['title'])
            ? $video['snippet']['title'] : $videoId;
        $isPinned = !empty($item['pinned']);
        $html .= '<tr><td><a href="' . htmlspecialchars(plugin_idtourl_videos('', $videoId), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a><br><code>'
            . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '</code></td><td>'
            . ($isPinned ? 'Épinglée' : 'Permanente') . '</td><td>';
        $html .= $isPinned
            ? videos_admin_action_form('pool_unpin', $videoId, 'Désépingler', $token)
            : videos_admin_action_form('pool_pin', $videoId, 'Épingler', $token);
        $html .= videos_admin_action_form('pool_remove', $videoId, 'Retirer du permanent', $token)
            . videos_admin_action_form('pool_exclude', $videoId, 'Exclure du fonds', $token)
            . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
if (!empty($records['excluded'])) {
    $html .= '<details class="videos-advanced-field"><summary>Vidéos exclues du fonds ('
        . count($records['excluded']) . ')</summary><ul>';
    foreach ($records['excluded'] as $videoId => $excludedAt) {
        $html .= '<li><code>' . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '</code> '
            . videos_admin_action_form('pool_allow', $videoId, 'Réautoriser', $token) . '</li>';
    }
    $html .= '</ul></details>';
}
if (SEC_hasRights('videos.maintenance')) {
    $html .= '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="pool_rebuild">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">Reconstruire le catalogue permanent</button></form>';
}
$html .= '</section>';

if (SEC_hasRights('videos.moderate')) {
    $html .= '<section class="videos-admin-section"><h2>Décisions sur les chaînes</h2>';
    if (count($priorityChannels) === 0) {
        $html .= '<p>Aucune chaîne prioritaire.</p>';
    } else {
        $html .= '<p><strong>Chaînes prioritaires</strong></p><ul>';
        foreach ($priorityChannels as $channelId) {
            $channel = $cache->getChannel($channelId, true);
            $title = is_array($channel) && !empty($channel['snippet']['title'])
                ? $channel['snippet']['title'] : $channelId;
            $html .= '<li><a href="' . htmlspecialchars(plugin_idtourl_videos('', 'channel:' . $channelId), ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a> <code>'
                . htmlspecialchars($channelId, ENT_QUOTES, 'UTF-8') . '</code></li>';
        }
        $html .= '</ul>';
    }
    $html .= '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="channel_state">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<label>ID chaîne <input type="text" name="channel_id" maxlength="40" required></label> '
        . '<label>Décision <select name="channel_state"><option value="priority">Prioritaire</option>'
        . '<option value="allowed">Autorisée</option><option value="neutral">Neutre</option>'
        . '<option value="blocked">Bloquée</option><option value="disabled">Désactivée</option></select></label> '
        . '<button type="submit">Appliquer</button></form></section>';
}

$html .= '<section class="videos-admin-section"><h2>YouTube Data API</h2>'
    . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="save_key">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>Nouvelle clé API <input type="password" name="youtube_api_key" maxlength="200" autocomplete="new-password"></label> '
    . '<button type="submit">Enregistrer la clé</button></form>'
    . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="test_search">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>Recherche de test <input type="text" name="test_query" maxlength="250" size="50" required></label> '
    . '<button type="submit">Tester la recherche</button></form>';
if (is_array($searchResults) && !empty($searchResults['videos'])) {
    $html .= '<div class="videos-grid videos-admin-results">';
    foreach ($searchResults['videos'] as $videoId => $video) {
        $title = isset($video['snippet']['title']) ? $video['snippet']['title'] : $videoId;
        $html .= '<article class="videos-admin-result"><strong>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong><br><code>'
            . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '</code></article>';
    }
    $html .= '</div>';
}
if (SEC_hasRights('videos.maintenance')) {
    $html .= '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="seed_discovery">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<label>Requête d’amorçage <input type="text" name="seed_query" maxlength="250" size="50" required></label> '
        . '<button type="submit">Amorcer le réservoir</button></form>';
}
$html .= '</section>';

if (SEC_hasRights('videos.maintenance')) {
    $html .= '<section class="videos-admin-section"><h2>Maintenance</h2>'
        . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="rebuild_ranking">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">Reconstruire les classements</button></form>'
        . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="clear_cache">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<label>Cache <select name="cache_scope"><option value="search">Recherches</option><option value="videos">Vidéos</option>'
        . '<option value="channels">Chaînes</option><option value="availability">Disponibilité</option><option value="all">Tous</option></select></label> '
        . '<button type="submit">Vider le cache</button></form>'
        . '<p><a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/videos/repair.php', ENT_QUOTES, 'UTF-8') . '">Outils de réparation</a></p></section>';
}

$html .= '<section class="videos-admin-section"><h2>Indexation des pages existantes</h2>'
    . '<p>Le rattrapage inventorie les pages publiques puis utilise le mode batch d’IndexNow. Il ne génère pas de faux événements de création pour Hello ou les autres plugins.</p>'
    . '<form method="post"><input type="hidden" name="videos_action" value="signal_public_pages">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<button type="submit">Envoyer les pages existantes à IndexNow</button></form></section></div>';

$html = VIDEOS_localizeAdminText($html);
echo COM_createHTMLDocument($html, array('pagetitle' => VIDEOS_localizeAdminText('Videos — Actions'), 'headercode' => VIDEOS_adminHeaderCode()));

function videos_actions_test_search($bootstrap, $query, $configuration)
{
    $store = $bootstrap->getStore();
    $service = new Videos_YouTubeService(
        new Videos_YouTubeClient(
            $bootstrap->getYouTubeApiKey(),
            isset($configuration['youtube_timeout']) ? $configuration['youtube_timeout'] : 8
        ),
        new Videos_Cache($store),
        new Videos_Quota($store),
        new Videos_Logger($store)
    );
    return $service->find($query, videos_actions_search_parameters($configuration));
}

function videos_actions_seed_discovery($bootstrap, $query, $configuration)
{
    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    $service = new Videos_YouTubeService(
        new Videos_YouTubeClient(
            $bootstrap->getYouTubeApiKey(),
            isset($configuration['youtube_timeout']) ? $configuration['youtube_timeout'] : 8
        ),
        $cache,
        new Videos_Quota($store),
        new Videos_Logger($store)
    );
    return (new Videos_DiscoveryReservoir($store, $cache))->refresh(
        $query,
        videos_actions_search_parameters($configuration),
        $configuration,
        $service,
        true
    );
}

function videos_actions_search_parameters($configuration)
{
    return array(
        'max_results' => isset($configuration['youtube_max_results']) ? $configuration['youtube_max_results'] : 20,
        'order' => 'relevance',
        'safe_search' => isset($configuration['youtube_safe_search']) ? $configuration['youtube_safe_search'] : 'moderate',
        'language' => isset($configuration['language']) ? $configuration['language'] : 'fr',
        'region' => isset($configuration['region']) ? $configuration['region'] : 'FR',
        'published_after' => '',
        'category_id' => '',
        'channel_id' => '',
        'daily_search_limit' => isset($configuration['youtube_daily_search_limit']) ? $configuration['youtube_daily_search_limit'] : 20,
        'cache_ttl' => isset($configuration['search_cache_ttl']) ? $configuration['search_cache_ttl'] : 86400,
        'video_cache_ttl' => isset($configuration['video_cache_ttl']) ? $configuration['video_cache_ttl'] : 86400,
        'channel_cache_ttl' => isset($configuration['channel_cache_ttl']) ? $configuration['channel_cache_ttl'] : 604800,
        'availability_cache_ttl' => isset($configuration['availability_cache_ttl']) ? $configuration['availability_cache_ttl'] : 86400,
        'blocked_videos' => isset($configuration['blocked_videos']) ? $configuration['blocked_videos'] : '',
        'blocked_channels' => isset($configuration['blocked_channels']) ? $configuration['blocked_channels'] : '',
        'allowed_channels' => isset($configuration['allowed_channels']) ? $configuration['allowed_channels'] : '',
        'minimum_duration' => 0,
        'maximum_duration' => 0,
        'exclude_short_videos' => !empty($configuration['exclude_short_videos']) ? 1 : 0,
        'short_filter_mode' => isset($configuration['short_filter_mode']) ? $configuration['short_filter_mode'] : 'probable',
        'short_max_duration' => isset($configuration['short_max_duration']) ? $configuration['short_max_duration'] : 180
    );
}

function videos_admin_public_urls($store, $cache, $pool, $moderation, $ranking, $configuration)
{
    $ids = array(
        'catalogue' => true,
        'rankings:videos' => true,
        'rankings:channels' => true
    );
    $reservoirVideos = (new Videos_DiscoveryReservoir($store, $cache))->videos($configuration);
    if (is_array($reservoirVideos)) {
        foreach ($reservoirVideos as $videoId => $video) {
            if (videos_admin_video_is_public($videoId, $video, $cache, $moderation, $configuration)) {
                $ids[$videoId] = true;
            }
        }
    }
    foreach ($ranking->getGlobal(500) as $videoId => $item) {
        $video = $cache->getVideo($videoId, true);
        if (videos_admin_video_is_public($videoId, $video, $cache, $moderation, $configuration)) {
            $ids[$videoId] = true;
        }
    }
    $records = $pool->records();
    foreach (isset($records['items']) ? $records['items'] : array() as $videoId => $item) {
        $video = $cache->getVideo($videoId, true);
        if (videos_admin_video_is_public($videoId, $video, $cache, $moderation, $configuration)) {
            $ids[$videoId] = true;
        }
    }
    foreach ((new Videos_ChannelRanking($store, $cache))->getGlobal(250) as $channelId => $item) {
        if (Videos_Validator::youtubeChannelId($channelId) && !$moderation->isChannelExcluded($channelId) &&
            isset($item['video_count']) && (int) $item['video_count'] >= 2) {
            $ids['channel:' . $channelId] = true;
        }
    }
    foreach ($moderation->getPriorityChannelIds(500) as $channelId) {
        if (Videos_Validator::youtubeChannelId($channelId) && !$moderation->isChannelExcluded($channelId)) {
            $ids['channel:' . $channelId] = true;
        }
    }
    $urls = array();
    foreach (array_keys($ids) as $id) {
        $url = plugin_idtourl_videos('', $id);
        if ($url !== '') {
            $urls[$url] = true;
        }
    }
    return array_keys($urls);
}

function videos_admin_video_is_public($videoId, $video, $cache, $moderation, $configuration)
{
    if (!Videos_Validator::youtubeVideoId($videoId) || !is_array($video) ||
        $cache->isVideoUnavailable($videoId) || $moderation->isVideoBlocked($videoId)) {
        return false;
    }
    $channelId = isset($video['snippet']['channelId']) ? (string) $video['snippet']['channelId'] : '';
    return !$moderation->isChannelExcluded($channelId)
        && !Videos_VideoPolicy::excludesShortVideo($video, $configuration);
}

function videos_admin_extract_video_id($input)
{
    $input = trim((string) $input);
    if (Videos_Validator::youtubeVideoId($input)) {
        return $input;
    }
    $parts = parse_url($input);
    if (!is_array($parts)) {
        return '';
    }
    $host = isset($parts['host']) ? strtolower($parts['host']) : '';
    $path = isset($parts['path']) ? trim($parts['path'], '/') : '';
    if ($host === 'youtu.be') {
        $candidate = strtok($path, '/');
        return Videos_Validator::youtubeVideoId($candidate) ? $candidate : '';
    }
    if (strpos($host, 'youtube.com') !== false) {
        $query = array();
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        if (!empty($query['v']) && Videos_Validator::youtubeVideoId($query['v'])) {
            return $query['v'];
        }
        $segments = explode('/', $path);
        if (count($segments) >= 2 && in_array($segments[0], array('shorts', 'embed'), true) &&
            Videos_Validator::youtubeVideoId($segments[1])) {
            return $segments[1];
        }
    }
    return '';
}

function videos_admin_fetch_single_video($bootstrap, $cache, $videoId, $configuration)
{
    $quota = new Videos_Quota($bootstrap->getStore());
    if (!$quota->reserve('videos', 500)) {
        return false;
    }
    $client = new Videos_YouTubeClient(
        $bootstrap->getYouTubeApiKey(),
        isset($configuration['youtube_timeout']) ? $configuration['youtube_timeout'] : 8
    );
    $videos = $client->videos(array($videoId));
    if (!is_array($videos) || !isset($videos[$videoId])) {
        return false;
    }
    $video = $videos[$videoId];
    $status = isset($video['status']) ? $video['status'] : array();
    if (empty($status['embeddable']) || !isset($status['privacyStatus']) ||
        $status['privacyStatus'] !== 'public' || Videos_VideoPolicy::excludesShortVideo($video, $configuration)) {
        return false;
    }
    if (isset($video['contentDetails']['duration'])) {
        $video['videos_duration_seconds'] = videos_admin_duration_seconds($video['contentDetails']['duration']);
    }
    $ttl = isset($configuration['video_cache_ttl']) ? (int) $configuration['video_cache_ttl'] : 86400;
    if (!$cache->putVideo($videoId, $video, $ttl, 31536000)) {
        return false;
    }
    $cache->putAvailability(
        $videoId,
        true,
        'available',
        isset($configuration['availability_cache_ttl']) ? (int) $configuration['availability_cache_ttl'] : 86400
    );
    $channelId = isset($video['snippet']['channelId']) ? $video['snippet']['channelId'] : '';
    if (Videos_Validator::youtubeChannelId($channelId) && $quota->reserve('channels', 500)) {
        $channels = $client->channels(array($channelId));
        if (is_array($channels) && isset($channels[$channelId])) {
            $cache->putChannel(
                $channelId,
                $channels[$channelId],
                isset($configuration['channel_cache_ttl']) ? (int) $configuration['channel_cache_ttl'] : 604800,
                5184000
            );
        }
    }
    $quota->recordSuccess();
    return $video;
}

function videos_admin_duration_seconds($duration)
{
    if (!preg_match('/^P(?:(\\d+)D)?T?(?:(\\d+)H)?(?:(\\d+)M)?(?:(\\d+)S)?$/', (string) $duration, $m)) {
        return 0;
    }
    return (isset($m[1]) ? (int) $m[1] * 86400 : 0)
        + (isset($m[2]) ? (int) $m[2] * 3600 : 0)
        + (isset($m[3]) ? (int) $m[3] * 60 : 0)
        + (isset($m[4]) ? (int) $m[4] : 0);
}

function videos_admin_action_form($action, $videoId, $label, $token)
{
    return '<form class="videos-inline-form" method="post"><input type="hidden" name="videos_action" value="'
        . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="video_id" value="'
        . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '"><input type="hidden" name="' . CSRF_TOKEN
        . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"><button type="submit">'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</button></form>';
}

function videos_admin_section_nav($configuration, $active)
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
        $html .= '<li><a href="' . htmlspecialchars($base . $item[0], ENT_QUOTES, 'UTF-8') . '"'
            . ($key === $active ? ' class="is-active" aria-current="page"' : '') . '>'
            . htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html . '</ul></nav>';
}
