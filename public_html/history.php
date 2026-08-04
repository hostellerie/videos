<?php

require_once '../lib-common.php';

$seo = new Videos_Seo(
    $_CONF['site_url'],
    isset($_CONF['site_name']) ? $_CONF['site_name'] : '',
    $_VIDEOS_CONF
);
$historyHeader = $seo->privatePage(
    $_CONF['site_url'] . '/videos/history.php'
);

if (COM_isAnonUser() || !SEC_hasRights('videos.personal_history')) {
    echo COM_createHTMLDocument(
        COM_showMessageText($LANG_VIDEOS['access_denied'], '', true),
        array(
            'pagetitle' => $LANG_VIDEOS['my_videos'],
            'headercode' => $historyHeader
        )
    );
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    echo COM_createHTMLDocument(
        COM_showMessageText('Historique temporairement indisponible.', '', true),
        array(
            'pagetitle' => $LANG_VIDEOS['my_videos'],
            'headercode' => $historyHeader
        )
    );
    exit;
}

$privacy = new Videos_Privacy(
    $bootstrap->getStore(),
    $bootstrap->getSecret()
);
$historyMessage = isset($_GET['videos_message'])
    ? (string) $_GET['videos_message'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['videos_action'])
        ? COM_applyFilter($_POST['videos_action']) : '';
    if (!SEC_checkToken()) {
        $historyMessage = 'csrf';
    } elseif ($action === 'export') {
        if (empty($_VIDEOS_CONF['allow_user_export'])) {
            $historyMessage = 'export_denied';
        } else {
            $export = $privacy->exportAccountData((int) $_USER['uid']);
            $json = is_array($export)
                ? json_encode(
                    $export,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ) : false;
            if ($json === false) {
                $historyMessage = 'export_failed';
            } else {
                header('Content-Type: application/json; charset=utf-8');
                header(
                    'Content-Disposition: attachment; filename="'
                    . 'videos-account-' . gmdate('Y-m-d') . '.json"'
                );
                header('Cache-Control: no-store, no-cache, must-revalidate');
                header('X-Content-Type-Options: nosniff');
                echo $json;
                exit;
            }
        }
    } elseif ($action === 'delete') {
        if (empty($_VIDEOS_CONF['allow_user_deletion'])) {
            $historyMessage = 'delete_denied';
        } elseif (!isset($_POST['confirm_delete']) ||
            $_POST['confirm_delete'] !== '1') {
            $historyMessage = 'delete_confirm';
        } else {
            $previousHistory = $privacy->accountHistory(
                (int) $_USER['uid']
            );
            if ($privacy->deleteAccountData((int) $_USER['uid'])) {
                videos_history_rebuild_after_deletion(
                    $bootstrap,
                    is_array($previousHistory) &&
                        isset($previousHistory['ratings'])
                        ? $previousHistory['ratings'] : array()
                );
                header(
                    'Location: ' . $_CONF['site_url']
                    . '/videos/history.php?videos_message=deleted'
                );
                exit;
            }
            $historyMessage = 'delete_failed';
        }
    } elseif ($action === 'delete_rating') {
        $deleteVideoId = isset($_POST['video_id'])
            ? COM_applyFilter($_POST['video_id']) : '';
        if (!Videos_Validator::youtubeVideoId($deleteVideoId)) {
            $historyMessage = 'rating_delete_failed';
        } else {
            $visitor = new Videos_Visitor(
                $privacy,
                (int) $_USER['uid']
            );
            $limiter = new Videos_RateLimiter($bootstrap->getStore());
            if (!$limiter->consume(
                $visitor->getSubjectHash(),
                'rating_delete',
                20,
                3600
            )) {
                $historyMessage = 'rating_delete_failed';
            } else {
                $ratingService = new Videos_RatingService(
                    $bootstrap->getStore(),
                    $privacy
                );
                $removed = $ratingService->remove(
                    $visitor,
                    $deleteVideoId
                );
                if ($removed === false) {
                    $historyMessage = 'rating_delete_failed';
                } else {
                    videos_history_rebuild_after_deletion(
                        $bootstrap,
                        array($deleteVideoId => true)
                    );
                    header(
                        'Location: ' . $_CONF['site_url']
                        . '/videos/history.php?videos_message=rating_deleted'
                    );
                    exit;
                }
            }
        }
    }
}

