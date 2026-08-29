<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

/**
 * Search the public Videos corpus without performing a YouTube API request.
 *
 * The service deliberately searches only material already known locally:
 * discovery reservoir entries, ranked videos and permanent catalogue items.
 */
class Videos_Search
{
    private $store;
    private $cache;
    private $configuration;
    private $moderation;

    public function __construct($store, $cache, $configuration)
    {
        $this->store = $store;
        $this->cache = $cache;
        $this->configuration = is_array($configuration)
            ? $configuration : array();
        $this->moderation = new Videos_Moderation($store);
    }

    /**
     * Return the bounded public inventory used by both Geeklog search and
     * the catalogue search form.
     */
    public function inventory($limit)
    {
        $limit = max(1, min(1000, (int) $limit));
        $videos = array();

        if (!empty($this->configuration['discovery_enabled'])) {
            $reservoir = new Videos_DiscoveryReservoir($this->store, $this->cache);
            foreach ($reservoir->videos($this->configuration) as $videoId => $video) {
                if ($this->isPublic($videoId, $video)) {
                    $videos[$videoId] = $video;
                }
                if (count($videos) >= $limit) {
                    return $videos;
                }
            }
        }

        $ranking = new Videos_Ranking(
            $this->store,
            new Videos_RatingStats($this->store),
            new Videos_VideoStats($this->store),
            $this->cache
        );
        foreach ($ranking->getGlobal(500) as $videoId => $item) {
            if (isset($videos[$videoId])) {
                continue;
            }
            $video = $this->cache->getVideo($videoId, true);
            if ($this->isPublic($videoId, $video)) {
                $videos[$videoId] = $video;
            }
            if (count($videos) >= $limit) {
                return $videos;
            }
        }

        $pool = new Videos_PermanentPool($this->store, $this->cache);
        $records = $pool->records();
        $items = isset($records['items']) && is_array($records['items'])
            ? $records['items'] : array();
        foreach ($items as $videoId => $item) {
            if (isset($videos[$videoId])) {
                continue;
            }
            $video = $this->cache->getVideo($videoId, true);
            if ($this->isPublic($videoId, $video)) {
                $videos[$videoId] = $video;
            }
            if (count($videos) >= $limit) {
                break;
            }
        }

        return $videos;
    }

    /**
     * Search the local public inventory.
     *
     * $keyType follows Geeklog search semantics: phrase, all or any.
     */
    public function search(
        $query,
        $keyType,
        $dateStart,
        $dateEnd,
        $titlesOnly,
        $limit
    ) {
        $query = trim(strip_tags((string) $query));
        $keyType = in_array($keyType, array('phrase', 'all', 'any'), true)
            ? $keyType : 'phrase';
        $limit = max(1, min(500, (int) $limit));
        if ($query === '') {
            return array();
        }

        $words = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($words) || count($words) === 0) {
            return array();
        }
        $normalizedWords = array();
        foreach ($words as $word) {
            $normalizedWords[] = $this->lower($word);
        }
        $needle = $this->lower($query);
        $start = $this->dateBoundary($dateStart, false);
        $end = $this->dateBoundary($dateEnd, true);
        $matches = array();

        $ranking = new Videos_Ranking(
            $this->store,
            new Videos_RatingStats($this->store),
            new Videos_VideoStats($this->store),
            $this->cache
        );
        $ranked = $ranking->getGlobal(500);

        foreach ($this->inventory(1000) as $videoId => $video) {
            $snippet = isset($video['snippet']) && is_array($video['snippet'])
                ? $video['snippet'] : array();
            $title = isset($snippet['title']) ? trim((string) $snippet['title']) : $videoId;
            $description = isset($snippet['description'])
                ? trim(strip_tags((string) $snippet['description'])) : '';
            $channel = isset($snippet['channelTitle'])
                ? trim((string) $snippet['channelTitle']) : '';
            $published = !empty($snippet['publishedAt'])
                ? strtotime((string) $snippet['publishedAt']) : false;
            if ($start !== null && ($published === false || $published < $start)) {
                continue;
            }
            if ($end !== null && ($published === false || $published > $end)) {
                continue;
            }

            $titleText = $this->lower($title);
            $channelText = $this->lower($channel);
            $descriptionText = $this->lower($description);
            $haystacks = $titlesOnly
                ? array($titleText)
                : array($titleText, $channelText, $descriptionText);
            if (!$this->matches($haystacks, $needle, $normalizedWords, $keyType)) {
                continue;
            }

            $score = $this->relevanceScore(
                $titleText,
                $channelText,
                $descriptionText,
                $needle,
                $normalizedWords
            );
            if (isset($ranked[$videoId]['score'])) {
                $score += min(10, max(0, (float) $ranked[$videoId]['score']) / 20);
            }
            $matches[$videoId] = array(
                'video' => $video,
                'score' => $score,
                'published' => $published === false ? 0 : $published,
                'ranking' => isset($ranked[$videoId]) ? $ranked[$videoId] : array()
            );
        }

