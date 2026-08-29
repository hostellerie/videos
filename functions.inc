<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

require_once $_CONF['path'] . 'plugins/videos/version.php';
require_once $_CONF['path'] . 'plugins/videos/autoinstall.php';
require_once $_CONF['path'] . 'plugins/videos/install_defaults.php';

define('VIDEOS_PLUGIN_NAME', 'videos');

$videosLanguage = $_CONF['path'] . 'plugins/videos/language/'
    . $_CONF['language'] . '.php';
if (!file_exists($videosLanguage)) {
    $videosLanguage = $_CONF['path']
        . 'plugins/videos/language/english.php';
}
require_once $videosLanguage;

require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Validator.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_JsonStore.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Logger.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Privacy.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Bootstrap.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_HttpClient.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_YouTubeClient.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Quota.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Cache.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Moderation.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_VideoPolicy.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_PermanentPool.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_CacheMaintenance.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_KeywordExtractor.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_YouTubeService.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_DiscoveryReservoir.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Visitor.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_PlaybackToken.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_RateLimiter.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Engagement.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_RatingService.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_RatingStats.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_CatalogueSelector.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_VideoStats.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_RecommendationStats.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_ChannelRanking.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Ranking.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Recommendation.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Description.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Seo.php';
require_once $_CONF['path'] . 'plugins/videos/classes/Videos_Faq.php';

$_VIDEOS_CONF = array();
if (class_exists('config')) {
    if (!isset($_VID_DEFAULT) || !is_array($_VID_DEFAULT)) {
        $_VID_DEFAULT = videos_default_configuration();
        $GLOBALS['_VID_DEFAULT'] = $_VID_DEFAULT;
    }
    $videosConfig = config::get_instance();
    $videosStoredConfiguration = $videosConfig->get_config('videos');
    if (!is_array($videosStoredConfiguration)) {
        $videosStoredConfiguration = array();
    }
    $_VIDEOS_CONF = is_array($_VID_DEFAULT)
        ? array_merge($_VID_DEFAULT, $videosStoredConfiguration)
        : $videosStoredConfiguration;
}

function plugin_getadminoption_videos()
{
    global $_CONF, $LANG_VIDEOS;

    if (!SEC_hasRights('videos.admin')) {
        return;
    }

    return array(
        $LANG_VIDEOS['admin_title'],
        $_CONF['site_admin_url'] . '/plugins/videos/index.php',
        0,
        'Videos Admin'
    );
}

function plugin_cclabel_videos()
{
    global $_CONF, $LANG_VIDEOS;

    if (!SEC_hasRights('videos.admin')) {
        return false;
    }

    return array(
        $LANG_VIDEOS['admin_title'],
        $_CONF['site_admin_url'] . '/plugins/videos/index.php',
        $_CONF['site_url'] . '/videos/images/videos.svg',
        'Videos Admin'
    );
}

function plugin_getmenuitems_videos()
{
    global $_CONF, $_VIDEOS_CONF;

    if (isset($_VIDEOS_CONF['enabled']) && !$_VIDEOS_CONF['enabled']) {
        return array();
    }

    return array(
        VIDEOS_getPublicTitle() => $_CONF['site_url']
            . '/videos/index.php'
    );
}

function plugin_getuseroption_videos()
{
    global $_CONF, $LANG_VIDEOS, $_VIDEOS_CONF;

    if (COM_isAnonUser() ||
        !SEC_hasRights('videos.personal_history') ||
        (isset($_VIDEOS_CONF['account_history_enabled']) &&
         !$_VIDEOS_CONF['account_history_enabled'])) {
        return;
    }

    return array(
        $LANG_VIDEOS['my_videos'],
        $_CONF['site_url'] . '/videos/history.php',
        ''
    );
}

function plugin_chkVersion_videos()
{
    return VIDEOS_PLUGIN_VERSION;
}

