<?php

require_once '../lib-common.php';

$seo = new Videos_Seo(
    $_CONF['site_url'],
    isset($_CONF['site_name']) ? $_CONF['site_name'] : '',
    $_VIDEOS_CONF
);

if (empty($_VIDEOS_CONF['enabled'])) {
    $publicTitle = VIDEOS_getPublicTitle();
    echo COM_createHTMLDocument(
        COM_showMessageText('Le catalogue vidéo est désactivé.', '', true),
        array(
            'pagetitle' => $publicTitle,
            'headercode' => $seo->catalogue($publicTitle, 1, false)
        )
    );
    exit;
}

$publicTitle = VIDEOS_getPublicTitle();
$bootstrap = new Videos_Bootstrap($_CONF);
$videos = array();
$videoMetadata = array();
$catalogueContextKey = '';
$message = '';
$searchQuery = isset($_GET['q'])
    ? trim(strip_tags((string) $_GET['q'])) : '';
if (function_exists('MBYTE_substr')) {
    $searchQuery = MBYTE_substr($searchQuery, 0, 120);
} else {
    $searchQuery = substr($searchQuery, 0, 120);
}
$isLocalSearch = $searchQuery !== '';
$searchTotal = 0;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$perPage = isset($_VIDEOS_CONF['videos_per_page'])
    ? max(1, min(50, (int) $_VIDEOS_CONF['videos_per_page'])) : 12;
$pageCount = 1;