        uasort($matches, array($this, 'compareMatches'));
        return array_slice($matches, 0, $limit, true);
    }

    /**
     * Build rows understood by Geeklog Search API 2 / ListFactory.
     */
    public function geeklogResults(
        $query,
        $keyType,
        $dateStart,
        $dateEnd,
        $titlesOnly,
        $limit
    ) {
        $rows = array();
        $descriptionService = new Videos_Description();
        foreach ($this->search(
            $query,
            $keyType,
            $dateStart,
            $dateEnd,
            $titlesOnly,
            $limit
        ) as $videoId => $match) {
            $video = $match['video'];
            $snippet = isset($video['snippet']) && is_array($video['snippet'])
                ? $video['snippet'] : array();
            $title = isset($snippet['title']) ? trim((string) $snippet['title']) : $videoId;
            $description = $descriptionService->excerpt(
                isset($snippet['description']) ? $snippet['description'] : '',
                isset($this->configuration['description_mode'])
                    ? $this->configuration['description_mode'] : 'clean'
            );
            $published = !empty($snippet['publishedAt'])
                ? strtotime((string) $snippet['publishedAt']) : false;
            $ranking = isset($match['ranking']) && is_array($match['ranking'])
                ? $match['ranking'] : array();
            $rows[] = array(
                'id' => $videoId,
                'url' => plugin_idtourl_videos('', $videoId),
                'title' => $title,
                'description' => $description,
                'date' => $published === false
                    ? '' : date('Y-m-d H:i:s', $published),
                'uid' => 0,
                'hits' => isset($ranking['view_count'])
                    ? max(0, (int) $ranking['view_count']) : 0
            );
        }
        return $rows;
    }

    public function compareMatches($left, $right)
    {
        $leftScore = isset($left['score']) ? (float) $left['score'] : 0;
        $rightScore = isset($right['score']) ? (float) $right['score'] : 0;
        if ($leftScore != $rightScore) {
            return $leftScore > $rightScore ? -1 : 1;
        }
        $leftDate = isset($left['published']) ? (int) $left['published'] : 0;
        $rightDate = isset($right['published']) ? (int) $right['published'] : 0;
        if ($leftDate == $rightDate) {
            return 0;
        }
        return $leftDate > $rightDate ? -1 : 1;
    }

    private function isPublic($videoId, $video)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) ||
            !is_array($video) ||
            $this->cache->isVideoUnavailable($videoId) ||
            $this->moderation->isVideoBlocked($videoId)) {
            return false;
        }
        $channelId = !empty($video['snippet']['channelId'])
            ? (string) $video['snippet']['channelId'] : '';
        if ($this->moderation->isChannelExcluded($channelId)) {
            return false;
        }
        $blockedVideos = $this->listSet(
            isset($this->configuration['blocked_videos'])
                ? $this->configuration['blocked_videos'] : ''
        );
        $blockedChannels = $this->listSet(
            isset($this->configuration['blocked_channels'])
                ? $this->configuration['blocked_channels'] : ''
        );
        if (isset($blockedVideos[$videoId]) || isset($blockedChannels[$channelId])) {
            return false;
        }
        return !Videos_VideoPolicy::excludesShortVideo(
            $video,
            $this->configuration
        );
    }

    private function matches($haystacks, $needle, $words, $keyType)
    {
        if ($keyType === 'phrase') {
            foreach ($haystacks as $haystack) {
                if ($needle !== '' && strpos($haystack, $needle) !== false) {
                    return true;
                }
            }
            return false;
        }

        $found = 0;
        foreach ($words as $word) {
            $wordFound = false;
            foreach ($haystacks as $haystack) {
                if ($word !== '' && strpos($haystack, $word) !== false) {
                    $wordFound = true;
                    break;
                }
            }
            if ($wordFound) {
                $found++;
            } elseif ($keyType === 'all') {
                return false;
            }
        }
        return $keyType === 'all'
            ? $found === count($words)
            : $found > 0;
    }

    private function relevanceScore($title, $channel, $description, $needle, $words)
    {
        $score = 0;
        if ($needle !== '') {
            if ($title === $needle) {
                $score += 100;
            } elseif (strpos($title, $needle) !== false) {
                $score += 60;
            }
            if (strpos($channel, $needle) !== false) {
                $score += 35;
            }
            if (strpos($description, $needle) !== false) {
                $score += 15;
            }
        }
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            if (strpos($title, $word) !== false) {
                $score += 12;
            }
            if (strpos($channel, $word) !== false) {
                $score += 7;
            }
            if (strpos($description, $word) !== false) {
                $score += 2;
            }
        }
        return $score;
    }

    private function dateBoundary($value, $endOfDay)
    {
        if (empty($value)) {
            return null;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }
        return $endOfDay
            ? strtotime(date('Y-m-d 23:59:59', $timestamp))
            : strtotime(date('Y-m-d 00:00:00', $timestamp));
    }

    private function lower($value)
    {
        $value = trim((string) $value);
        if (function_exists('MBYTE_strtolower')) {
            return MBYTE_strtolower($value);
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }

    private function listSet($value)
    {
        $items = preg_split('/[\s,;]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY);
        $set = array();
        foreach ($items as $item) {
            $set[trim($item)] = true;
        }
        return $set;
    }
}