function plugin_upgrade_videos()
{
    global $_CONF, $_TABLES;

    $installedVersion = DB_getItem(
        $_TABLES['plugins'],
        'pi_version',
        "pi_name = 'videos'"
    );

    if ($installedVersion === VIDEOS_PLUGIN_VERSION) {
        return true;
    }
    $codeVersion = plugin_chkVersion_videos();

    require_once $_CONF['path'] . 'plugins/videos/autoinstall.php';
    if (!plugin_compatible_with_this_version_videos('videos')) {
        return 3002;
    }

    require_once $_CONF['path'] . 'plugins/videos/install_updates.php';
    if (!videos_apply_updates($installedVersion, $codeVersion)) {
        COM_errorLog(
            "Videos upgrade: no valid migration path from '"
            . $installedVersion . "' to '" . $codeVersion . "'.",
            1
        );
        return false;
    }

    DB_query(
        "UPDATE {$_TABLES['plugins']} SET pi_version = '"
        . DB_escapeString(VIDEOS_PLUGIN_VERSION)
        . "', pi_gl_version = '"
        . DB_escapeString(VIDEOS_MIN_GEEKLOG_VERSION)
        . "' WHERE pi_name = 'videos'"
    );
    if (DB_error()) {
        COM_errorLog(
            'Videos upgrade: cannot update the plugin version record.',
            1
        );
        return false;
    }

    return true;
}

function plugin_enablestatechange_videos($enable)
{
    return true;
}

function plugin_geticon_videos()
{
    global $_CONF;

    return $_CONF['site_url'] . '/videos/images/videos.svg';
}

function plugin_getBlocks_videos($side, $topic = '')
{
    global $LANG_VIDEOS, $_VIDEOS_CONF;

    $blocks = array();
    if (empty($_VIDEOS_CONF['enabled']) ||
        empty($_VIDEOS_CONF['block_enabled'])) {
        return $blocks;
    }

    $configuredSide = empty($_VIDEOS_CONF['block_isleft'])
        ? 'right'
        : 'left';
    if ($side !== $configuredSide) {
        return $blocks;
    }

    $content = '';
    if (function_exists('VIDEOS_renderBlock')) {
        $content = VIDEOS_renderBlock();
    }
    if ($content === '') {
        return $blocks;
    }

    $blocks[] = array(
        'name'           => 'videos_recommendations',
        'type'           => 'dynamic',
        'onleft'         => empty($_VIDEOS_CONF['block_isleft']) ? 0 : 1,
        'title'          => VIDEOS_getPublicTitle(),
        'blockorder'     => isset($_VIDEOS_CONF['block_order'])
            ? (int) $_VIDEOS_CONF['block_order']
            : 50,
        'content'        => $content,
        'allow_autotags' => false,
        'help'           => ''
    );

    return $blocks;
}

function plugin_getBlocksConfig_videos($side, $topic = '')
{
    global $LANG_VIDEOS, $_VIDEOS_CONF;

    return array(
        array(
            'plugin'         => $LANG_VIDEOS['plugin_name'],
            'name'           => 'videos_recommendations',
            'title'          => VIDEOS_getPublicTitle(),
            'type'           => 'dynamic',
            'onleft'         => empty($_VIDEOS_CONF['block_isleft']) ? 0 : 1,
            'blockorder'     => isset($_VIDEOS_CONF['block_order'])
                ? (int) $_VIDEOS_CONF['block_order']
                : 50,
            'allow_autotags' => false,
            'help'           => '',
            'enable'         => empty($_VIDEOS_CONF['block_enabled']) ? 0 : 1,
            'topic_option'   => 'all',
            'topic'          => array(),
            'inherit'        => array()
        )
    );
}