if (!$bootstrap->isReady()) {
    $message = 'Le catalogue vidéo est temporairement indisponible.';
} elseif ($isLocalSearch) {
    // Reuse exactly the same bounded local corpus as Geeklog Search API 2.
    // No YouTube API call is performed by a visitor search.
    $searchService = VIDEOS_getSearchService($bootstrap);
    if ($searchService === false) {
        $message = 'La recherche vidéo est temporairement indisponible.';
    } else {
        $matches = $searchService->search(
            $searchQuery,
            'any',
            '',
            '',
            false,
            500
        );
        $allVideos = array();
        foreach ($matches as $videoId => $match) {
            if (!empty($match['video']) && is_array($match['video'])) {
                $allVideos[$videoId] = $match['video'];
            }
            if (!empty($match['ranking']) && is_array($match['ranking'])) {
                $videoMetadata[$videoId] = array(
                    'rating_count' => isset($match['ranking']['rating_count'])
                        ? $match['ranking']['rating_count'] : 0,
                    'rating_average' => isset($match['ranking']['rating_average'])
                        ? $match['ranking']['rating_average'] : 0
                );
            }
        }
        $searchTotal = count($allVideos);
        $pageCount = max(1, (int) ceil($searchTotal / $perPage));
        $page = min($page, $pageCount);
        $videos = array_slice(
            $allVideos,
            ($page - 1) * $perPage,
            $perPage,
            true
        );
        if ($searchTotal === 0) {
            $message = 'Aucune vidéo du catalogue ne correspond à cette recherche.';
        }
    }
} else {
    $extractor = new Videos_KeywordExtractor(
        isset($_VIDEOS_CONF['additional_stop_words'])
            ? $_VIDEOS_CONF['additional_stop_words'] : ''
    );
    $context = array(
        'site_title' => isset($_CONF['site_name']) ? $_CONF['site_name'] : '',
        'site_description' => isset($_CONF['site_slogan'])
            ? $_CONF['site_slogan'] : '',
        'page_title' => '',
        'page_description' => '',
        'meta_keywords' => '',
        'content' => ''
    );
    $analysis = array(
        'mode' => isset($_VIDEOS_CONF['analysis_mode'])
            ? $_VIDEOS_CONF['analysis_mode'] : 'mixed',
        'maximum' => isset($_VIDEOS_CONF['max_keywords'])
            ? $_VIDEOS_CONF['max_keywords'] : 8,
        'title_weight' => isset($_VIDEOS_CONF['title_weight'])
            ? $_VIDEOS_CONF['title_weight'] : 5,
        'meta_weight' => isset($_VIDEOS_CONF['meta_weight'])
            ? $_VIDEOS_CONF['meta_weight'] : 4,
        'content_weight' => isset($_VIDEOS_CONF['content_weight'])
            ? $_VIDEOS_CONF['content_weight'] : 1,
        'manual_keywords' => isset($_VIDEOS_CONF['manual_keywords'])
            ? $_VIDEOS_CONF['manual_keywords'] : '',
        'required_keywords' => isset($_VIDEOS_CONF['required_keywords'])
            ? $_VIDEOS_CONF['required_keywords'] : '',
        'excluded_keywords' => isset($_VIDEOS_CONF['excluded_keywords'])
            ? $_VIDEOS_CONF['excluded_keywords'] : ''
    );
    $terms = $extractor->extract($context, $analysis);
    $query = $extractor->buildQuery($terms, $analysis['excluded_keywords']);
    if ($query === '') {
        $message = 'La thématique vidéo doit être configurée par un administrateur.';
    } else {
        $result = videos_public_search($bootstrap, $query, $_VIDEOS_CONF);
        if ($result === false) {
            $message = 'Aucune vidéo n’est actuellement disponible.';
        } else {
            $catalogueContextKey = isset($result['cache_key']) &&
                preg_match('/^[a-f0-9]{64}$/', $result['cache_key'])
                ? $result['cache_key'] : '';
            $privacy = new Videos_Privacy(
                $bootstrap->getStore(),
                $bootstrap->getSecret()
            );
            $ratingStats = new Videos_RatingStats($bootstrap->getStore());
            $videoStats = new Videos_VideoStats($bootstrap->getStore());
            $cache = new Videos_Cache($bootstrap->getStore());
            if (!empty($_VIDEOS_CONF['discovery_enabled'])) {
                $reservoir = new Videos_DiscoveryReservoir(
                    $bootstrap->getStore(),
                    $cache
                );
                $reservoir->ingest($result['videos'], $query);
                if ($reservoir->isDue($_VIDEOS_CONF)) {
                    $reservoir->refresh(
                        $query,
                        videos_build_public_search_parameters($_VIDEOS_CONF),
                        $_VIDEOS_CONF,
                        videos_create_public_youtube_service(
                            $bootstrap,
                            $_VIDEOS_CONF
                        ),
                        false
                    );
                }
                $reservoirVideos = $reservoir->videos($_VIDEOS_CONF);
                if (count($reservoirVideos) > 0) {
                    $result['videos'] = $reservoirVideos;
                    $result['video_ids'] = array_keys($reservoirVideos);
                }
            }
            $ranking = new Videos_Ranking(
                $bootstrap->getStore(),
                $ratingStats,
                $videoStats,
                $cache
            );
            $selector = new Videos_CatalogueSelector(
                $ratingStats,
                $privacy,
                $ranking
            );
            $selectionConfiguration = $_VIDEOS_CONF;
            $moderation = new Videos_Moderation($bootstrap->getStore());
            $priorityIds = $moderation->getPriorityChannelIds(500);
            if (count($priorityIds) > 0) {
                $existingPriority = isset(
                    $selectionConfiguration['priority_channels']
                ) ? $selectionConfiguration['priority_channels'] : '';
                $selectionConfiguration['priority_channels'] = trim(
                    $existingPriority . ',' . implode(',', $priorityIds),
                    ','
                );
            }
            $selection = $selector->select(
                $result['videos'],
                $query,
                $selectionConfiguration,
                !empty($_VIDEOS_CONF['account_history_enabled']) &&
                    isset($_USER['uid']) ? (int) $_USER['uid'] : 1
            );
            if (!empty($_VIDEOS_CONF['permanent_pool_enabled'])) {
                $pool = new Videos_PermanentPool(
                    $bootstrap->getStore(),
                    $cache
                );
                $poolVideos = $pool->videos(
                    $ranking->getGlobal(500),
                    $_VIDEOS_CONF
                );
                $poolSelection = $selector->select(
                    $poolVideos,
                    'permanent-pool',
                    $selectionConfiguration,
                    !empty($_VIDEOS_CONF['account_history_enabled']) &&
                        isset($_USER['uid']) ? (int) $_USER['uid'] : 1
                );
                $selection = $pool->mergeSelections(
                    $selection,
                    $poolSelection,
                    $_VIDEOS_CONF
                );
            }
            $allVideos = $selection['videos'];
            $videoMetadata = $selection['metadata'];
            $totalVideos = count($allVideos);
            $pageCount = max(1, (int) ceil($totalVideos / $perPage));
            $page = min($page, $pageCount);
            $videos = array_slice(
                $allVideos,
                ($page - 1) * $perPage,
                $perPage,
                true
            );
            if ($totalVideos === 0) {
                $message = $LANG_VIDEOS['no_more_videos'];
            }
        }
    }
}

