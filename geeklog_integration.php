<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

/**
 * Return the shared local-search service. No YouTube request is performed.
 */
function VIDEOS_getSearchService($bootstrap = null)
{
    global $_CONF, $_VIDEOS_CONF;

    if ($bootstrap === null) {
        $bootstrap = new Videos_Bootstrap($_CONF);
    }
    if (!is_object($bootstrap) || !$bootstrap->isReady()) {
        return false;
    }
    if (!class_exists('Videos_Search')) {
        require_once $_CONF['path']
            . 'plugins/videos/classes/Videos_Search.php';
    }
    return new Videos_Search(
        $bootstrap->getStore(),
        new Videos_Cache($bootstrap->getStore()),
        isset($_VIDEOS_CONF) && is_array($_VIDEOS_CONF)
            ? $_VIDEOS_CONF : array()
    );
}

/**
 * Add Videos to Geeklog's advanced-search type selector.
 */
function plugin_searchtypes_videos()
{
    if (isset($GLOBALS['_VIDEOS_CONF']['enabled']) &&
        empty($GLOBALS['_VIDEOS_CONF']['enabled'])) {
        return array();
    }
    return array('videos' => VIDEOS_getPublicTitle());
}

/**
 * Geeklog Search API 2 implementation using the local JSON/cache corpus.
 */
function plugin_dopluginsearch_videos(
    $query,
    $datestart,
    $dateend,
    $topic,
    $type,
    $author,
    $keyType,
    $page,
    $perpage
) {
    global $_CONF;

    if ($type !== 'all' && $type !== 'videos') {
        return false;
    }
    if (!class_exists('SearchCriteria')) {
        require_once $_CONF['path_system'] . 'classes/searchcriteria.class.php';
    }

    $criteria = new SearchCriteria('videos', VIDEOS_getPublicTitle());
    $service = VIDEOS_getSearchService();
    if ($service === false) {
        $criteria->setResults(array());
        $criteria->setTotal(0);
        return $criteria;
    }

    if (!empty($author)) {
        $criteria->setResults(array());
        $criteria->setTotal(0);
        return $criteria;
    }

    $titlesOnly = isset($_GET['title']);
    $rows = $service->geeklogResults(
        $query,
        $keyType,
        $datestart,
        $dateend,
        $titlesOnly,
        500
    );
    $criteria->setResults($rows);
    $criteria->setTotal(count($rows));
    $criteria->setRank(3);
    $criteria->setAppendQuery(false);
    $criteria->setURLRewrite(false);

    return $criteria;
}

/**
 * Summary row used by Geeklog's site statistics page.
 */
function plugin_statssummary_videos()
{
    $service = VIDEOS_getSearchService();
    $count = $service === false ? 0 : count($service->inventory(1000));
    return array(VIDEOS_getPublicTitle(), COM_numberFormat($count));
}

/**
 * Detailed Videos section on Geeklog's stats.php page.
 *
 * The optional argument is retained for the Geeklog 2.1.1 Plugin API, which
 * calls plugin_showstats_PLUGIN(2) for the detailed section.
 */
function plugin_showstats_videos($showSiteStats = 2)
{
    global $_CONF;

    $bootstrap = new Videos_Bootstrap($_CONF);
    if (!$bootstrap->isReady()) {
        return COM_startBlock(VIDEOS_getPublicTitle())
            . '<p>Les statistiques vidéo sont temporairement indisponibles.</p>'
            . COM_endBlock();
    }

    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    $ranking = new Videos_Ranking(
        $store,
        new Videos_RatingStats($store),
        new Videos_VideoStats($store),
        $cache
    );
    $items = $ranking->getGlobal(10);
    if (count($items) === 0) {
        return COM_startBlock(VIDEOS_getPublicTitle())
            . '<p>Aucune statistique vidéo publique n’est encore disponible.</p>'
            . COM_endBlock();
    }

    require_once $_CONF['path_system'] . 'lib-admin.php';
    $headers = array(
        array(
            'text' => 'Vidéo',
            'field' => 'video',
            'header_class' => 'stats-header-title'
        ),
        array(
            'text' => 'Vues qualifiées',
            'field' => 'views',
            'header_class' => 'stats-header-count',
            'field_class' => 'stats-list-count'
        )
    );
    $data = array();
    foreach ($items as $videoId => $item) {
        $video = $cache->getVideo($videoId, true);
        if (!is_array($video) || $cache->isVideoUnavailable($videoId)) {
            continue;
        }
        $title = !empty($item['title'])
            ? $item['title'] : $videoId;
        $url = plugin_idtourl_videos('', $videoId);
        $data[] = array(
            'video' => COM_createLink(
                htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                $url
            ),
            'views' => COM_numberFormat(
                isset($item['view_count']) ? (int) $item['view_count'] : 0
            )
        );
    }
    if (count($data) === 0) {
        return COM_startBlock(VIDEOS_getPublicTitle())
            . '<p>Aucune statistique vidéo publique n’est encore disponible.</p>'
            . COM_endBlock();
    }

    return ADMIN_simpleList(
        '',
        $headers,
        array(
            'has_menu' => false,
            'title' => 'Vidéos les plus regardées',
            'form_url' => $_CONF['site_url'] . '/stats.php'
        ),
        $data
    );
}

/**
 * Determine whether a local public channel page is intended to be exposed.
 * A priority channel, a channel owning at least one pinned video, or a channel
 * with at least two ranked videos has sufficient editorial value.
 */
function VIDEOS_channelPageEligible($channelId, $bootstrap = null)
{
    global $_CONF;

    if (!Videos_Validator::youtubeChannelId($channelId)) {
        return false;
    }
    if ($bootstrap === null) {
        $bootstrap = new Videos_Bootstrap($_CONF);
    }
    if (!is_object($bootstrap) || !$bootstrap->isReady()) {
        return false;
    }

    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    $moderation = new Videos_Moderation($store);
    if ($moderation->isChannelExcluded($channelId)) {
        return false;
    }

    $state = $moderation->getChannelState($channelId);
    if (isset($state['state']) && $state['state'] === 'priority') {
        return true;
    }

    $pool = new Videos_PermanentPool($store, $cache);
    $records = $pool->records();
    $items = isset($records['items']) && is_array($records['items'])
        ? $records['items'] : array();
    foreach ($items as $videoId => $item) {
        if (empty($item['pinned'])) {
            continue;
        }
        $video = $cache->getVideo($videoId, true);
        if (!is_array($video) || empty($video['snippet']['channelId'])) {
            continue;
        }
        if ((string) $video['snippet']['channelId'] === $channelId &&
            !$cache->isVideoUnavailable($videoId) &&
            !$moderation->isVideoBlocked($videoId)) {
            return true;
        }
    }

    $channels = (new Videos_ChannelRanking($store, $cache))->getGlobal(250);
    return isset($channels[$channelId]['video_count']) &&
        (int) $channels[$channelId]['video_count'] >= 2;
}