function VIDEOS_renderBlock()
{
    global $_CONF, $_VIDEOS_CONF, $LANG_VIDEOS;

    $bootstrap = new Videos_Bootstrap($_CONF);
    if (!$bootstrap->isReady()) {
        return '';
    }
    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    $moderation = new Videos_Moderation($store);
    $configuredMode = VIDEOS_getBlockMode();
    $mode = VIDEOS_resolveBlockMode($configuredMode);
    $allowedModes = array(
        'recommended',
        'top_rated',
        'most_watched',
        'recent_activity',
        'random',
        'random_mode',
        'best_channels'
    );
    if (!in_array($mode, $allowedModes, true)) {
        $mode = 'recommended';
    }
    $limit = isset($_VIDEOS_CONF['block_item_count'])
        ? max(1, min(10, (int) $_VIDEOS_CONF['block_item_count'])) : 3;
    $blockedVideos = VIDEOS_csvSet(
        isset($_VIDEOS_CONF['blocked_videos'])
            ? $_VIDEOS_CONF['blocked_videos'] : ''
    );
    $blockedChannels = VIDEOS_csvSet(
        isset($_VIDEOS_CONF['blocked_channels'])
            ? $_VIDEOS_CONF['blocked_channels'] : ''
    );

    if ($mode === 'best_channels') {
        $ranking = new Videos_ChannelRanking($store, $cache);
        $items = $ranking->getGlobal(100);
        $content = VIDEOS_renderChannelBlock(
            $items,
            $blockedChannels,
            $limit,
            $_CONF,
            $LANG_VIDEOS,
            $moderation
        );
        return VIDEOS_wrapBlockContent(
            $content,
            $mode,
            $LANG_VIDEOS
        );
    }

    $ranking = new Videos_Ranking(
        $store,
        new Videos_RatingStats($store),
        new Videos_VideoStats($store),
        $cache
    );
    $items = $ranking->getGlobal(500);
    if ($mode === 'top_rated') {
        uasort($items, 'VIDEOS_compareBlockRatings');
    } elseif ($mode === 'most_watched') {
        uasort($items, 'VIDEOS_compareBlockViews');
    } elseif ($mode === 'recent_activity') {
        uasort($items, 'VIDEOS_compareBlockActivity');
    } elseif ($mode === 'random') {
        $items = VIDEOS_shuffleBlockItems($items);
    }

    $selected = array();
    $channelCounts = array();
    $maximumPerChannel = isset($_VIDEOS_CONF['max_same_channel'])
        ? max(1, (int) $_VIDEOS_CONF['max_same_channel']) : 2;
    foreach ($items as $videoId => $item) {
        $channelId = isset($item['channel_id'])
            ? (string) $item['channel_id'] : '';
        if ($mode === 'top_rated' && empty($item['rating_count'])) {
            continue;
        }
        if ($mode === 'most_watched' && empty($item['view_count'])) {
            continue;
        }
        if ($mode === 'recent_activity' && empty($item['last_viewed_at'])) {
            continue;
        }
        if ($cache->isVideoUnavailable($videoId) ||
            $moderation->isVideoBlocked($videoId) ||
            $moderation->isChannelExcluded($channelId) ||
            isset($blockedVideos[$videoId]) ||
            isset($blockedChannels[$channelId]) ||
            Videos_VideoPolicy::excludesShortVideo(
                $cache->getVideo($videoId, true),
                $_VIDEOS_CONF
            )) {
            continue;
        }
        $channelCount = isset($channelCounts[$channelId])
            ? (int) $channelCounts[$channelId] : 0;
        if ($channelId !== '' && $channelCount >= $maximumPerChannel) {
            continue;
        }
        $selected[$videoId] = $item;
        if ($channelId !== '') {
            $channelCounts[$channelId] = $channelCount + 1;
        }
        if (count($selected) >= $limit) {
            break;
        }
    }
    $content = VIDEOS_renderVideoBlock($selected, $cache, $_CONF, $LANG_VIDEOS);
    return VIDEOS_wrapBlockContent($content, $mode, $LANG_VIDEOS);
}

function VIDEOS_getBlockMode()
{
    global $_VIDEOS_CONF;
    $mode = isset($_VIDEOS_CONF['block_mode'])
        ? (string) $_VIDEOS_CONF['block_mode'] : 'recommended';
    $allowedModes = array('recommended', 'top_rated', 'most_watched', 'recent_activity', 'random', 'random_mode', 'best_channels');
    return in_array($mode, $allowedModes, true) ? $mode : 'recommended';
}

function VIDEOS_resolveBlockMode($mode)
{
    if ($mode !== 'random_mode') {
        return $mode;
    }
    $modes = array('recommended', 'top_rated', 'most_watched', 'recent_activity', 'random', 'best_channels');
    return $modes[array_rand($modes)];
}