$faqService = new Videos_Faq($LANG_VIDEOS_FAQ, $_VIDEOS_CONF);
$faqItems = !$isLocalSearch &&
    !empty($_VIDEOS_CONF['faq_catalogue_enabled']) &&
    $page === 1 && count($videos) > 0
    ? $faqService->catalogue() : array();
$html = '<div class="videos-page">'
    . VIDEOS_renderNavigation('catalogue')
    . '<h1>' . htmlspecialchars($publicTitle, ENT_QUOTES, 'UTF-8') . '</h1>'
    . videos_catalogue_search_form(
        $_CONF['site_url'] . '/videos/index.php',
        $searchQuery
    );

if ($isLocalSearch) {
    $html .= '<div class="videos-search-summary"><strong>'
        . COM_numberFormat($searchTotal) . '</strong> résultat(s) pour « '
        . htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') . ' ».'
        . ' <a href="'
        . htmlspecialchars(
            $_CONF['site_url'] . '/videos/index.php',
            ENT_QUOTES,
            'UTF-8'
        ) . '">Afficher tout le catalogue</a></div>';
}
if ($message !== '') {
    $html .= '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
}
if (count($videos) > 0) {
    $html .= '<div class="videos-grid">';
    foreach ($videos as $videoId => $video) {
        $snippet = isset($video['snippet']) ? $video['snippet'] : array();
        $title = isset($snippet['title']) ? $snippet['title'] : $videoId;
        $channelTitle = isset($snippet['channelTitle'])
            ? $snippet['channelTitle'] : '';
        $channelId = isset($snippet['channelId'])
            ? (string) $snippet['channelId'] : '';
        $duration = isset($video['videos_duration_seconds'])
            ? (int) $video['videos_duration_seconds'] : 0;
        $metadata = isset($videoMetadata[$videoId])
            ? $videoMetadata[$videoId] : array();
        $thumbnail = isset($snippet['thumbnails']['medium']['url'])
            ? $snippet['thumbnails']['medium']['url'] : '';
        $url = $_CONF['site_url'] . '/videos/watch.php?v='
            . rawurlencode($videoId);
        if (!$isLocalSearch && $catalogueContextKey !== '') {
            $url .= '&c=' . rawurlencode($catalogueContextKey);
        }
        $html .= '<article class="videos-card"><a href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
        if (strpos($thumbnail, 'https://') === 0) {
            $html .= '<img loading="lazy" src="'
                . htmlspecialchars($thumbnail, ENT_QUOTES, 'UTF-8')
                . '" alt="'
                . htmlspecialchars(
                    VIDEOS_thumbnailAlt($title, $channelTitle),
                    ENT_QUOTES,
                    'UTF-8'
                ) . '">';
        }
        $html .= '</a><div class="videos-card-content"><h2><a href="'
            . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a></h2>';
        if ($channelTitle !== '') {
            $html .= '<p class="videos-card-meta">';
            if ($channelId !== '' &&
                VIDEOS_channelPageEligible($channelId, $bootstrap)) {
                $html .= '<a href="'
                    . htmlspecialchars(
                        plugin_idtourl_videos('', 'channel:' . $channelId),
                        ENT_QUOTES,
                        'UTF-8'
                    ) . '">'
                    . htmlspecialchars($channelTitle, ENT_QUOTES, 'UTF-8')
                    . '</a>';
            } else {
                $html .= htmlspecialchars(
                    $channelTitle,
                    ENT_QUOTES,
                    'UTF-8'
                );
            }
            $html .= '</p>';
        }
        if ($duration > 0) {
            $html .= '<p class="videos-card-meta">'
                . htmlspecialchars(
                    $LANG_VIDEOS['video_duration'],
                    ENT_QUOTES,
                    'UTF-8'
                ) . ' : ' . videos_catalogue_duration($duration) . '</p>';
        }
        if (!empty($metadata['rating_count'])) {
            $html .= '<p class="videos-card-meta">'
                . htmlspecialchars(
                    $LANG_VIDEOS['local_average'],
                    ENT_QUOTES,
                    'UTF-8'
                ) . ' : '
                . number_format(
                    (float) $metadata['rating_average'],
                    2,
                    ',',
                    ' '
                ) . '/5 (' . (int) $metadata['rating_count'] . ')</p>';
        }
        if (!empty($metadata['viewed'])) {
            $html .= '<span class="videos-card-badge">'
                . htmlspecialchars(
                    $LANG_VIDEOS['already_watched'],
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</span>';
        }
        if (!empty($metadata['permanent_pool'])) {
            $html .= '<span class="videos-card-badge videos-pool-badge">'
                . htmlspecialchars(
                    $LANG_VIDEOS['permanent_pool_badge'],
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</span>';
        }
        $html .= '</div></article>';
    }
    $html .= '</div>';
    if ($pageCount > 1) {
        $paginationParameters = $isLocalSearch
            ? array('q' => $searchQuery) : array();
        $html .= videos_catalogue_pagination(
            $page,
            $pageCount,
            $_CONF['site_url'] . '/videos/index.php',
            $LANG_VIDEOS,
            $paginationParameters
        );
    }
}
if (count($faqItems) > 0) {
    $html .= $faqService->render($faqItems, $LANG_VIDEOS['faq_title']);
}
$html .= '</div>';

if ($isLocalSearch) {
    // Search-result URLs are useful to visitors but should not create an
    // unbounded family of indexable query pages.
    $catalogueHeader = '<link rel="canonical" href="'
        . htmlspecialchars(
            $_CONF['site_url'] . '/videos/index.php',
            ENT_QUOTES,
            'UTF-8'
        ) . '">' . "\n"
        . '<meta name="robots" content="noindex,follow">';
} else {
    $catalogueHeader = $seo->catalogue(
        $publicTitle,
        $page,
        count($videos) > 0
    );
    if (!empty($_VIDEOS_CONF['seo_catalogue_index'])) {
        $catalogueHeader .= $faqService->structuredData($faqItems);
    }
}
$catalogueHeader .= "\n" . videos_catalogue_search_style();
$pageTitle = $isLocalSearch
    ? 'Recherche « ' . $searchQuery . ' » - ' . $publicTitle
    : $publicTitle;
if (!$isLocalSearch && $page > 1) {
    $pageTitle .= ' – Page ' . $page;
}
echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $pageTitle,
        'headercode' => $catalogueHeader
    )
);

