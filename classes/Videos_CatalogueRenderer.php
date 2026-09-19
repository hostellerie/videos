<?php

if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

/**
 * Render the public Videos catalogue through Geeklog plugin templates.
 *
 * Selection, caching and discovery stay outside this class. It receives an
 * already prepared local catalogue and turns it into presentation fragments.
 */
class Videos_CatalogueRenderer
{
    private $bootstrap;
    private $configuration;
    private $language;
    private $siteUrl;

    public function __construct($bootstrap, $configuration, $language, $siteUrl)
    {
        $this->bootstrap = $bootstrap;
        $this->configuration = is_array($configuration)
            ? $configuration : array();
        $this->language = is_array($language) ? $language : array();
        $this->siteUrl = rtrim((string) $siteUrl, '/');
    }

    public function render(
        $publicTitle,
        $videos,
        $videoMetadata,
        $catalogueContextKey,
        $isLocalSearch,
        $searchTotal,
        $searchQuery,
        $page,
        $pageCount,
        $message,
        $faqHtml
    ) {
        $summary = $isLocalSearch
            ? $this->renderSearchSummary($searchTotal, $searchQuery) : '';
        $messageHtml = $message !== ''
            ? '<p>' . $this->escape($message) . '</p>' : '';
        $grid = $this->renderGrid(
            is_array($videos) ? $videos : array(),
            is_array($videoMetadata) ? $videoMetadata : array(),
            (string) $catalogueContextKey,
            (bool) $isLocalSearch
        );
        $pagination = '';
        if ($pageCount > 1) {
            $parameters = $isLocalSearch
                ? array('q' => (string) $searchQuery) : array();
            $pagination = $this->renderPagination(
                (int) $page,
                (int) $pageCount,
                $this->siteUrl . '/videos/index.php',
                $parameters
            );
        }

        $html = Videos_TemplateRenderer::render(
            'catalogue.thtml',
            array(
                'navigation' => VIDEOS_renderNavigation('catalogue'),
                'title' => $this->escape($publicTitle),
                'search_form' => $this->renderSearchForm(
                    $this->siteUrl . '/videos/index.php',
                    $searchQuery
                ),
                'summary' => $summary,
                'message' => $messageHtml,
                'grid' => $grid,
                'pagination' => $pagination,
                'faq' => (string) $faqHtml
            )
        );

        if ($html !== '') {
            return $html;
        }

        // Defensive fallback for an incomplete or broken template install.
        return '<div class="videos-page">'
            . VIDEOS_renderNavigation('catalogue')
            . '<h1>' . $this->escape($publicTitle) . '</h1>'
            . $this->renderSearchForm(
                $this->siteUrl . '/videos/index.php',
                $searchQuery
            )
            . $summary
            . $messageHtml
            . $grid
            . $pagination
            . (string) $faqHtml
            . '</div>';
    }

    private function renderGrid($videos, $videoMetadata, $contextKey, $isLocalSearch)
    {
        if (count($videos) === 0) {
            return '';
        }

        $cards = '';
        foreach ($videos as $videoId => $video) {
            $metadata = isset($videoMetadata[$videoId])
                ? $videoMetadata[$videoId] : array();
            $cards .= $this->renderCard(
                (string) $videoId,
                is_array($video) ? $video : array(),
                is_array($metadata) ? $metadata : array(),
                $contextKey,
                $isLocalSearch
            );
        }

        return '<div class="videos-grid">' . $cards . '</div>';
    }