function VIDEOS_renderVideoBlock($items, $cache, $configuration, $language)
{
    if (count($items) === 0) {
        return '';
    }
    $html = '<ol class="videos-block-list">';
    foreach ($items as $videoId => $item) {
        $video = $cache->getVideo($videoId, true);
        $thumbnail = isset($video['snippet']['thumbnails']['medium']['url'])
            ? $video['snippet']['thumbnails']['medium']['url'] : '';
        $title = !empty($item['title']) ? $item['title'] : $videoId;
        $channel = isset($video['snippet']['channelTitle']) ? $video['snippet']['channelTitle'] : '';
        $shortTitle = VIDEOS_limitWords($title, 6);
        $url = $configuration['site_url'] . '/videos/watch.php?v=' . rawurlencode($videoId);
        $hasThumbnail = strpos($thumbnail, 'https://') === 0;
        $html .= '<li><a title="' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '"'
            . ($hasThumbnail ? '' : ' class="videos-block-no-image"') . ' href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
        if ($hasThumbnail) {
            $html .= '<img loading="lazy" src="' . htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8')
                . '" alt="' . htmlspecialchars(VIDEOS_thumbnailAlt($title, $channel), ENT_QUOTES, 'UTF-8') . '">';
        }
        $html .= '<span>' . htmlspecialchars($shortTitle, ENT_QUOTES, 'UTF-8') . '</span></a></li>';
    }
    return $html . '</ol><p class="videos-block-more"><a href="'
        . htmlspecialchars($configuration['site_url'] . '/videos/index.php', ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($language['view_video_catalogue'], ENT_QUOTES, 'UTF-8') . '</a></p>';
}

function VIDEOS_renderChannelBlock($items, $blockedChannels, $limit, $configuration, $language, $moderation)
{
    global $_VIDEOS_CONF;
    $html = '<ol class="videos-block-list videos-block-channels">';
    $count = 0;
    foreach ($items as $channelId => $item) {
        if ($moderation->isChannelExcluded($channelId) || isset($blockedChannels[$channelId])) {
            continue;
        }
        $title = !empty($item['title']) ? $item['title'] : $channelId;
        $html .= '<li><span>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</span></li>';
        $count++;
        if ($count >= $limit) {
            break;
        }
    }
    if ($count === 0) {
        return '';
    }
    $rankingsAvailable = !empty($_VIDEOS_CONF['public_rankings_enabled']) && !empty($_VIDEOS_CONF['public_channel_ranking_enabled']);
    $targetUrl = $rankingsAvailable
        ? $configuration['site_url'] . '/videos/rankings.php?tab=channels'
        : $configuration['site_url'] . '/videos/index.php';
    $targetLabel = $rankingsAvailable ? $language['view_channel_ranking'] : $language['view_video_catalogue'];
    return $html . '</ol><p class="videos-block-more"><a href="'
        . htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8') . '">'
        . htmlspecialchars($targetLabel, ENT_QUOTES, 'UTF-8') . '</a></p>';
}

function VIDEOS_compareBlockRatings($left, $right)
{
    $leftRating = isset($left['weighted_rating']) ? (float) $left['weighted_rating'] : 0;
    $rightRating = isset($right['weighted_rating']) ? (float) $right['weighted_rating'] : 0;
    if ($leftRating == $rightRating) {
        return VIDEOS_compareBlockViews($left, $right);
    }
    return $leftRating > $rightRating ? -1 : 1;
}

function VIDEOS_wrapBlockContent($content, $mode, $language)
{
    if ($content === '') {
        return '';
    }
    $keys = array(
        'recommended' => 'block_mode_recommended',
        'top_rated' => 'block_mode_top_rated',
        'most_watched' => 'block_mode_most_watched',
        'recent_activity' => 'block_mode_recent_activity',
        'random' => 'block_mode_random',
        'best_channels' => 'block_mode_best_channels'
    );
    $key = isset($keys[$mode]) ? $keys[$mode] : 'block_mode_recommended';
    $label = isset($language[$key]) ? $language[$key] : '';
    if ($label === '') {
        return $content;
    }
    return '<p class="videos-block-mode">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</p>' . $content;
}

function VIDEOS_compareBlockViews($left, $right)
{
    $leftViews = isset($left['view_count']) ? (int) $left['view_count'] : 0;
    $rightViews = isset($right['view_count']) ? (int) $right['view_count'] : 0;
    if ($leftViews === $rightViews) {
        return strcmp(isset($left['video_id']) ? $left['video_id'] : '', isset($right['video_id']) ? $right['video_id'] : '');
    }
    return $leftViews > $rightViews ? -1 : 1;
}

function VIDEOS_compareBlockActivity($left, $right)
{
    $leftTime = !empty($left['last_viewed_at']) ? strtotime($left['last_viewed_at']) : 0;
    $rightTime = !empty($right['last_viewed_at']) ? strtotime($right['last_viewed_at']) : 0;
    if ($leftTime == $rightTime) {
        return VIDEOS_compareBlockViews($left, $right);
    }
    return $leftTime > $rightTime ? -1 : 1;
}

function VIDEOS_shuffleBlockItems($items)
{
    if (!is_array($items) || count($items) < 2) {
        return is_array($items) ? $items : array();
    }
    $keys = array_keys($items);
    shuffle($keys);
    $shuffled = array();
    foreach ($keys as $key) {
        $shuffled[$key] = $items[$key];
    }
    return $shuffled;
}

function VIDEOS_limitWords($value, $maximum)
{
    $value = trim(strip_tags((string) $value));
    $maximum = max(1, (int) $maximum);
    if ($value === '') {
        return '';
    }
    $words = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($words)) {
        $words = preg_split('/\s+/', $value, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($words) || count($words) <= $maximum) {
        return $value;
    }
    return implode(' ', array_slice($words, 0, $maximum)) . '…';
}

function VIDEOS_csvSet($value)
{
    $result = array();
    foreach (preg_split('/[\s,;]+/', (string) $value) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $result[$item] = true;
        }
    }
    return $result;
}

