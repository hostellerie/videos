<?php

require_once '../lib-common.php';

$publicTitle = VIDEOS_getPublicTitle();
$seo = new Videos_Seo(
    $_CONF['site_url'],
    isset($_CONF['site_name']) ? $_CONF['site_name'] : '',
    $_VIDEOS_CONF
);
$videoId = isset($_GET['v']) ? COM_applyFilter($_GET['v']) : '';
if (!Videos_Validator::youtubeVideoId($videoId)) {
    echo COM_createHTMLDocument(
        COM_showMessageText('Vidéo invalide.', '', true),
        array(
            'pagetitle' => $publicTitle,
            'headercode' => $seo->unavailableVideo($videoId)
        )
    );
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
$cache = new Videos_Cache($bootstrap->getStore());
$moderation = new Videos_Moderation($bootstrap->getStore());
$knownUnavailable = $cache->isVideoUnavailable($videoId);
$video = $cache->getVideo($videoId, true);
$videoChannelId = is_array($video) &&
    isset($video['snippet']['channelId'])
    ? (string) $video['snippet']['channelId'] : '';
if ($knownUnavailable ||
    $moderation->isVideoBlocked($videoId) ||
    $moderation->isChannelExcluded($videoChannelId) ||
    $video === false) {
    echo COM_createHTMLDocument(
        COM_showMessageText('Cette vidéo n’est plus disponible.', '', true),
        array(
            'pagetitle' => $publicTitle,
            'headercode' => $seo->unavailableVideo($videoId)
        )
    );
    exit;
}

$snippet = isset($video['snippet']) ? $video['snippet'] : array();
$title = isset($snippet['title']) ? $snippet['title'] : $videoId;
$channelTitle = isset($snippet['channelTitle'])
    ? $snippet['channelTitle'] : '';
$descriptionService = new Videos_Description();
$description = $descriptionService->excerpt(
    isset($snippet['description']) ? $snippet['description'] : '',
    isset($_VIDEOS_CONF['description_mode'])
        ? $_VIDEOS_CONF['description_mode'] : 'clean'
);
$localUrl = $_CONF['site_url'] . '/videos/watch.php?v=' . rawurlencode($videoId);
$embedHost = !empty($_VIDEOS_CONF['privacy_enhanced_embed'])
    ? 'https://www.youtube-nocookie.com'
    : 'https://www.youtube.com';
$autoplay = !empty($_VIDEOS_CONF['autoplay']) ? '1' : '0';
$playerMode = isset($_VIDEOS_CONF['youtube_player_mode']) &&
    $_VIDEOS_CONF['youtube_player_mode'] === 'minimal'
    ? 'minimal' : 'standard';
$playerControls = $playerMode === 'minimal' ? '0' : '1';
$playerFullscreen = $playerMode === 'minimal' ? '0' : '1';
$embedUrl = $embedHost . '/embed/' . rawurlencode($videoId)
    . '?autoplay=' . $autoplay
    . '&rel=0&enablejsapi=1&playsinline=1&iv_load_policy=3'
    . '&controls=' . $playerControls . '&fs=' . $playerFullscreen
    . '&origin='
    . rawurlencode($_CONF['site_url']);
$seoHeader = $seo->video(
    $videoId,
    $video,
    $description,
    $embedHost . '/embed/' . rawurlencode($videoId)
);
$duration = isset($video['videos_duration_seconds'])
    ? (int) $video['videos_duration_seconds'] : 0;
$csrfToken = SEC_createToken();
$ratingStatsService = new Videos_RatingStats($bootstrap->getStore());
$ratingStats = $ratingStatsService->get($videoId);
$faqService = new Videos_Faq($LANG_VIDEOS_FAQ, $_VIDEOS_CONF);
$faqItems = !empty($_VIDEOS_CONF['faq_video_enabled'])
    ? $faqService->video($video, $ratingStats) : array();
$seoHeader .= $faqService->structuredData($faqItems);
$contextKey = isset($_GET['c']) &&
    preg_match('/^[a-f0-9]{64}$/', $_GET['c'])
    ? $_GET['c'] : '';
$videoStats = new Videos_VideoStats($bootstrap->getStore());
$ranking = new Videos_Ranking(
    $bootstrap->getStore(),
    $ratingStatsService,
    $videoStats,
    $cache
);
$privacy = new Videos_Privacy(
    $bootstrap->getStore(),
    $bootstrap->getSecret()
);
$selector = new Videos_CatalogueSelector(
    $ratingStatsService,
    $privacy,
    $ranking
);
$recommendation = new Videos_Recommendation($cache, $selector, $ranking);
$uid = isset($_USER['uid']) ? (int) $_USER['uid'] : 1;
$visitor = new Videos_Visitor($privacy, $uid);
$ratingService = new Videos_RatingService(
    $bootstrap->getStore(),
    $privacy
);
$personalRating = $ratingService->getRating($visitor, $videoId);
if (!is_int($personalRating)) {
    $personalRating = 0;
}
$anonymousViewed = array();
if (!Videos_Validator::accountUid($uid) &&
    !empty($_VIDEOS_CONF['anonymous_tracking_enabled']) &&
    isset($_COOKIE[Videos_Visitor::COOKIE_NAME])) {
    $anonymousViewed = $privacy->anonymousViewedVideoIds(
        $_COOKIE[Videos_Visitor::COOKIE_NAME],
        2
    );
}
$nextVideos = $recommendation->nextVideos(
    $videoId,
    $contextKey,
    $_VIDEOS_CONF,
    !empty($_VIDEOS_CONF['account_history_enabled']) ? $uid : 1,
    $anonymousViewed
);

$html = '<article class="videos-watch">'
    . VIDEOS_renderNavigation('')
    . '<div class="videos-player">'
    . '<iframe id="videos-youtube-player" src="'
    . htmlspecialchars($embedUrl, ENT_QUOTES, 'UTF-8')
    . '" title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
    . '" allow="accelerometer; encrypted-media; picture-in-picture'
    . ($autoplay === '1' ? '; autoplay' : '') . '" '
    . ($playerFullscreen === '1' ? 'allowfullscreen ' : '')
    . 'loading="lazy"></iframe></div>'
    . '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
    . '<p class="videos-channel">'
    . htmlspecialchars($channelTitle, ENT_QUOTES, 'UTF-8') . '</p>'
    . '<p class="videos-local-rating"><strong>'
    . htmlspecialchars($LANG_VIDEOS['local_average'], ENT_QUOTES, 'UTF-8')
    . ' :</strong> <span id="videos-local-rating-average">' . number_format(
        (float) $ratingStats['rating_average'],
        2,
        ',',
        ' '
    ) . '</span>/5 (<span id="videos-local-rating-count">'
    . (int) $ratingStats['rating_count'] . '</span>)</p>';
if (SEC_hasRights('videos.moderate')) {
    $quickModerationUrl = $_CONF['site_admin_url']
        . '/plugins/videos/quick_moderation.php?video_id='
        . rawurlencode($videoId);
    $html .= '<aside class="videos-moderation-actions" aria-label="'
        . htmlspecialchars(
            $LANG_VIDEOS['moderation_quick_actions'],
            ENT_QUOTES,
            'UTF-8'
        ) . '"><strong>' . htmlspecialchars(
            $LANG_VIDEOS['moderation_quick_actions'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</strong><div><a href="' . htmlspecialchars(
            $quickModerationUrl . '&entity=video&entity_id='
                . rawurlencode($videoId),
            ENT_QUOTES,
            'UTF-8'
        ) . '">'
        . htmlspecialchars(
            $LANG_VIDEOS['moderation_block_video'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</a>';
    if (Videos_Validator::youtubeChannelId($videoChannelId)) {
        $html .= '<a href="' . htmlspecialchars(
            $quickModerationUrl . '&entity=channel&entity_id='
                . rawurlencode($videoChannelId),
            ENT_QUOTES,
            'UTF-8'
        ) . '">'
            . htmlspecialchars(
                $LANG_VIDEOS['moderation_block_channel'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</a>';
    }
    $html .= '</div></aside>';
}
if ($description !== '') {
    $html .= '<p class="videos-description">'
        . htmlspecialchars($description, ENT_QUOTES, 'UTF-8') . '</p>';
}
$html .= '<div id="videos-engagement" class="videos-engagement" '
    . 'data-video-id="' . htmlspecialchars($videoId, ENT_QUOTES, 'UTF-8') . '" '
    . 'data-duration="' . $duration . '" '
    . 'data-endpoint="' . htmlspecialchars(
        $_CONF['site_url'] . '/videos/ajax.php',
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-token-name="' . htmlspecialchars(
        CSRF_TOKEN,
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-token="' . htmlspecialchars(
        $csrfToken,
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-rating-saved="' . htmlspecialchars(
        $LANG_VIDEOS['rating_saved'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-rating-error="' . htmlspecialchars(
        $LANG_VIDEOS['rating_error'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-rating-locked="' . htmlspecialchars(
        $LANG_VIDEOS['rating_locked'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-rating-waiting="' . htmlspecialchars(
        $LANG_VIDEOS['rating_waiting'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-playback-error="' . htmlspecialchars(
        $LANG_VIDEOS['playback_error'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-rating-countdown="' . htmlspecialchars(
        $LANG_VIDEOS['rating_countdown'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-rating-delete-confirm="' . htmlspecialchars(
        $LANG_VIDEOS['rating_delete_confirm'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-rating-deleted="' . htmlspecialchars(
        $LANG_VIDEOS['rating_deleted'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-rating-delete-error="' . htmlspecialchars(
        $LANG_VIDEOS['rating_delete_error'],
        ENT_QUOTES,
        'UTF-8'
    ) . '" data-current-rating="' . $personalRating
    . '"><fieldset class="videos-rating"><legend>Votre note</legend>';
for ($ratingValue = 1; $ratingValue <= 5; $ratingValue++) {
    $selected = $ratingValue <= $personalRating;
    $html .= '<button type="button" data-rating="' . $ratingValue
        . '" aria-label="' . $ratingValue . ' sur 5" aria-pressed="'
        . ($selected ? 'true' : 'false') . '" class="'
        . ($selected ? 'is-selected' : '') . '" disabled>&#9733;</button>';
}
$html .= '</fieldset><button type="button" class="videos-rating-delete" '
    . 'data-delete-rating'
    . ($personalRating > 0 ? '' : ' hidden') . ' disabled>'
    . htmlspecialchars(
        $LANG_VIDEOS['rating_delete'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</button><p class="videos-rating-status" aria-live="polite">'
    . htmlspecialchars($LANG_VIDEOS['rating_locked'], ENT_QUOTES, 'UTF-8')
    . '</p></div>';

if (!empty($_VIDEOS_CONF['sharing_enabled'])) {
    $encodedUrl = rawurlencode($localUrl);
    $encodedTitle = rawurlencode($title);
    $html .= '<nav class="videos-share" aria-label="Partager" '
        . 'data-url="' . htmlspecialchars($localUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'data-title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '" '
        . 'data-copied="' . htmlspecialchars(
            $LANG_VIDEOS['link_copied'],
            ENT_QUOTES,
            'UTF-8'
        ) . '">'
        . '<a class="videos-share-link" rel="nofollow noopener" target="_blank" href="https://www.facebook.com/sharer/sharer.php?u='
        . $encodedUrl . '"><span class="videos-share-icon is-facebook" aria-hidden="true">f</span>Facebook</a> '
        . '<a class="videos-share-link" rel="nofollow noopener" target="_blank" href="https://twitter.com/intent/tweet?url='
        . $encodedUrl . '&text=' . $encodedTitle . '"><span class="videos-share-icon is-x" aria-hidden="true">X</span>X</a> '
        . '<a class="videos-share-link" rel="nofollow noopener" target="_blank" href="https://www.linkedin.com/sharing/share-offsite/?url='
        . $encodedUrl . '"><span class="videos-share-icon is-linkedin" aria-hidden="true">in</span>LinkedIn</a> '
        . '<a class="videos-share-link" rel="nofollow noopener" target="_blank" href="https://wa.me/?text='
        . $encodedTitle . '%20' . $encodedUrl . '"><span class="videos-share-icon is-whatsapp" aria-hidden="true">W</span>WhatsApp</a> '
        . '<a class="videos-share-link" rel="nofollow noopener" target="_blank" href="https://t.me/share/url?url='
        . $encodedUrl . '&text=' . $encodedTitle . '"><span class="videos-share-icon is-telegram" aria-hidden="true">T</span>Telegram</a> '
        . '<a class="videos-share-link" href="mailto:?subject='
        . $encodedTitle . '&body=' . $encodedUrl
        . '"><span class="videos-share-icon is-email" aria-hidden="true">@</span>E-mail</a>'
        . '<button type="button" class="videos-copy-link">'
        . '<span class="videos-share-icon is-copy" aria-hidden="true">&#128279;</span>'
        . htmlspecialchars($LANG_VIDEOS['copy_link'], ENT_QUOTES, 'UTF-8')
        . '</button><button type="button" class="videos-native-share">'
        . '<span class="videos-share-icon is-native" aria-hidden="true">&#8599;</span>'
        . htmlspecialchars($LANG_VIDEOS['native_share'], ENT_QUOTES, 'UTF-8')
        . '</button><span class="videos-share-status" aria-live="polite"></span>'
        . '</nav>';
}

if (count($nextVideos) > 0) {
    $html .= '<section id="videos-next-panel" class="videos-next-panel" '
        . 'aria-labelledby="videos-next-title"><h2 id="videos-next-title">'
        . htmlspecialchars($LANG_VIDEOS['next_video'], ENT_QUOTES, 'UTF-8')
        . '</h2><p>'
        . htmlspecialchars($LANG_VIDEOS['next_video_help'], ENT_QUOTES, 'UTF-8')
        . '</p><div class="videos-next-items">';
    $nextPosition = 0;
    foreach ($nextVideos as $nextItem) {
        $nextId = $nextItem['video_id'];
        $nextVideo = $nextItem['video'];
        $nextSnippet = isset($nextVideo['snippet'])
            ? $nextVideo['snippet'] : array();
        $nextTitle = isset($nextSnippet['title'])
            ? $nextSnippet['title'] : $nextId;
        $nextChannel = isset($nextSnippet['channelTitle'])
            ? $nextSnippet['channelTitle'] : '';
        $nextThumbnail = isset(
            $nextSnippet['thumbnails']['medium']['url']
        ) ? $nextSnippet['thumbnails']['medium']['url'] : '';
        $nextUrl = $_CONF['site_url'] . '/videos/watch.php?v='
            . rawurlencode($nextId);
        if ($contextKey !== '') {
            $nextUrl .= '&c=' . rawurlencode($contextKey);
        }
        $nextProof = hash_hmac(
            'sha256',
            'recommendation:' . $videoId . ':' . $nextId,
            $bootstrap->getSecret()
        );
        $html .= '<article class="videos-next-item'
            . ($nextPosition === 0 ? ' is-active' : '')
            . '" data-next-item="' . $nextPosition . '" data-next-video="'
            . htmlspecialchars($nextId, ENT_QUOTES, 'UTF-8')
            . '" data-next-proof="'
            . htmlspecialchars($nextProof, ENT_QUOTES, 'UTF-8') . '">';
        if (strpos($nextThumbnail, 'https://') === 0) {
            $html .= '<a href="'
                . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8')
                . '"><img loading="lazy" src="'
                . htmlspecialchars($nextThumbnail, ENT_QUOTES, 'UTF-8')
                . '" alt=""></a>';
        }
        $html .= '<div><h3><a href="'
            . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($nextTitle, ENT_QUOTES, 'UTF-8')
            . '</a></h3>';
        if ($nextChannel !== '') {
            $html .= '<p>'
                . htmlspecialchars($nextChannel, ENT_QUOTES, 'UTF-8')
                . '</p>';
        }
        $html .= '<a class="videos-watch-next" href="'
            . htmlspecialchars($nextUrl, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($LANG_VIDEOS['watch_next'], ENT_QUOTES, 'UTF-8')
            . '</a></div></article>';
        $nextPosition++;
    }
    $html .= '</div>';
    if (count($nextVideos) > 1) {
        $html .= '<button type="button" class="videos-another-suggestion" '
            . 'data-next-other>'
            . htmlspecialchars(
                $LANG_VIDEOS['another_suggestion'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</button>';
    }
    $html .= '</section>';
}
if (count($faqItems) > 0) {
    $html .= $faqService->render(
        $faqItems,
        $LANG_VIDEOS['video_about_title']
    );
}
$html .= '</article>';
$html .= '<script src="https://www.youtube.com/iframe_api"></script>'
    . '<script src="' . htmlspecialchars(
        $_CONF['site_url'] . '/videos/js/videos-player.js?v='
            . rawurlencode(VIDEOS_PLUGIN_VERSION),
        ENT_QUOTES,
        'UTF-8'
    ) . '"></script><script src="' . htmlspecialchars(
        $_CONF['site_url'] . '/videos/js/videos-share.js?v='
            . rawurlencode(VIDEOS_PLUGIN_VERSION),
        ENT_QUOTES,
        'UTF-8'
    ) . '"></script>';

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $title,
        'headercode' => $seoHeader
    )
);
