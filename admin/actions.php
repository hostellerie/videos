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
    $message = $LANG_VIDEOS['admin_videos_plugin_storage_unavailable'];
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
        $message = $LANG_VIDEOS['admin_security_token_has_expired_please_try_again'];
    } else {
        $action = isset($_POST['videos_action']) ? COM_applyFilter($_POST['videos_action']) : '';
        if ($action === 'save_key') {
            $key = isset($_POST['youtube_api_key']) ? trim((string) $_POST['youtube_api_key']) : '';
            $message = $bootstrap->setYouTubeApiKey($key)
                ? $LANG_VIDEOS['admin_youtube_data_api_key_has_been_saved']
                : $LANG_VIDEOS['admin_api_key_invalid'];
        } elseif ($action === 'test_search') {
            $query = isset($_POST['test_query']) ? trim(strip_tags((string) $_POST['test_query'])) : '';
            if ($query === '' || strlen($query) > 250) {
                $message = $LANG_VIDEOS['admin_test_query_invalid'];
            } else {
                $searchResults = videos_actions_test_search($bootstrap, $query, $_VIDEOS_CONF);
                $message = $searchResults === false
                    ? videos_actions_failure_message($store, $_VIDEOS_CONF, $LANG_VIDEOS['admin_test_search_failed'])
                    : count($searchResults['video_ids']) . $LANG_VIDEOS['admin_valid_video_s_found'];
            }
        } elseif ($action === 'seed_discovery' && SEC_hasRights('videos.maintenance')) {
            $query = isset($_POST['seed_query']) ? trim(strip_tags((string) $_POST['seed_query'])) : '';
            if ($query === '' || strlen($query) > 250) {
                $message = $LANG_VIDEOS['admin_seed_query_invalid'];
            } else {
                $result = videos_actions_seed_discovery($bootstrap, $query, $_VIDEOS_CONF);
                $message = is_array($result) && !empty($result['success'])
                    ? (int) $result['added'] . $LANG_VIDEOS['admin_video_s_added_reservoir']
                    : videos_actions_failure_message($store, $_VIDEOS_CONF, $LANG_VIDEOS['admin_reservoir_seeding_failed']);
            }
        } elseif ($action === 'clear_cache' && SEC_hasRights('videos.maintenance')) {
            $scope = isset($_POST['cache_scope']) ? COM_applyFilter($_POST['cache_scope']) : '';
            $result = (new Videos_CacheMaintenance($store))->clear($scope);
            $message = !empty($result['success'])
                ? (int) $result['deleted'] . $LANG_VIDEOS['admin_cache_entrie_s_deleted']
                : $LANG_VIDEOS['admin_partial_cleanup'] . (int) $result['deleted'] . $LANG_VIDEOS['admin_deleted']
                    . (int) $result['failed'] . $LANG_VIDEOS['admin_failure_s'];
        } elseif ($action === 'rebuild_ranking' && SEC_hasRights('videos.maintenance')) {
            $count = $ranking->rebuild();
            $message = $count === false
                ? $LANG_VIDEOS['admin_ranking_rebuild_failed']
                : $LANG_VIDEOS['admin_rankings_rebuilt'] . (int) $count . $LANG_VIDEOS['admin_ranked_video_s'];
        } elseif ($action === 'pool_rebuild' && SEC_hasRights('videos.maintenance')) {
            $result = $pool->synchronize($ranking->getGlobal(500), $_VIDEOS_CONF, true);
            $message = $result === false
                ? $LANG_VIDEOS['admin_permanent_catalogue_rebuild_failed']
                : $LANG_VIDEOS['admin_permanent_catalogue_has_been_rebuilt'];
        } elseif ($action === 'add_video') {
            $input = isset($_POST['video_input']) ? trim((string) $_POST['video_input']) : '';
            $videoId = videos_admin_extract_video_id($input);
            if ($videoId === '') {
                $message = $LANG_VIDEOS['admin_invalid_youtube_id_url'];
            } else {
                $video = $cache->getVideo($videoId, true);
                if (!is_array($video)) {
                    $video = videos_admin_fetch_single_video($bootstrap, $cache, $videoId, $_VIDEOS_CONF);
                }
                if (!is_array($video)) {
                    $message = $LANG_VIDEOS['admin_video_unavailable_private_not_embeddable_rejected_by'];
                } elseif ($moderation->isVideoBlocked($videoId)) {
                    $message = $LANG_VIDEOS['admin_video_currently_blocked_by_moderation'];
                } else {
                    $global = $ranking->getGlobal(500);
                    $rankingItem = isset($global[$videoId]) ? $global[$videoId] : array();
                    $saved = $pool->setManualState($videoId, 'added', $rankingItem);
                    $message = $saved
                        ? $LANG_VIDEOS['admin_video_added_permanent_catalogue']
                        : $LANG_VIDEOS['admin_unable_add_video_permanent_catalogue'];
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
                ? $LANG_VIDEOS['admin_editorial_decision_saved']
                : $LANG_VIDEOS['admin_unable_save_decision'];
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
                $message = $LANG_VIDEOS['admin_no_public_videos_url_submit'];
            } elseif (function_exists('send_to_indexnow')) {
                $result = send_to_indexnow(
                    $urls,
                    array('item_type' => 'videos', 'event' => 'manual-sync')
                );
                $message = $result === false
                    ? $LANG_VIDEOS['admin_indexnow_batch_could_not_be_sent']
                    : count($urls) . $LANG_VIDEOS['admin_videos_url_s_sent_indexnow_one_batch'];
            } else {
                $message = $LANG_VIDEOS['admin_indexnow_plugin_unavailable_no_fake_content_creation'];
            }
        }
    }
}