function videos_public_search($bootstrap, $query, $configuration)
{
    return videos_create_public_youtube_service($bootstrap, $configuration)
        ->find($query, videos_build_public_search_parameters($configuration));
}

function videos_create_public_youtube_service($bootstrap, $configuration)
{
    $store = $bootstrap->getStore();
    return new Videos_YouTubeService(
        new Videos_YouTubeClient(
            $bootstrap->getYouTubeApiKey(),
            isset($configuration['youtube_timeout'])
                ? $configuration['youtube_timeout'] : 8
        ),
        new Videos_Cache($store),
        new Videos_Quota($store),
        new Videos_Logger($store)
    );
}

function videos_build_public_search_parameters($configuration)
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
        'daily_search_limit' => isset(
            $configuration['youtube_daily_search_limit']
        ) ? $configuration['youtube_daily_search_limit'] : 20,
        'cache_ttl' => isset($configuration['search_cache_ttl'])
            ? $configuration['search_cache_ttl'] : 86400,
        'video_cache_ttl' => isset($configuration['video_cache_ttl'])
            ? $configuration['video_cache_ttl'] : 86400,
        'channel_cache_ttl' => isset($configuration['channel_cache_ttl'])
            ? $configuration['channel_cache_ttl'] : 604800,
        'availability_cache_ttl' => isset(
            $configuration['availability_cache_ttl']
        ) ? $configuration['availability_cache_ttl'] : 86400,
        'blocked_videos' => isset($configuration['blocked_videos'])
            ? $configuration['blocked_videos'] : '',
        'blocked_channels' => isset($configuration['blocked_channels'])
            ? $configuration['blocked_channels'] : '',
        'allowed_channels' => isset($configuration['allowed_channels'])
            ? $configuration['allowed_channels'] : '',
        'minimum_duration' => 0,
        'maximum_duration' => 0,
        'exclude_short_videos' => !empty($configuration['exclude_short_videos'])
            ? 1 : 0,
        'short_filter_mode' => isset($configuration['short_filter_mode'])
            ? $configuration['short_filter_mode'] : 'probable',
        'short_max_duration' => isset($configuration['short_max_duration'])
            ? $configuration['short_max_duration'] : 180
    );
}

