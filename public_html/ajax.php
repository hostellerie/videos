<?php

require_once '../lib-common.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$GLOBALS['VIDEOS_AJAX_STAGE'] = 'request';
$GLOBALS['VIDEOS_AJAX_RESPONDED'] = false;
ob_start();
register_shutdown_function('videos_ajax_shutdown');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    videos_ajax_response(false, 'method_not_allowed', array(), 405);
}

$action = isset($_POST['videos_action'])
    ? COM_applyFilter($_POST['videos_action']) : '';
$videoId = isset($_POST['video_id'])
    ? COM_applyFilter($_POST['video_id']) : '';
if (!Videos_Validator::youtubeVideoId($videoId)) {
    videos_ajax_response(false, 'invalid_video', array(), 400);
}
if ($action === 'start') {
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'csrf';
    if (!SEC_checkToken()) {
        videos_ajax_response(false, 'invalid_csrf', array(), 403);
    }
}

$GLOBALS['VIDEOS_AJAX_STAGE'] = 'bootstrap';
$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    videos_ajax_response(false, 'storage_unavailable', array(), 503);
}

$GLOBALS['VIDEOS_AJAX_STAGE'] = 'visitor';
$privacy = new Videos_Privacy(
    $bootstrap->getStore(),
    $bootstrap->getSecret()
);
$uid = isset($_USER['uid']) ? (int) $_USER['uid'] : 1;
$visitor = new Videos_Visitor($privacy, $uid);
if (!$visitor->isValid()) {
    videos_ajax_response(false, 'visitor_unavailable', array(), 503);
}

$limiter = new Videos_RateLimiter($bootstrap->getStore());
$tokenService = new Videos_PlaybackToken(
    $bootstrap->getSecret(),
    14400
);

if ($action === 'start') {
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'start';
    if (!$limiter->consume(
        $visitor->getSubjectHash(),
        'playback_start',
        60,
        3600
    )) {
        videos_ajax_response(false, 'rate_limited', array(), 429);
    }
    $playbackToken = $tokenService->create(
        $videoId,
        $visitor->getSubjectHash()
    );
    if ($playbackToken === false) {
        videos_ajax_response(false, 'token_failed', array(), 503);
    }
    videos_ajax_response(true, '', array(
        'playback_token' => $playbackToken,
        'account_mode' => $visitor->isAccount()
    ));
}

$GLOBALS['VIDEOS_AJAX_STAGE'] = 'token';
$playbackToken = isset($_POST['playback_token'])
    ? (string) $_POST['playback_token'] : '';
if ($tokenService->verify(
    $playbackToken,
    $videoId,
    $visitor->getSubjectHash()
) === false) {
    videos_ajax_response(false, 'invalid_playback_token', array(), 403);
}

$GLOBALS['VIDEOS_AJAX_STAGE'] = 'cache';
$cache = new Videos_Cache($bootstrap->getStore());
$video = $cache->getVideo($videoId, true);
if ($video === false) {
    videos_ajax_response(false, 'video_unavailable', array(), 404);
}
$duration = isset($video['videos_duration_seconds'])
    ? (int) $video['videos_duration_seconds'] : 0;
if ($duration <= 0) {
    videos_ajax_response(false, 'duration_unavailable', array(), 409);
}
$GLOBALS['VIDEOS_AJAX_STAGE'] = 'progress';
$elapsed = isset($_POST['elapsed'])
    ? filter_var($_POST['elapsed'], FILTER_VALIDATE_INT) : false;
if ($elapsed === false || $elapsed < 0 || $elapsed > $duration + 15) {
    videos_ajax_response(false, 'invalid_progress', array(), 400);
}
$elapsed = min($elapsed, $duration);
$channelId = isset($video['snippet']['channelId'])
    ? $video['snippet']['channelId'] : '';
$context = array(
    'channel_id' => $channelId,
    'context_hash' => isset($_POST['context_hash'])
        ? preg_replace('/[^a-f0-9]/', '', $_POST['context_hash']) : ''
);
$engagement = new Videos_Engagement($bootstrap->getStore(), $privacy);