$token = SEC_createToken();
$records = $pool ? $pool->records() : array('items' => array(), 'excluded' => array());

$html = VIDEOS_adminPageOpen('actions', $LANG_VIDEOS['admin_nav_actions']);
if ($message !== '') {
    if ($message === $LANG_VIDEOS['admin_video_added_permanent_catalogue']) {
        $html .= '<p class="videos-admin-help"><strong>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</strong></p>';
    } else {
        $html .= COM_showMessageText($message, '', true);
    }
}
$html .= '<section class="videos-admin-section"><h2>' . $LANG_VIDEOS['admin_video_curation'] . '</h2>'
    . '<p>' . $LANG_VIDEOS['admin_add_video_directly_by_its_youtube_id'] . '</p>'
    . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="add_video">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>' . $LANG_VIDEOS['admin_youtube_id_url'] . ' <input type="text" name="video_input" maxlength="500" size="60" placeholder="H5nzrlARuCo' . $LANG_VIDEOS['admin_https_youtu_be'] . '" required></label> '
    . '<button type="submit">' . $LANG_VIDEOS['admin_add_permanent_catalogue'] . '</button></form></section>';

$html .= '<section class="videos-admin-section"><h2>' . $LANG_VIDEOS['admin_permanent_catalogue'] . '</h2>';
if (empty($records['items'])) {
    $html .= '<p>' . $LANG_VIDEOS['admin_no_retained_video'] . '</p>';
} else {
    $html .= '<div class="videos-admin-table-wrap"><table class="admin-list videos-admin-table"><thead><tr>'
        . '<th>' . $LANG_VIDEOS['admin_video'] . '</th><th>' . $LANG_VIDEOS['admin_status_db27'] . '</th><th>' . $LANG_VIDEOS['admin_actions_column'] . '</th></tr></thead><tbody>';
    foreach ($records['items'] as $videoId => $item) {
        $video = $cache->getVideo($videoId, true);
        $title = is_array($video) && !empty($video['snippet']['title'])
            ? $video['snippet']['title'] : $videoId;
        $isPinned = !empty($item['pinned']);
        $html .= '<tr><td><a href="' . htmlspecialchars(plugin_idtourl_videos('', $videoId), ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a><br><code>'
            . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '</code></td><td>'
            . ($isPinned ? $LANG_VIDEOS['admin_pinned'] : $LANG_VIDEOS['admin_permanent']) . '</td><td>';
        $html .= $isPinned
            ? videos_admin_action_form('pool_unpin', $videoId, $LANG_VIDEOS['admin_unpin'], $token)
            : videos_admin_action_form('pool_pin', $videoId, $LANG_VIDEOS['admin_pin'], $token);
        $html .= videos_admin_action_form('pool_remove', $videoId, $LANG_VIDEOS['admin_remove_from_selection'], $token)
            . videos_admin_action_form('pool_exclude', $videoId, $LANG_VIDEOS['admin_exclude_from_future_selections'], $token)
            . '</td></tr>';
    }
    $html .= '</tbody></table></div>'
        . '<p class="videos-admin-help"><strong>' . $LANG_VIDEOS['admin_remove_from_selection'] . '</strong> ' . $LANG_VIDEOS['admin_remove_help'] . ' '
        . '<strong>' . $LANG_VIDEOS['admin_exclude_from_future_selections'] . '</strong> ' . $LANG_VIDEOS['admin_exclude_help'] . '</p>';
}
if (!empty($records['excluded'])) {
    $html .= '<details class="videos-advanced-field"><summary>' . $LANG_VIDEOS['admin_videos_excluded_from_pool'] . ' ('
        . count($records['excluded']) . ')</summary><ul>';
    foreach ($records['excluded'] as $videoId => $excludedAt) {
        $html .= '<li><code>' . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '</code> '
            . videos_admin_action_form('pool_allow', $videoId, $LANG_VIDEOS['admin_allow_again'], $token) . '</li>';
    }
    $html .= '</ul></details>';
}
if (SEC_hasRights('videos.maintenance')) {
    $html .= '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="pool_rebuild">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">' . $LANG_VIDEOS['admin_rebuild_permanent_catalogue'] . '</button></form>';
}
$html .= '</section>';

$youtubeApiKeyConfigured = $bootstrap->getYouTubeApiKey() !== '';
$html .= '<section class="videos-admin-section"><h2>YouTube Data API</h2>'
    . '<p><strong>' . $LANG_VIDEOS['admin_status'] . '</strong> ' . ($youtubeApiKeyConfigured ? $LANG_VIDEOS['admin_api_key_configured'] : $LANG_VIDEOS['admin_api_key_missing']) . '</p>';
if (!$youtubeApiKeyConfigured) {
    $html .= '<p>' . $LANG_VIDEOS['admin_youtube_data_api_key_required_search_new'] . '</p>';
}
$html .= '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="save_key">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>' . ($youtubeApiKeyConfigured ? $LANG_VIDEOS['admin_replace_api_key'] : $LANG_VIDEOS['admin_add_api_key']) . ' <input type="password" name="youtube_api_key" maxlength="200" autocomplete="new-password"></label> '
    . '<button type="submit">' . ($youtubeApiKeyConfigured ? $LANG_VIDEOS['admin_replace_key'] : $LANG_VIDEOS['admin_save_key']) . '</button></form>'
    . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="test_search">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>' . $LANG_VIDEOS['admin_test_search'] . ' <input type="text" name="test_query" maxlength="250" size="50" required></label> '
    . '<button type="submit">' . $LANG_VIDEOS['admin_test_search_8cfd'] . '</button></form>';
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
        . '<label>' . $LANG_VIDEOS['admin_seed_query'] . ' <input type="text" name="seed_query" maxlength="250" size="50" required></label> '
        . '<button type="submit">' . $LANG_VIDEOS['admin_seed_reservoir'] . '</button></form>';
}
$html .= '</section>';

