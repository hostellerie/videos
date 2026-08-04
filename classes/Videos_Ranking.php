<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Ranking
{
    private $store;
    private $ratings;
    private $engagement;
    private $cache;
    private $recommendations;
    private $channelRanking;

    public function __construct(
        $store,
        $ratings,
        $engagement,
        $cache,
        $recommendations = null
    )
    {
        $this->store = $store;
        $this->ratings = $ratings;
        $this->engagement = $engagement;
        $this->cache = $cache;
        $this->recommendations = is_object($recommendations)
            ? $recommendations : new Videos_RecommendationStats($store);
        $this->channelRanking = new Videos_ChannelRanking($store, $cache);
    }

    public function updateVideo($videoId, $video, $updateChannels = true)
    {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        $item = $this->calculate($videoId, $video);
        $result = $this->store->update(
            'rankings/global.json',
            'videos.global_ranking',
            array('rebuilt_at' => null, 'items' => array()),
            function ($document) use ($videoId, $item) {
                $items = isset($document['data']['items']) &&
                    is_array($document['data']['items'])
                    ? $document['data']['items'] : array();
                $items[$videoId] = $item;
                uasort($items, array($this, 'compareItems'));
                if (count($items) > 500) {
                    $items = array_slice($items, 0, 500, true);
                }
                $document['data']['items'] = $items;
                $document['data']['rebuilt_at'] = gmdate('Y-m-d\TH:i:s\Z');
                return $document;
            }
        );
        if ($result === false) {
            return false;
        }
        if ($updateChannels) {
            $this->channelRanking->rebuildFromVideos(
                isset($result['data']['items'])
                    ? $result['data']['items'] : array()
            );
        }
        return $result;
    }

    public function rebuildChannels()
    {
        $document = $this->store->read(
            'rankings/global.json',
            'videos.global_ranking',
            array('rebuilt_at' => null, 'items' => array())
        );
        $items = isset($document['data']['items']) &&
            is_array($document['data']['items'])
            ? $document['data']['items'] : array();
        return $this->channelRanking->rebuildFromVideos($items);
    }

    public function getGlobal($limit)
    {
        $document = $this->store->read(
            'rankings/global.json',
            'videos.global_ranking',
            array('rebuilt_at' => null, 'items' => array())
        );
        $items = isset($document['data']['items']) &&
            is_array($document['data']['items'])
            ? $document['data']['items'] : array();
        uasort($items, array($this, 'compareItems'));
        return array_slice($items, 0, max(1, (int) $limit), true);
    }

    public function rebuild()
    {
        $videoIds = $this->discoverVideoIds(5000);
        $items = array();
        foreach ($videoIds as $videoId) {
            $video = $this->cache->getVideo($videoId, true);
            $items[$videoId] = $this->calculate(
                $videoId,
                is_array($video) ? $video : array()
            );
        }
        uasort($items, array($this, 'compareItems'));
        if (count($items) > 500) {
            $items = array_slice($items, 0, 500, true);
        }
        $document = $this->store->createDocument(
            'videos.global_ranking',
            array(
                'rebuilt_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'items' => $items
            )
        );
        $written = $this->store->write(
            'rankings/global.json',
            'videos.global_ranking',
            $document
        );
        if (!$written) {
            return false;
        }
        $this->channelRanking->rebuildFromVideos($items);
        return count($items);
    }

    public function compareItems($left, $right)
    {
        $leftScore = isset($left['score']) ? (float) $left['score'] : 0;
        $rightScore = isset($right['score']) ? (float) $right['score'] : 0;
        if ($leftScore == $rightScore) {
            return strcmp(
                isset($left['video_id']) ? $left['video_id'] : '',
                isset($right['video_id']) ? $right['video_id'] : ''
            );
        }
        return $leftScore > $rightScore ? -1 : 1;
    }

    private function calculate($videoId, $video)
    {
        $rating = $this->ratings->get($videoId);
        $engagement = $this->engagement->get($videoId);
        $recommendation = $this->recommendations->get($videoId);
        $ratingCount = isset($rating['rating_count'])
            ? max(0, (int) $rating['rating_count']) : 0;
        $ratingAverage = isset($rating['rating_average'])
            ? max(0, min(5, (float) $rating['rating_average'])) : 0;
        $weightedRating = (
            ($ratingCount * $ratingAverage) + (5 * 3.5)
        ) / ($ratingCount + 5);
        $views = isset($engagement['view_count'])
            ? max(0, (int) $engagement['view_count']) : 0;
        $watchRatio = isset($engagement['watch_ratio_average'])
            ? max(0, min(1, (float) $engagement['watch_ratio_average'])) : 0;
        $ratingScore = $weightedRating * 12;
        $confidenceScore = min(12, log($ratingCount + 1) * 4);
        $viewScore = min(20, log($views + 1) * 4);
        $watchScore = $watchRatio * 15;
        $activityScore = $this->recencyScore(
            isset($engagement['last_viewed_at'])
                ? $engagement['last_viewed_at'] : null,
            30,
            10
        );
        $publishedAt = isset($video['snippet']['publishedAt'])
            ? $video['snippet']['publishedAt'] : null;
        $publicationScore = $this->recencyScore($publishedAt, 365, 5);
        $accepted = isset($recommendation['accepted_count'])
            ? max(0, (int) $recommendation['accepted_count']) : 0;
        $skipped = isset($recommendation['skipped_count'])
            ? max(0, (int) $recommendation['skipped_count']) : 0;
        $recommendationScore = min(10, log($accepted + 1) * 3)
            - min(10, log($skipped + 1) * 2);
        $score = $ratingScore + $confidenceScore + $viewScore
            + $watchScore + $activityScore + $publicationScore
            + $recommendationScore;

        return array(
            'video_id' => $videoId,
            'channel_id' => isset($video['snippet']['channelId'])
                ? substr((string) $video['snippet']['channelId'], 0, 64)
                : (isset($engagement['channel_id'])
                    ? $engagement['channel_id'] : ''),
            'channel_title' => isset($video['snippet']['channelTitle'])
                ? $this->cleanTitle($video['snippet']['channelTitle']) : '',
            'title' => isset($video['snippet']['title'])
                ? $this->cleanTitle($video['snippet']['title']) : '',
            'score' => round($score, 4),
            'rating_average' => $ratingAverage,
            'rating_count' => $ratingCount,
            'weighted_rating' => round($weightedRating, 4),
            'view_count' => $views,
            'watch_ratio_average' => round($watchRatio, 4),
            'recommendation_accepted_count' => $accepted,
            'recommendation_skipped_count' => $skipped,
            'recommendation_score' => round($recommendationScore, 4),
            'last_viewed_at' => isset($engagement['last_viewed_at'])
                ? $engagement['last_viewed_at'] : null,
            'calculated_at' => gmdate('Y-m-d\TH:i:s\Z')
        );
    }

    private function recencyScore($date, $halfLifeDays, $maximum)
    {
        $timestamp = !empty($date) ? strtotime((string) $date) : false;
        if ($timestamp === false) {
            return 0;
        }
        $ageDays = max(0, (time() - $timestamp) / 86400);
        return $maximum * exp(-log(2) * $ageDays / $halfLifeDays);
    }

    private function cleanTitle($title)
    {
        $title = trim(strip_tags((string) $title));
        if (function_exists('MBYTE_substr')) {
            return MBYTE_substr($title, 0, 250);
        }
        if (function_exists('mb_substr')) {
            return mb_substr($title, 0, 250, 'UTF-8');
        }
        if (preg_match('/^.{0,250}/us', $title, $match)) {
            return $match[0];
        }
        return '';
    }

    private function discoverVideoIds($limit)
    {
        $ids = array();
        $directories = array(
            'stats/videos',
            'stats/engagement/videos',
            'stats/recommendations/videos'
        );
        foreach ($directories as $relativeDirectory) {
            $directory = $this->store->getRoot() . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $relativeDirectory
            );
            if (!is_dir($directory)) {
                continue;
            }
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator(
                        $directory,
                        FilesystemIterator::SKIP_DOTS
                    ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
                foreach ($iterator as $fileInfo) {
                    if (!$fileInfo->isFile() || $fileInfo->isLink() ||
                        substr($fileInfo->getFilename(), -5) !== '.json') {
                        continue;
                    }
                    $videoId = substr($fileInfo->getFilename(), 0, -5);
                    if (Videos_Validator::youtubeVideoId($videoId)) {
                        $ids[$videoId] = true;
                    }
                    if (count($ids) >= $limit) {
                        break 2;
                    }
                }
            } catch (UnexpectedValueException $exception) {
                continue;
            }
        }
        return array_keys($ids);
    }
}