    private function renderCard(
        $videoId,
        $video,
        $metadata,
        $contextKey,
        $isLocalSearch
    ) {
        $snippet = isset($video['snippet']) && is_array($video['snippet'])
            ? $video['snippet'] : array();
        $title = isset($snippet['title']) ? $snippet['title'] : $videoId;
        $channelTitle = isset($snippet['channelTitle'])
            ? $snippet['channelTitle'] : '';
        $channelId = isset($snippet['channelId'])
            ? (string) $snippet['channelId'] : '';
        $duration = isset($video['videos_duration_seconds'])
            ? (int) $video['videos_duration_seconds'] : 0;
        $thumbnail = isset($snippet['thumbnails']['medium']['url'])
            ? $snippet['thumbnails']['medium']['url'] : '';

        $url = $this->siteUrl . '/videos/watch.php?v=' . rawurlencode($videoId);
        if (!$isLocalSearch && preg_match('/^[a-f0-9]{64}$/', $contextKey)) {
            $url .= '&c=' . rawurlencode($contextKey);
        }

        $thumbnailHtml = '';
        if (strpos($thumbnail, 'https://') === 0) {
            $thumbnailHtml = '<img loading="lazy" src="'
                . $this->escape($thumbnail) . '" alt="'
                . $this->escape(VIDEOS_thumbnailAlt($title, $channelTitle))
                . '">';
        }

        $channelHtml = '';
        if ($channelTitle !== '') {
            $label = $this->escape($channelTitle);
            if ($channelId !== '' &&
                VIDEOS_channelPageEligible($channelId, $this->bootstrap)) {
                $label = '<a href="'
                    . $this->escape(
                        plugin_idtourl_videos('', 'channel:' . $channelId)
                    ) . '">' . $label . '</a>';
            }
            $channelHtml = '<p class="videos-card-meta">' . $label . '</p>';
        }

        $durationHtml = '';
        if ($duration > 0) {
            $durationHtml = '<p class="videos-card-meta">'
                . $this->escape($this->lang('video_duration'))
                . ' : ' . $this->formatDuration($duration) . '</p>';
        }

        $ratingHtml = '';
        if (!empty($metadata['rating_count'])) {
            $ratingHtml = '<p class="videos-card-meta">'
                . $this->escape($this->lang('local_average')) . ' : '
                . number_format(
                    (float) $metadata['rating_average'],
                    2,
                    ',',
                    ' '
                ) . '/5 (' . (int) $metadata['rating_count'] . ')</p>';
        }

        $badges = '';
        if (!empty($metadata['viewed'])) {
            $badges .= '<span class="videos-card-badge">'
                . $this->escape($this->lang('already_watched')) . '</span>';
        }
        if (!empty($metadata['permanent_pool'])) {
            $badges .= '<span class="videos-card-badge videos-pool-badge">'
                . $this->escape($this->lang('permanent_pool_badge')) . '</span>';
        }

        $escapedUrl = $this->escape($url);
        $variables = array(
            'video_url' => $escapedUrl,
            'thumbnail' => $thumbnailHtml,
            'title' => $this->escape($title),
            'channel' => $channelHtml,
            'duration' => $durationHtml,
            'rating' => $ratingHtml,
            'badges' => $badges
        );
        $html = Videos_TemplateRenderer::render('video-card.thtml', $variables);
        if ($html !== '') {
            return $html;
        }

        return '<article class="videos-card"><a href="' . $escapedUrl . '">'
            . $thumbnailHtml . '</a><div class="videos-card-content"><h2><a href="'
            . $escapedUrl . '">' . $this->escape($title) . '</a></h2>'
            . $channelHtml . $durationHtml . $ratingHtml . $badges
            . '</div></article>';
    }

    private function renderSearchSummary($searchTotal, $searchQuery)
    {
        $text = sprintf(
            $this->lang('catalogue_search_results'),
            (string) $searchQuery
        );
        return '<div class="videos-search-summary"><strong>'
            . COM_numberFormat((int) $searchTotal) . '</strong> '
            . $this->escape($text) . ' <a href="'
            . $this->escape($this->siteUrl . '/videos/index.php') . '">'
            . $this->escape($this->lang('catalogue_show_all')) . '</a></div>';
    }

    private function renderSearchForm($action, $query)
    {
        return '<form class="videos-catalogue-search" method="get" action="'
            . $this->escape($action) . '">'
            . '<label for="videos-search-q">'
            . $this->escape($this->lang('catalogue_search_label')) . '</label>'
            . '<div><input id="videos-search-q" type="search" name="q" maxlength="120"'
            . ' value="' . $this->escape($query) . '" placeholder="'
            . $this->escape($this->lang('catalogue_search_placeholder')) . '">'
            . '<button type="submit">'
            . $this->escape($this->lang('catalogue_search_button'))
            . '</button></div></form>';
    }

    private function renderPagination($page, $pageCount, $baseUrl, $parameters)
    {
        $html = '<nav class="videos-pagination" aria-label="Pagination">';
        if ($page > 1) {
            $parameters['page'] = $page - 1;
            $html .= '<a rel="prev" href="'
                . $this->escape(
                    $baseUrl . '?' . http_build_query($parameters, '', '&')
                ) . '">' . $this->escape($this->lang('previous_page')) . '</a>';
        }
        $html .= '<span>' . $this->escape(
            sprintf($this->lang('catalogue_page'), $page, $pageCount)
        ) . '</span>';
        if ($page < $pageCount) {
            $parameters['page'] = $page + 1;
            $html .= '<a rel="next" href="'
                . $this->escape(
                    $baseUrl . '?' . http_build_query($parameters, '', '&')
                ) . '">' . $this->escape($this->lang('next_page')) . '</a>';
        }
        return $html . '</nav>';
    }

    private function formatDuration($seconds)
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $remaining = $seconds % 60;
        return ($hours > 0 ? $hours . ':' : '')
            . ($hours > 0
                ? str_pad($minutes, 2, '0', STR_PAD_LEFT) : $minutes)
            . ':' . str_pad($remaining, 2, '0', STR_PAD_LEFT);
    }

    private function lang($key)
    {
        return isset($this->language[$key])
            ? (string) $this->language[$key] : '';
    }

    private function escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