if (SEC_hasRights('videos.maintenance')) {
    $html .= '<section class="videos-admin-section"><h2>' . $LANG_VIDEOS['admin_maintenance'] . '</h2>'
        . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="rebuild_ranking">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">' . $LANG_VIDEOS['admin_rebuild_rankings'] . '</button></form>'
        . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="clear_cache">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<label>' . $LANG_VIDEOS['admin_cache_label'] . ' <select name="cache_scope"><option value="search">' . $LANG_VIDEOS['admin_searches'] . '</option><option value="videos">' . $LANG_VIDEOS['admin_videos'] . '</option>'
        . '<option value="channels">' . $LANG_VIDEOS['admin_channels'] . '</option><option value="availability">' . $LANG_VIDEOS['admin_availability'] . '</option><option value="all">' . $LANG_VIDEOS['admin_all'] . '</option></select></label> '
        . '<button type="submit">' . $LANG_VIDEOS['admin_clear_cache'] . '</button></form>'
        . '<p><a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/videos/repair.php', ENT_QUOTES, 'UTF-8') . '">' . $LANG_VIDEOS['admin_repair_tools'] . '</a></p></section>';
}

if (function_exists('send_to_indexnow')) {
    $html .= '<section class="videos-admin-section"><h2>' . $LANG_VIDEOS['admin_index_existing_pages'] . '</h2>'
        . '<p>' . $LANG_VIDEOS['admin_catch_up_process_inventories_public_pages_sends'] . '</p>'
        . '<form method="post"><input type="hidden" name="videos_action" value="signal_public_pages">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">' . $LANG_VIDEOS['admin_send_existing_pages_indexnow'] . '</button></form></section>';
}
$html .= VIDEOS_adminPageClose();