function videos_catalogue_search_form($action, $query)
{
    return '<form class="videos-catalogue-search" method="get" action="'
        . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '">'
        . '<label for="videos-search-q">Rechercher dans le catalogue</label>'
        . '<div><input id="videos-search-q" type="search" name="q" maxlength="120"'
        . ' value="' . htmlspecialchars($query, ENT_QUOTES, 'UTF-8') . '"'
        . ' placeholder="Titre, chaîne ou mots-clés">'
        . '<button type="submit">Rechercher</button></div>'
        . '<p>La recherche utilise les vidéos déjà connues du site et ne consomme '
        . 'aucun quota YouTube supplémentaire.</p></form>';
}

function videos_catalogue_search_style()
{
    return '<style>'
        . '.videos-catalogue-search{margin:0 0 1.25rem;padding:1rem;border:1px solid rgba(127,127,127,.28);border-radius:.55rem;background:rgba(127,127,127,.045)}'
        . '.videos-catalogue-search label{display:block;margin-bottom:.45rem;font-weight:600}'
        . '.videos-catalogue-search>div{display:flex;gap:.5rem;align-items:stretch}'
        . '.videos-catalogue-search input{flex:1 1 20rem;min-width:0;padding:.65rem .75rem;border:1px solid rgba(127,127,127,.45);border-radius:.35rem;background:transparent;color:inherit}'
        . '.videos-catalogue-search button{padding:.65rem 1rem;border:1px solid rgba(127,127,127,.45);border-radius:.35rem;cursor:pointer}'
        . '.videos-catalogue-search p,.videos-search-summary{font-size:.88rem;line-height:1.4}'
        . '.videos-catalogue-search p{margin:.55rem 0 0;opacity:.75}'
        . '.videos-search-summary{margin:0 0 1rem;padding:.7rem .85rem;background:rgba(127,127,127,.08);border-radius:.4rem}'
        . '@media(max-width:520px){.videos-catalogue-search>div{flex-direction:column}.videos-catalogue-search input,.videos-catalogue-search button{width:100%;box-sizing:border-box}}'
        . '</style>';
}

function videos_catalogue_duration($seconds)
{
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $remaining = $seconds % 60;
    return ($hours > 0 ? $hours . ':' : '')
        . ($hours > 0
            ? str_pad($minutes, 2, '0', STR_PAD_LEFT) : $minutes)
        . ':' . str_pad($remaining, 2, '0', STR_PAD_LEFT);
}

function videos_catalogue_pagination(
    $page,
    $pageCount,
    $baseUrl,
    $language,
    $parameters = array()
) {
    $html = '<nav class="videos-pagination" aria-label="Pagination">';
    if ($page > 1) {
        $parameters['page'] = $page - 1;
        $html .= '<a rel="prev" href="'
            . htmlspecialchars(
                $baseUrl . '?' . http_build_query($parameters, '', '&'),
                ENT_QUOTES,
                'UTF-8'
            ) . '">'
            . htmlspecialchars(
                $language['previous_page'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</a>';
    }
    $html .= '<span>'
        . htmlspecialchars(
            sprintf($language['catalogue_page'], $page, $pageCount),
            ENT_QUOTES,
            'UTF-8'
        ) . '</span>';
    if ($page < $pageCount) {
        $parameters['page'] = $page + 1;
        $html .= '<a rel="next" href="'
            . htmlspecialchars(
                $baseUrl . '?' . http_build_query($parameters, '', '&'),
                ENT_QUOTES,
                'UTF-8'
            ) . '">'
            . htmlspecialchars(
                $language['next_page'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</a>';
    }
    return $html . '</nav>';
}