function plugin_getdocumentationurl_videos($file)
{
    global $_CONF;
    $allowed = array('config' => 'ADMIN.html', 'install' => 'INSTALL.html', 'privacy' => 'PRIVACY.html');
    if (!isset($allowed[$file])) {
        $file = 'config';
    }
    return $_CONF['site_url'] . '/videos/docs/' . $allowed[$file];
}

function plugin_getconfigtooltip_videos($id)
{
    global $LANG_VIDEOS_TOOLTIPS;
    return isset($LANG_VIDEOS_TOOLTIPS[$id]) ? $LANG_VIDEOS_TOOLTIPS[$id] : '';
}

function VIDEOS_getPublicTitle()
{
    global $LANG_VIDEOS, $_VIDEOS_CONF;
    $title = isset($_VIDEOS_CONF['public_title']) ? trim(strip_tags((string) $_VIDEOS_CONF['public_title'])) : '';
    if ($title === '') {
        $title = $LANG_VIDEOS['public_title'];
    }
    if (function_exists('MBYTE_substr')) {
        return MBYTE_substr($title, 0, 120);
    }
    return substr($title, 0, 120);
}

function VIDEOS_renderNavigation($active)
{
    global $_CONF, $LANG_VIDEOS, $_VIDEOS_CONF;
    $items = array(
        'catalogue' => array('label' => $LANG_VIDEOS['catalogue'], 'url' => $_CONF['site_url'] . '/videos/index.php')
    );
    if (!empty($_VIDEOS_CONF['public_rankings_enabled'])) {
        $items['rankings'] = array('label' => $LANG_VIDEOS['rankings'], 'url' => $_CONF['site_url'] . '/videos/rankings.php');
    }
    if (!COM_isAnonUser() && SEC_hasRights('videos.personal_history') && !empty($_VIDEOS_CONF['account_history_enabled'])) {
        $items['history'] = array('label' => $LANG_VIDEOS['my_videos'], 'url' => $_CONF['site_url'] . '/videos/history.php');
    }
    $html = '<nav class="videos-navigation" aria-label="'
        . htmlspecialchars($LANG_VIDEOS['video_navigation'], ENT_QUOTES, 'UTF-8') . '"><ul>';
    foreach ($items as $key => $item) {
        $html .= '<li><a href="' . htmlspecialchars($item['url'], ENT_QUOTES, 'UTF-8') . '"';
        if ($active === $key) {
            $html .= ' class="is-active" aria-current="page"';
        }
        $html .= '>' . htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html . '</ul></nav>';
}

function plugin_getheadercode_videos()
{
    global $_CONF, $_VIDEOS_CONF;
    if (isset($_VIDEOS_CONF['enabled']) && !$_VIDEOS_CONF['enabled']) {
        return '';
    }
    return '<link rel="stylesheet" type="text/css" href="'
        . htmlspecialchars($_CONF['site_url'] . '/videos/css/videos.css', ENT_QUOTES, 'UTF-8')
        . '?v=' . rawurlencode(VIDEOS_PLUGIN_VERSION) . '">' . "\n";
}

function VIDEOS_adminHeaderCode()
{
    return '<meta name="robots" content="noindex,nofollow">' . "\n";
}

function plugin_user_delete_videos($uid)
{
    global $_CONF;
    $bootstrap = new Videos_Bootstrap($_CONF);
    if (!$bootstrap->isReady()) {
        return false;
    }
    $privacy = new Videos_Privacy($bootstrap->getStore(), $bootstrap->getSecret());
    return $privacy->deleteAccountData($uid);
}