$history = $privacy->accountHistory((int) $_USER['uid']);
$cache = new Videos_Cache($bootstrap->getStore());
$ratingStatsService = new Videos_RatingStats($bootstrap->getStore());
$individualRatingToken = SEC_createToken();

$html = '<div class="videos-history">'
    . VIDEOS_renderNavigation('history')
    . '<h1>'
    . htmlspecialchars($LANG_VIDEOS['my_videos'], ENT_QUOTES, 'UTF-8')
    . '</h1><p>Ces données sont associées à un identifiant pseudonyme. '
    . 'Elles sont conservées sans expiration automatique.</p>';

if ($historyMessage !== '') {
    $messageKey = 'history_' . $historyMessage;
    if (isset($LANG_VIDEOS[$messageKey])) {
        $html .= COM_showMessageText(
            $LANG_VIDEOS[$messageKey],
            '',
            true
        );
    }
}

$views = is_array($history) ? $history['views'] : array();
$ratings = is_array($history) ? $history['ratings'] : array();
$videoIds = array_values(array_unique(array_merge(
    array_keys($views),
    array_keys($ratings)
)));
usort($videoIds, 'videos_history_sort_ids');

$html .= '<div class="videos-history-filters" role="group" aria-label="Filtres">'
    . '<button type="button" data-filter="all" class="is-active">'
    . htmlspecialchars($LANG_VIDEOS['all_videos'], ENT_QUOTES, 'UTF-8')
    . '</button><button type="button" data-filter="watched">'
    . htmlspecialchars($LANG_VIDEOS['watched_videos'], ENT_QUOTES, 'UTF-8')
    . '</button><button type="button" data-filter="rated">'
    . htmlspecialchars($LANG_VIDEOS['rated_videos'], ENT_QUOTES, 'UTF-8')
    . '</button></div><div class="videos-history-grid">';