if ($action === 'view') {
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'threshold';
    if (empty($_VIDEOS_CONF['view_tracking_enabled'])) {
        videos_ajax_response(false, 'tracking_disabled', array(), 403);
    }
    $threshold = $engagement->threshold(
        $duration,
        isset($_VIDEOS_CONF['view_threshold_seconds'])
            ? $_VIDEOS_CONF['view_threshold_seconds'] : 30,
        isset($_VIDEOS_CONF['view_threshold_percent'])
            ? $_VIDEOS_CONF['view_threshold_percent'] : 25
    );
    $ratingRequired = !empty($_VIDEOS_CONF['ratings_enabled'])
        ? videos_rating_required_seconds(
            $duration,
            isset($_VIDEOS_CONF['rating_threshold_seconds'])
                ? $_VIDEOS_CONF['rating_threshold_seconds'] : 30
        ) : 0;
    if ($elapsed < $threshold) {
        videos_ajax_response(true, '', array(
            'view_recorded' => false,
            'required_seconds' => $threshold,
            'remaining_seconds' => $threshold - $elapsed,
            'rating_required_seconds' => $ratingRequired,
            'rating_remaining_seconds' => $ratingRequired > 0
                ? max(0, $ratingRequired - $elapsed) : 0,
            'retry_after' => 5
        ));
    }
    if (!$limiter->consume(
        $visitor->getSubjectHash(),
        'qualified_view',
        120,
        86400
    )) {
        videos_ajax_response(false, 'rate_limited', array(), 429);
    }
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'view_write';
    if (!$engagement->recordView(
        $visitor,
        $videoId,
        $elapsed,
        $duration,
        $context
    )) {
        videos_ajax_response(false, 'view_write_failed', array(), 503);
    }
    $videoStats = new Videos_VideoStats($bootstrap->getStore());
    $aggregate = $videoStats->recordView(
        $videoId,
        $elapsed,
        $duration,
        $channelId
    );
    if ($aggregate === false) {
        $logger = new Videos_Logger($bootstrap->getStore());
        $logger->log(
            'warning',
            'video_stats_write_failed',
            'View was saved but its aggregate could not be updated.',
            array('video_id' => $videoId)
        );
    } else {
        $ranking = new Videos_Ranking(
            $bootstrap->getStore(),
            new Videos_RatingStats($bootstrap->getStore()),
            $videoStats,
            $cache
        );
        if ($ranking->updateVideo($videoId, $video) === false) {
            $logger = new Videos_Logger($bootstrap->getStore());
            $logger->log(
                'warning',
                'video_ranking_update_failed',
                'View was saved but the global ranking was not updated.',
                array('video_id' => $videoId)
            );
        }
    }
    videos_ajax_response(true, '', array(
        'view_recorded' => true,
        'rating_enabled' => !empty($_VIDEOS_CONF['ratings_enabled']) &&
            $elapsed >= $ratingRequired,
        'rating_required_seconds' => $ratingRequired,
        'rating_remaining_seconds' => $ratingRequired > 0
            ? max(0, $ratingRequired - $elapsed) : 0
    ));
}

if ($action === 'progress') {
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'progress_completion';
    if (empty($_VIDEOS_CONF['view_tracking_enabled'])) {
        videos_ajax_response(false, 'tracking_disabled', array(), 403);
    }
    $completed = isset($_POST['completed'])
        ? filter_var($_POST['completed'], FILTER_VALIDATE_INT) : false;
    if ($completed !== 1 || $elapsed < max(0, $duration - 2)) {
        videos_ajax_response(false, 'invalid_completion', array(), 400);
    }
    if (!$limiter->consume(
        $visitor->getSubjectHash(),
        'playback_completion',
        120,
        86400
    )) {
        videos_ajax_response(false, 'rate_limited', array(), 429);
    }
    $elapsed = $duration;
    $change = $engagement->updateProgress(
        $visitor,
        $videoId,
        $elapsed,
        $duration
    );
    if ($change === false) {
        videos_ajax_response(false, 'progress_write_failed', array(), 503);
    }
    if (!empty($change['updated'])) {
        $videoStats = new Videos_VideoStats($bootstrap->getStore());
        if ($videoStats->refineProgress($videoId, $change) === false) {
            $logger = new Videos_Logger($bootstrap->getStore());
            $logger->log(
                'warning',
                'video_progress_stats_failed',
                'Personal progress was saved but aggregate refinement failed.',
                array('video_id' => $videoId)
            );
        } else {
            $ranking = new Videos_Ranking(
                $bootstrap->getStore(),
                new Videos_RatingStats($bootstrap->getStore()),
                $videoStats,
                $cache
            );
            if ($ranking->updateVideo($videoId, $video) === false) {
                $logger = new Videos_Logger($bootstrap->getStore());
                $logger->log(
                    'warning',
                    'video_ranking_update_failed',
                    'Completion was saved but ranking update failed.',
                    array('video_id' => $videoId)
                );
            }
        }
    }
    videos_ajax_response(true, '', array(
        'progress_recorded' => true,
        'watched_ratio' => 1
    ));
}

