<?php

require_once '../lib-common.php';

$publicTitle = VIDEOS_getPublicTitle();
$seo = new Videos_Seo(
    $_CONF['site_url'],
    isset($_CONF['site_name']) ? $_CONF['site_name'] : '',
    $_VIDEOS_CONF
);
if (empty($_VIDEOS_CONF['enabled']) ||
    empty($_VIDEOS_CONF['public_rankings_enabled'])) {
    echo COM_createHTMLDocument(
        COM_showMessageText(
            $LANG_VIDEOS['public_rankings_disabled'],
            '',
            true
        ),
        array(
            'pagetitle' => $LANG_VIDEOS['rankings'],
            'headercode' => $seo->privatePage(
                $_CONF['site_url'] . '/videos/rankings.php'
            )
        )
    );
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
$videoItems = array();
$channelItems = array();
$cache = null;
$limit = isset($_VIDEOS_CONF['public_ranking_limit'])
    ? max(1, min(50, (int) $_VIDEOS_CONF['public_ranking_limit'])) : 10;
$videosEnabled = !empty(
    $_VIDEOS_CONF['public_video_ranking_enabled']
);
$channelsEnabled = !empty(
    $_VIDEOS_CONF['public_channel_ranking_enabled']
);
$requestedTab = isset($_GET['tab']) ? (string) $_GET['tab'] : '';
$activeTab = $requestedTab === 'channels' ? 'channels' : 'videos';
if ($activeTab === 'videos' && !$videosEnabled && $channelsEnabled) {
    $activeTab = 'channels';
} elseif ($activeTab === 'channels' && !$channelsEnabled && $videosEnabled) {
    $activeTab = 'videos';
}

if ($bootstrap->isReady()) {
    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    $moderation = new Videos_Moderation($store);
    $ranking = new Videos_Ranking(
        $store,
        new Videos_RatingStats($store),
        new Videos_VideoStats($store),
        $cache
    );
    $channelRanking = new Videos_ChannelRanking($store, $cache);
    $blockedVideos = videos_rankings_csv_set(
        isset($_VIDEOS_CONF['blocked_videos'])
            ? $_VIDEOS_CONF['blocked_videos'] : ''
    );
    $blockedChannels = videos_rankings_csv_set(
        isset($_VIDEOS_CONF['blocked_channels'])
            ? $_VIDEOS_CONF['blocked_channels'] : ''
    );

    if ($activeTab === 'videos' && $videosEnabled) {
        $candidates = $ranking->getGlobal(500);
        foreach ($candidates as $videoId => $item) {
            $channelId = isset($item['channel_id'])
                ? (string) $item['channel_id'] : '';
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
            $videoItems[$videoId] = $item;
            if (count($videoItems) >= $limit) {
                break;
            }
        }
    }
    if ($activeTab === 'channels' && $channelsEnabled) {
        $candidates = $channelRanking->getGlobal(250);
        foreach ($candidates as $channelId => $item) {
            if ($moderation->isChannelExcluded($channelId) ||
                isset($blockedChannels[$channelId])) {
                continue;
            }
            $channelItems[$channelId] = $item;
            if (count($channelItems) >= $limit) {
                break;
            }
        }
    }
}

$faqService = new Videos_Faq($LANG_VIDEOS_FAQ, $_VIDEOS_CONF);
$rankingHasContent =
    ($activeTab === 'videos' && count($videoItems) > 0) ||
    ($activeTab === 'channels' && count($channelItems) > 0);
$faqItems = !empty($_VIDEOS_CONF['faq_rankings_enabled']) &&
    $rankingHasContent
    ? $faqService->rankings($activeTab) : array();
$html = '<div class="videos-page videos-rankings-page">'
    . VIDEOS_renderNavigation('rankings')
    . '<h1>' . htmlspecialchars(
        $LANG_VIDEOS['public_rankings_title'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</h1><p class="videos-rankings-intro">'
    . htmlspecialchars(
        $LANG_VIDEOS['public_rankings_intro'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';

if (!$bootstrap->isReady()) {
    $html .= '<p>' . htmlspecialchars(
        $LANG_VIDEOS['public_rankings_unavailable'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</p>';
} else {
    if ($videosEnabled || $channelsEnabled) {
        $html .= '<nav class="videos-ranking-tabs" aria-label="'
            . htmlspecialchars(
                $LANG_VIDEOS['ranking_tabs_navigation'],
                ENT_QUOTES,
                'UTF-8'
            ) . '"><ul>';
        if ($videosEnabled) {
            $html .= videos_rankings_tab(
                'videos',
                $activeTab,
                $LANG_VIDEOS['top_videos'],
                $_CONF['site_url']
            );
        }
        if ($channelsEnabled) {
            $html .= videos_rankings_tab(
                'channels',
                $activeTab,
                $LANG_VIDEOS['top_channels'],
                $_CONF['site_url']
            );
        }
        $html .= '</ul></nav>';
    }
    if ($activeTab === 'videos' && $videosEnabled) {
        $html .= videos_rankings_render_videos(
            $videoItems,
            $cache,
            $_CONF,
            $LANG_VIDEOS
        );
    }
    if ($activeTab === 'channels' && $channelsEnabled) {
        $html .= videos_rankings_render_channels(
            $channelItems,
            $LANG_VIDEOS
        );
    }
    if (!$videosEnabled && !$channelsEnabled) {
        $html .= '<p>' . htmlspecialchars(
            $LANG_VIDEOS['public_rankings_empty'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</p>';
    }
}
if (count($faqItems) > 0) {
    $html .= $faqService->render(
        $faqItems,
        $LANG_VIDEOS['faq_title']
    );
}
$html .= '</div>';

$rankingsHeader = $seo->rankings(
    $LANG_VIDEOS['public_rankings_title'],
    $LANG_VIDEOS['public_rankings_intro'],
    $activeTab,
    $rankingHasContent
);
if (!empty($_VIDEOS_CONF['seo_rankings_index'])) {
    $rankingsHeader .= $faqService->structuredData($faqItems);
}
echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $LANG_VIDEOS['public_rankings_title']
            . ' - ' . $publicTitle,
        'headercode' => $rankingsHeader
    )
);

function videos_rankings_tab($tab, $activeTab, $label, $siteUrl)
{
    $url = $siteUrl . '/videos/rankings.php?tab='
        . rawurlencode($tab);
    $html = '<li><a href="'
        . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
    if ($tab === $activeTab) {
        $html .= ' class="is-active" aria-current="page"';
    }
    return $html . '>'
        . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        . '</a></li>';
}

function videos_rankings_render_videos($items, $cache, $configuration, $language)
{
    $html = '<section class="videos-ranking-section"><h2>'
        . htmlspecialchars(
            $language['top_videos'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</h2>';
    if (count($items) === 0) {
        return $html . '<p>' . htmlspecialchars(
            $language['ranking_no_data'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</p></section>';
    }
    $html .= '<ol class="videos-ranking-list">';
    foreach ($items as $videoId => $item) {
        $video = $cache->getVideo($videoId, true);
        $thumbnail = isset($video['snippet']['thumbnails']['medium']['url'])
            ? $video['snippet']['thumbnails']['medium']['url'] : '';
        $title = !empty($item['title']) ? $item['title'] : $videoId;
        $channelTitle = !empty($item['channel_title'])
            ? $item['channel_title'] : '';
        $url = $configuration['site_url'] . '/videos/watch.php?v='
            . rawurlencode($videoId);
        $html .= '<li class="videos-ranking-item"><a class="'
            . 'videos-ranking-thumbnail" href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
        if (strpos($thumbnail, 'https://') === 0) {
            $html .= '<img loading="lazy" src="'
                . htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8')
                . '" alt="">';
        }
        $html .= '</a><div class="videos-ranking-content"><h3><a href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</a></h3>';
        if ($channelTitle !== '') {
            $html .= '<p class="videos-ranking-channel">'
                . htmlspecialchars($channelTitle, ENT_QUOTES, 'UTF-8')
                . '</p>';
        }
        $html .= videos_rankings_metrics($item, $language)
            . '</div></li>';
    }
    return $html . '</ol></section>';
}

function videos_rankings_render_channels($items, $language)
{
    $html = '<section class="videos-ranking-section"><h2>'
        . htmlspecialchars(
            $language['top_channels'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</h2>';
    if (count($items) === 0) {
        return $html . '<p>' . htmlspecialchars(
            $language['channel_ranking_no_data'],
            ENT_QUOTES,
            'UTF-8'
        ) . '</p></section>';
    }
    $html .= '<ol class="videos-ranking-list videos-channel-ranking-list">';
    foreach ($items as $channelId => $item) {
        $title = !empty($item['title']) ? $item['title'] : $channelId;
        $html .= '<li class="videos-ranking-item">'
            . '<div class="videos-ranking-content"><h3>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</h3>' . videos_rankings_metrics($item, $language)
            . '<p class="videos-ranking-meta">'
            . htmlspecialchars(
                $language['channel_videos'],
                ENT_QUOTES,
                'UTF-8'
            ) . ' : ' . (isset($item['video_count'])
                ? (int) $item['video_count'] : 0)
            . '</p></div></li>';
    }
    return $html . '</ol></section>';
}

function videos_rankings_metrics($item, $language)
{
    $average = isset($item['rating_average'])
        ? (float) $item['rating_average'] : 0;
    $ratings = isset($item['rating_count'])
        ? (int) $item['rating_count'] : 0;
    $views = isset($item['view_count'])
        ? (int) $item['view_count'] : 0;
    return '<p class="videos-ranking-meta"><span>'
        . htmlspecialchars($language['local_average'], ENT_QUOTES, 'UTF-8')
        . ' : ' . number_format($average, 2, ',', ' ')
        . '/5 (' . $ratings . ')</span><span>'
        . htmlspecialchars($language['ranking_views'], ENT_QUOTES, 'UTF-8')
        . ' : ' . $views . '</span></p>';
}

function videos_rankings_csv_set($value)
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