foreach ($videoIds as $videoId) {
    if (!Videos_Validator::youtubeVideoId($videoId)) {
        continue;
    }
    $view = isset($views[$videoId]) ? $views[$videoId] : array();
    $rating = isset($ratings[$videoId]) ? $ratings[$videoId] : array();
    $video = $cache->getVideo($videoId, true);
    $snippet = is_array($video) && isset($video['snippet'])
        ? $video['snippet'] : array();
    $title = isset($snippet['title']) ? $snippet['title'] : $videoId;
    $channel = isset($snippet['channelTitle'])
        ? $snippet['channelTitle'] : '';
    $thumbnail = isset($snippet['thumbnails']['medium']['url'])
        ? $snippet['thumbnails']['medium']['url'] : '';
    $duration = is_array($video) && isset($video['videos_duration_seconds'])
        ? (int) $video['videos_duration_seconds'] : 0;
    $progress = isset($view['watched_ratio'])
        ? max(0, min(100, round($view['watched_ratio'] * 100))) : 0;
    $classes = array('videos-history-card');
    if (!empty($view)) {
        $classes[] = 'is-watched';
    }
    if (!empty($rating)) {
        $classes[] = 'is-rated';
    }
    $url = $_CONF['site_url'] . '/videos/watch.php?v='
        . rawurlencode($videoId);
    $summary = $ratingStatsService->get($videoId);

    $html .= '<article class="' . implode(' ', $classes) . '">';
    if (strpos($thumbnail, 'https://') === 0) {
        $html .= '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
            . '"><img loading="lazy" src="'
            . htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8')
            . '" alt=""></a>';
    }
    $html .= '<div class="videos-history-content"><h2><a href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a></h2>';
    if ($channel !== '') {
        $html .= '<p class="videos-channel">'
            . htmlspecialchars($channel, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if (!empty($view)) {
        $viewedAt = isset($view['viewed_at'])
            ? (string) $view['viewed_at'] : '';
        $viewCount = isset($view['view_count'])
            ? (int) $view['view_count'] : 0;
        $html .= '<div class="videos-progress" aria-label="Progression '
            . $progress . '%"><span style="width:' . $progress
            . '%"></span></div><p>'
            . htmlspecialchars($LANG_VIDEOS['watched_on'], ENT_QUOTES, 'UTF-8')
            . ' ' . htmlspecialchars(
                videos_history_local_date($viewedAt),
                ENT_QUOTES,
                'UTF-8'
            ) . ' · ' . htmlspecialchars(
                $LANG_VIDEOS['personal_views'],
                ENT_QUOTES,
                'UTF-8'
            ) . ' : ' . $viewCount . '</p>';
    }
    if ($duration > 0) {
        $html .= '<p>Durée : ' . videos_history_duration($duration) . '</p>';
    }
    if (!empty($rating)) {
        $personalRating = isset($rating['rating'])
            ? (int) $rating['rating'] : 0;
        $html .= '<p><strong>'
            . htmlspecialchars($LANG_VIDEOS['your_rating'], ENT_QUOTES, 'UTF-8')
            . ' :</strong> ' . videos_history_stars($personalRating)
            . '</p><form method="post" action="" class="videos-rating-delete-form" '
            . 'onsubmit="return confirm('
            . htmlspecialchars(
                json_encode($LANG_VIDEOS['rating_delete_confirm']),
                ENT_QUOTES,
                'UTF-8'
            ) . ');"><input type="hidden" name="videos_action" '
            . 'value="delete_rating"><input type="hidden" name="video_id" '
            . 'value="' . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8')
            . '"><input type="hidden" name="' . CSRF_TOKEN . '" value="'
            . htmlspecialchars(
                $individualRatingToken,
                ENT_QUOTES,
                'UTF-8'
            ) . '"><button type="submit" class="videos-rating-delete">'
            . htmlspecialchars(
                $LANG_VIDEOS['rating_delete'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</button></form>';
    }
    $html .= '<p><strong>'
        . htmlspecialchars($LANG_VIDEOS['local_average'], ENT_QUOTES, 'UTF-8')
        . ' :</strong> ' . number_format(
            (float) $summary['rating_average'],
            2,
            ',',
            ' '
        ) . '/5 (' . (int) $summary['rating_count'] . ')</p>'
        . '<a class="videos-watch-again" href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($LANG_VIDEOS['watch_again'], ENT_QUOTES, 'UTF-8')
        . '</a></div></article>';
}
$html .= '</div><p class="videos-history-empty" hidden>'
    . htmlspecialchars($LANG_VIDEOS['no_personal_videos'], ENT_QUOTES, 'UTF-8')
    . '</p>';

if (!empty($_VIDEOS_CONF['allow_user_export']) ||
    !empty($_VIDEOS_CONF['allow_user_deletion'])) {
    $token = SEC_createToken();
    $html .= '<section class="videos-history-data"><h2>'
        . htmlspecialchars(
            $LANG_VIDEOS['manage_personal_data'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</h2><p>'
        . htmlspecialchars(
            $LANG_VIDEOS['manage_personal_data_help'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</p><div class="videos-history-data-actions">';
    if (!empty($_VIDEOS_CONF['allow_user_export'])) {
        $html .= '<form method="post" action="">'
            . '<input type="hidden" name="videos_action" value="export">'
            . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
            . '<button type="submit" class="videos-data-export">'
            . htmlspecialchars(
                $LANG_VIDEOS['export_personal_data'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</button></form>';
    }
    if (!empty($_VIDEOS_CONF['allow_user_deletion'])) {
        $html .= '<form method="post" action="" class="videos-data-delete" '
            . 'onsubmit="return confirm('
            . htmlspecialchars(
                json_encode($LANG_VIDEOS['delete_personal_data_confirm']),
                ENT_QUOTES,
                'UTF-8'
            ) . ');"><input type="hidden" name="videos_action" '
            . 'value="delete"><input type="hidden" name="'
            . CSRF_TOKEN . '" value="'
            . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '"><label>'
            . '<input type="checkbox" name="confirm_delete" value="1" '
            . 'required> ' . htmlspecialchars(
                $LANG_VIDEOS['delete_personal_data_checkbox'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</label><button type="submit">'
            . htmlspecialchars(
                $LANG_VIDEOS['delete_personal_data'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</button></form>';
    }
    $html .= '</div></section>';
}

$html .= '</div><script src="' . htmlspecialchars(
        $_CONF['site_url'] . '/videos/js/videos-history.js?v='
            . rawurlencode(VIDEOS_PLUGIN_VERSION),
        ENT_QUOTES,
        'UTF-8'
    ) . '"></script>';

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $LANG_VIDEOS['my_videos'],
        'headercode' => $historyHeader
    )
);

function videos_history_sort_ids($leftId, $rightId)
{
    global $history;
    $leftDate = isset($history['views'][$leftId]['viewed_at'])
        ? $history['views'][$leftId]['viewed_at']
        : (isset($history['ratings'][$leftId]['rated_at'])
            ? $history['ratings'][$leftId]['rated_at'] : '');
    $rightDate = isset($history['views'][$rightId]['viewed_at'])
        ? $history['views'][$rightId]['viewed_at']
        : (isset($history['ratings'][$rightId]['rated_at'])
            ? $history['ratings'][$rightId]['rated_at'] : '');
    return strcmp($rightDate, $leftDate);
}

function videos_history_local_date($isoDate)
{
    $timestamp = strtotime($isoDate);
    if ($timestamp === false) {
        return '';
    }
    if (function_exists('COM_getUserDateTimeFormat')) {
        $formatted = COM_getUserDateTimeFormat($timestamp);
        if (is_array($formatted) && isset($formatted[0])) {
            return $formatted[0];
        }
    }
    return date('d/m/Y H:i', $timestamp);
}

function videos_history_duration($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remaining = $seconds % 60;
    return ($hours > 0 ? $hours . ':' : '')
        . ($hours > 0 ? str_pad($minutes, 2, '0', STR_PAD_LEFT) : $minutes)
        . ':' . str_pad($remaining, 2, '0', STR_PAD_LEFT);
}

function videos_history_stars($rating)
{
    $html = '<span class="videos-static-stars" aria-label="'
        . $rating . ' sur 5">';
    for ($i = 1; $i <= 5; $i++) {
        $html .= '<span class="' . ($i <= $rating ? 'is-filled' : '')
            . '" aria-hidden="true">&#9733;</span>';
    }
    return $html . '</span>';
}

function videos_history_rebuild_after_deletion($bootstrap, $ratings)
{
    if (!is_array($ratings)) {
        return;
    }
    $store = $bootstrap->getStore();
    $ratingStats = new Videos_RatingStats($store);
    $cache = new Videos_Cache($store);
    $ranking = new Videos_Ranking(
        $store,
        $ratingStats,
        new Videos_VideoStats($store),
        $cache
    );
    foreach (array_keys($ratings) as $videoId) {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            continue;
        }
        if ($ratingStats->rebuild($videoId) === false) {
            COM_errorLog(
                'Videos privacy deletion: rating statistics rebuild failed.',
                1
            );
            continue;
        }
        $video = $cache->getVideo($videoId, true);
        if ($ranking->updateVideo(
            $videoId,
            is_array($video) ? $video : array(),
            false
        ) === false) {
            COM_errorLog(
                'Videos privacy deletion: ranking update failed.',
                1
            );
        }
    }
    if (count($ratings) > 0 && $ranking->rebuildChannels() === false) {
        COM_errorLog(
            'Videos privacy deletion: channel ranking rebuild failed.',
            1
        );
    }
    if (count($ratings) > 0) {
        $pool = new Videos_PermanentPool($store, $cache);
        $pool->markDirty();
    }
}