echo COM_createHTMLDocument($html, array('pagetitle' => $LANG_VIDEOS['admin_title'], 'headercode' => VIDEOS_adminHeaderCode()));

function videos_actions_failure_message($store, $configuration, $prefix)
{
    global $LANG_VIDEOS;

    $status = (new Videos_Quota($store))->status();
    $data = isset($status['data']) && is_array($status['data']) ? $status['data'] : array();
    $counts = isset($data['counts']) && is_array($data['counts']) ? $data['counts'] : array();
    $count = isset($counts['search']) ? (int) $counts['search'] : 0;
    $limit = isset($configuration['youtube_daily_search_limit'])
        ? max(0, (int) $configuration['youtube_daily_search_limit']) : 20;
    if (!empty($data['suspended'])) {
        $code = !empty($data['last_error']['code']) ? (string) $data['last_error']['code'] : 'quota';
        return $prefix . ' ' . $LANG_VIDEOS['admin_youtube_quota_suspended'] . $code . ').';
    }
    if ($limit > 0 && $count >= $limit) {
        return $prefix . ' ' . $LANG_VIDEOS['admin_local_youtube_search_limit_has_been_reached']
            . $count . '/' . $limit . $LANG_VIDEOS['admin_today'];
    }
    if (!empty($data['last_error']['code'])) {
        return $prefix . ' ' . $LANG_VIDEOS['admin_last_youtube_error'] . (string) $data['last_error']['code']
            . '. ' . $LANG_VIDEOS['admin_consult'] . ' ' . $LANG_VIDEOS['admin_statistics'] . ' > ' . $LANG_VIDEOS['admin_youtube_api_activity'] . '.';
    }
    return $prefix . ' ' . $LANG_VIDEOS['admin_see_statistics_youtube_api_activity_diagnostics'];
}

function videos_actions_test_search($bootstrap, $query, $configuration)
{
    return (new Videos_ExternalSync($bootstrap, $configuration))->search($query);
}

function videos_actions_seed_discovery($bootstrap, $query, $configuration)
{
    return (new Videos_ExternalSync($bootstrap, $configuration))->seedDiscovery($query);
}

function videos_actions_search_parameters($configuration)
{
    return Videos_ExternalSync::searchParameters($configuration);
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
    return (new Videos_ExternalSync($bootstrap, $configuration))->fetchVideo($videoId);
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