if ($action === 'recommendation') {
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'recommendation_signal';
    $targetVideoId = isset($_POST['target_video_id'])
        ? COM_applyFilter($_POST['target_video_id']) : '';
    $signal = isset($_POST['signal'])
        ? COM_applyFilter($_POST['signal']) : '';
    $recommendationProof = isset($_POST['recommendation_proof'])
        ? COM_applyFilter($_POST['recommendation_proof']) : '';
    $expectedProof = hash_hmac(
        'sha256',
        'recommendation:' . $videoId . ':' . $targetVideoId,
        $bootstrap->getSecret()
    );
    if (!Videos_Validator::youtubeVideoId($targetVideoId) ||
        $targetVideoId === $videoId ||
        !in_array($signal, array('accepted', 'skipped'), true) ||
        !preg_match('/^[a-f0-9]{64}$/', $recommendationProof) ||
        !hash_equals($expectedProof, $recommendationProof)) {
        videos_ajax_response(false, 'invalid_recommendation_signal', array(), 400);
    }
    if (!$limiter->consume(
        $visitor->getSubjectHash(),
        'recommendation_signal',
        60,
        3600
    )) {
        videos_ajax_response(false, 'rate_limited', array(), 429);
    }
    $recommendationStats = new Videos_RecommendationStats(
        $bootstrap->getStore()
    );
    if ($recommendationStats->record(
        $targetVideoId,
        $signal,
        $videoId
    ) === false) {
        videos_ajax_response(false, 'recommendation_write_failed', array(), 503);
    }
    $targetVideo = $cache->getVideo($targetVideoId, true);
    if (is_array($targetVideo)) {
        $ranking = new Videos_Ranking(
            $bootstrap->getStore(),
            new Videos_RatingStats($bootstrap->getStore()),
            new Videos_VideoStats($bootstrap->getStore()),
            $cache,
            $recommendationStats
        );
        if ($ranking->updateVideo($targetVideoId, $targetVideo) === false) {
            $logger = new Videos_Logger($bootstrap->getStore());
            $logger->log(
                'warning',
                'recommendation_ranking_update_failed',
                'Recommendation signal was saved but ranking update failed.',
                array('video_id' => $targetVideoId)
            );
        }
    }
    videos_ajax_response(true, '', array(
        'signal_recorded' => true
    ));
}

if ($action === 'delete_rating') {
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'rating_delete';
    if (!$limiter->consume(
        $visitor->getSubjectHash(),
        'rating_delete',
        20,
        3600
    )) {
        videos_ajax_response(false, 'rate_limited', array(), 429);
    }
    $ratings = new Videos_RatingService(
        $bootstrap->getStore(),
        $privacy
    );
    $removed = $ratings->remove($visitor, $videoId);
    if ($removed === false) {
        $logger = new Videos_Logger($bootstrap->getStore());
        $logger->log(
            'error',
            'rating_delete_failed',
            'A local rating could not be removed or verified.',
            array('video_id' => $videoId)
        );
        videos_ajax_response(false, 'rating_delete_failed', array(), 503);
    }
    $ratingStats = new Videos_RatingStats($bootstrap->getStore());
    $summary = $ratingStats->rebuild($videoId);
    if ($summary === false) {
        videos_ajax_response(false, 'rating_stats_rebuild_failed', array(), 503);
    }
    $ranking = new Videos_Ranking(
        $bootstrap->getStore(),
        $ratingStats,
        new Videos_VideoStats($bootstrap->getStore()),
        $cache
    );
    if ($ranking->updateVideo($videoId, $video) === false) {
        $logger = new Videos_Logger($bootstrap->getStore());
        $logger->log(
            'warning',
            'video_ranking_update_failed',
            'Rating was removed but the global ranking was not updated.',
            array('video_id' => $videoId)
        );
    }
    if (!empty($_VIDEOS_CONF['permanent_pool_enabled'])) {
        $pool = new Videos_PermanentPool(
            $bootstrap->getStore(),
            $cache
        );
        $pool->markDirty();
    }
    videos_ajax_response(true, '', array(
        'rating_removed' => !empty($removed['removed']),
        'rating_average' => $summary['rating_average'],
        'rating_count' => $summary['rating_count']
    ));
}

