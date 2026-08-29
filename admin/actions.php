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
        if ($action === 'add_video') {
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
        } elseif (in_array($action, array('pool_add', 'pool_pin', 'pool_unpin', 'pool_remove'), true)) {
            $videoId = isset($_POST['video_id']) ? COM_applyFilter($_POST['video_id']) : '';
            $stateMap = array(
                'pool_add' => 'added',
                'pool_pin' => 'pinned',
                'pool_unpin' => 'unpinned',
                'pool_remove' => 'removed'
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
                if ($saved && function_exists('VIDEOS_signalSaved')) {
                    VIDEOS_signalSaved('rankings:channels');
                    VIDEOS_signalSaved('catalogue');
                    VIDEOS_signalSaved('channel:' . $channelId);
                }
                $message = $saved ? 'Décision sur la chaîne enregistrée.' : 'Impossible de modifier la chaîne.';
            }
        } elseif ($action === 'signal_public_pages') {
            if (function_exists('VIDEOS_signalSaved')) {
                $signalIds = videos_admin_public_signal_ids(
                    $store,
                    $cache,
                    $pool,
                    $moderation,
                    $ranking,
                    $_VIDEOS_CONF
                );
                foreach ($signalIds as $signalId) {
                    VIDEOS_signalSaved($signalId);
                }
                $message = count($signalIds)
                    . ' ressource(s) publique(s) ont été signalée(s) aux consommateurs Geeklog.';
            }
        }
    }
}

$token = SEC_createToken();
$records = $pool ? $pool->records() : array('items' => array());
$priorityChannels = $moderation ? $moderation->getPriorityChannelIds(100) : array();

$html = '<div class="videos-admin"><h1>Videos — Actions</h1>'
    . videos_admin_section_nav($_CONF, 'actions');
if ($message !== '') {
    $html .= COM_showMessageText($message, '', true);
}
$html .= '<section class="videos-admin-section"><h2>Ajouter une vidéo</h2>'
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
        if ($isPinned) {
            $html .= videos_admin_action_form('pool_unpin', $videoId, 'Désépingler', $token);
        } else {
            $html .= videos_admin_action_form('pool_pin', $videoId, 'Épingler', $token);
        }
        $html .= videos_admin_action_form('pool_remove', $videoId, 'Retirer du permanent', $token)
            . '</td></tr>';
    }
    $html .= '</tbody></table></div>';
}
$html .= '</section>';

if (SEC_hasRights('videos.moderate')) {
    $html .= '<section class="videos-admin-section"><h2>Chaînes prioritaires</h2>';
    if (count($priorityChannels) === 0) {
        $html .= '<p>Aucune chaîne prioritaire.</p>';
    } else {
        $html .= '<ul>';
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

$html .= '<section class="videos-admin-section"><h2>Indexation des pages existantes</h2>'
    . '<p>Cette action réémet les événements Geeklog pour le catalogue, les classements, les vidéos actives du réservoir, les vidéos du classement global, le catalogue permanent et les pages de chaînes éligibles. IndexNow peut ensuite résoudre et dédupliquer les URLs.</p>'
    . '<form method="post"><input type="hidden" name="videos_action" value="signal_public_pages">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<button type="submit">Signaler les pages existantes</button></form></section></div>';

echo COM_createHTMLDocument($html, array('pagetitle' => 'Videos — Actions', 'headercode' => VIDEOS_adminHeaderCode()));

function videos_admin_public_signal_ids($store, $cache, $pool, $moderation, $ranking, $configuration)
{
    $ids = array(
        'catalogue' => true,
        'rankings:videos' => true,
        'rankings:channels' => true
    );

    $reservoir = new Videos_DiscoveryReservoir($store, $cache);
    $reservoirVideos = $reservoir->videos($configuration);
    if (is_array($reservoirVideos)) {
        foreach ($reservoirVideos as $videoId => $video) {
            if (videos_admin_video_is_public($videoId, $video, $cache, $moderation, $configuration)) {
                $ids[$videoId] = true;
            }
        }
    }

    $global = $ranking->getGlobal(500);
    foreach ($global as $videoId => $item) {
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

    $channelRanking = (new Videos_ChannelRanking($store, $cache))->getGlobal(250);
    foreach ($channelRanking as $channelId => $item) {
        if (!Videos_Validator::youtubeChannelId($channelId) ||
            $moderation->isChannelExcluded($channelId)) {
            continue;
        }
        if (isset($item['video_count']) && (int) $item['video_count'] >= 2) {
            $ids['channel:' . $channelId] = true;
        }
    }
    foreach ($moderation->getPriorityChannelIds(500) as $channelId) {
        if (Videos_Validator::youtubeChannelId($channelId) &&
            !$moderation->isChannelExcluded($channelId)) {
            $ids['channel:' . $channelId] = true;
        }
    }

    return array_keys($ids);
}

function videos_admin_video_is_public($videoId, $video, $cache, $moderation, $configuration)
{
    if (!Videos_Validator::youtubeVideoId($videoId) || !is_array($video) ||
        $cache->isVideoUnavailable($videoId) || $moderation->isVideoBlocked($videoId)) {
        return false;
    }
    $channelId = isset($video['snippet']['channelId'])
        ? (string) $video['snippet']['channelId'] : '';
    if ($moderation->isChannelExcluded($channelId) ||
        Videos_VideoPolicy::excludesShortVideo($video, $configuration)) {
        return false;
    }
    return true;
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
        $status['privacyStatus'] !== 'public' ||
        Videos_VideoPolicy::excludesShortVideo($video, $configuration)) {
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
        isset($configuration['availability_cache_ttl'])
            ? (int) $configuration['availability_cache_ttl'] : 86400
    );
    $channelId = isset($video['snippet']['channelId']) ? $video['snippet']['channelId'] : '';
    if (Videos_Validator::youtubeChannelId($channelId) && $quota->reserve('channels', 500)) {
        $channels = $client->channels(array($channelId));
        if (is_array($channels) && isset($channels[$channelId])) {
            $cache->putChannel(
                $channelId,
                $channels[$channelId],
                isset($configuration['channel_cache_ttl'])
                    ? (int) $configuration['channel_cache_ttl'] : 604800,
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