if ($action === 'rate') {
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'rating_validation';
    if (empty($_VIDEOS_CONF['ratings_enabled'])) {
        videos_ajax_response(false, 'ratings_disabled', array(), 403);
    }
    $ratingThreshold = videos_rating_required_seconds(
        $duration,
        isset($_VIDEOS_CONF['rating_threshold_seconds'])
            ? $_VIDEOS_CONF['rating_threshold_seconds'] : 30
    );
    if ($elapsed < $ratingThreshold) {
        videos_ajax_response(false, 'rating_threshold_not_reached', array(
            'required_seconds' => $ratingThreshold
        ), 409);
    }
    $rating = isset($_POST['rating'])
        ? filter_var($_POST['rating'], FILTER_VALIDATE_INT) : false;
    if ($rating === false || $rating < 1 || $rating > 5) {
        videos_ajax_response(false, 'invalid_rating', array(), 400);
    }
    if (!$limiter->consume(
        $visitor->getSubjectHash(),
        'rating',
        30,
        3600
    )) {
        videos_ajax_response(false, 'rate_limited', array(), 429);
    }
    $ratings = new Videos_RatingService(
        $bootstrap->getStore(),
        $privacy
    );
    $GLOBALS['VIDEOS_AJAX_STAGE'] = 'rating_write';
    if (!$ratings->rate($visitor, $videoId, (int) $rating, $context)) {
        $logger = new Videos_Logger($bootstrap->getStore());
        $logger->log(
            'error',
            'account_rating_write_failed',
            'A local rating could not be written or verified.',
            array('video_id' => $videoId)
        );
        videos_ajax_response(false, 'rating_write_failed', array(), 503);
    }
    $ratingStats = new Videos_RatingStats($bootstrap->getStore());
    $summary = $ratingStats->rebuild($videoId);
    if ($summary === false) {
        $logger = new Videos_Logger($bootstrap->getStore());
        $logger->log(
            'warning',
            'rating_stats_rebuild_failed',
            'Rating was saved but its aggregate could not be rebuilt.',
            array('video_id' => $videoId)
        );
        $summary = array('rating_average' => 0, 'rating_count' => 0);
    }
    $ranking = new Videos_Ranking(
        $bootstrap->getStore(),
        $ratingStats,
        new Videos_VideoStats($bootstrap->getStore()),
        $cache
    );
    if ($ranking->updateVideo($videoId, $video) === false) {
        $logger = new Videos_Logger($bootstrap->getStore());
        $logger->log(
            'warning',
            'video_ranking_update_failed',
            'Rating was saved but the global ranking was not updated.',
            array('video_id' => $videoId)
        );
    }
    if (!empty($_VIDEOS_CONF['permanent_pool_enabled'])) {
        $pool = new Videos_PermanentPool(
            $bootstrap->getStore(),
            $cache
        );
        $pool->markDirty();
    }
    videos_ajax_response(true, '', array(
        'rating' => (int) $rating,
        'rating_average' => $summary['rating_average'],
        'rating_count' => $summary['rating_count']
    ));
}

videos_ajax_response(false, 'invalid_action', array(), 400);

function videos_rating_required_seconds($duration, $configuredSeconds)
{
    $duration = max(1, (int) $duration);
    $configuredSeconds = max(1, (int) $configuredSeconds);
    $shortVideoThreshold = max(1, (int) ceil($duration * 0.9));
    if ($shortVideoThreshold >= $duration && $duration > 1) {
        $shortVideoThreshold = $duration - 1;
    }
    return min($configuredSeconds, $shortVideoThreshold);
}

function videos_ajax_response($success, $error, $data, $status = null)
{
    if ($status === null) {
        $status = $success ? 200 : 400;
    }
    http_response_code($status);
    $json = json_encode(array(
        'success' => (bool) $success,
        'error' => (string) $error,
        'data' => is_array($data) ? $data : array()
    ));
    if ($json === false) {
        $json = '{"success":false,"error":"json_encode_failed","data":{}}';
    }
    $GLOBALS['VIDEOS_AJAX_RESPONDED'] = true;
    echo $json;
    exit;
}

function videos_ajax_shutdown()
{
    $content = '';
    if (ob_get_level() > 0) {
        $content = ob_get_clean();
    }
    if (!empty($GLOBALS['VIDEOS_AJAX_RESPONDED'])) {
        echo $content;
        return;
    }

    $lastError = error_get_last();
    $errorType = is_array($lastError) && isset($lastError['type'])
        ? (int) $lastError['type'] : 0;
    $stage = isset($GLOBALS['VIDEOS_AJAX_STAGE'])
        ? preg_replace(
            '/[^a-z_]/',
            '',
            $GLOBALS['VIDEOS_AJAX_STAGE']
        )
        : 'unknown';

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        http_response_code(200);
    }
    echo json_encode(array(
        'success' => false,
        'error' => 'unexpected_shutdown',
        'data' => array(
            'stage' => $stage,
            'php_error_type' => $errorType
        )
    ));
}
