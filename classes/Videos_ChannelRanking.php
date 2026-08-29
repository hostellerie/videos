<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_ChannelRanking
{
    private $store;
    private $cache;

    public function __construct($store, $cache)
    {
        $this->store = $store;
        $this->cache = $cache;
    }

    public function rebuildFromVideos($videoItems)
    {
        if (!is_array($videoItems)) {
            return false;
        }
        $before = $this->getGlobal(50);
        $beforeSignature = $this->rankingSignature($before);
        $channels = array();
        foreach ($videoItems as $item) {
            $channelId = isset($item['channel_id'])
                ? (string) $item['channel_id'] : '';
            if (!Videos_Validator::youtubeChannelId($channelId)) {
                continue;
            }
            if (!isset($channels[$channelId])) {
                $channels[$channelId] = $this->emptyAggregate($channelId);
            }
            $channel = $channels[$channelId];
            if ($channel['title'] === '' && !empty($item['channel_title'])) {
                $channel['title'] = $this->cleanTitle($item['channel_title']);
            }
            $ratings = isset($item['rating_count'])
                ? max(0, (int) $item['rating_count']) : 0;
            $average = isset($item['rating_average'])
                ? max(0, min(5, (float) $item['rating_average'])) : 0;
            $views = isset($item['view_count'])
                ? max(0, (int) $item['view_count']) : 0;
            $watchRatio = isset($item['watch_ratio_average'])
                ? max(0, min(1, (float) $item['watch_ratio_average'])) : 0;
            $channel['video_count']++;
            $channel['rating_count'] += $ratings;
            $channel['rating_sum'] += $average * $ratings;
            $channel['view_count'] += $views;
            $channel['watch_ratio_weighted_sum'] += $watchRatio * max(1, $views);
            $channel['watch_ratio_weight'] += max(1, $views);
            if ($ratings > 0 && $average >= 4) {
                $channel['appreciated_video_count']++;
            }
            if (isset($item['score']) && (float) $item['score'] >= 70) {
                $channel['high_ranked_video_count']++;
            }
            $candidateDate = isset($item['last_viewed_at'])
                ? $item['last_viewed_at'] : null;
            if ($this->isMoreRecent($candidateDate, $channel['last_activity_at'])) {
                $channel['last_activity_at'] = $candidateDate;
            }
            $channels[$channelId] = $channel;
        }
        $items = array();
        foreach ($channels as $channelId => $aggregate) {
            $items[$channelId] = $this->calculate($aggregate);
        }
        uasort($items, array($this, 'compareItems'));
        if (count($items) > 250) {
            $items = array_slice($items, 0, 250, true);
        }
        $document = $this->store->createDocument(
            'videos.channel_ranking',
            array(
                'rebuilt_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'items' => $items
            )
        );
        $written = $this->store->write(
            'rankings/channels.json',
            'videos.channel_ranking',
            $document
        );
        if (!$written) {
            return false;
        }
        if ($beforeSignature !== $this->rankingSignature(array_slice($items, 0, 50, true)) &&
            function_exists('VIDEOS_signalSaved')) {
            VIDEOS_signalSaved('rankings:channels');
        }
        return count($items);
    }

    public function getGlobal($limit)
    {
        $document = $this->store->read(
            'rankings/channels.json',
            'videos.channel_ranking',
            array('rebuilt_at' => null, 'items' => array())
        );
        $items = isset($document['data']['items']) &&
            is_array($document['data']['items'])
            ? $document['data']['items'] : array();
        uasort($items, array($this, 'compareItems'));
        return array_slice($items, 0, max(1, (int) $limit), true);
    }

    public function compareItems($left, $right)
    {
        $leftScore = isset($left['score']) ? (float) $left['score'] : 0;
        $rightScore = isset($right['score']) ? (float) $right['score'] : 0;
        if ($leftScore == $rightScore) {
            return strcmp(
                isset($left['channel_id']) ? $left['channel_id'] : '',
                isset($right['channel_id']) ? $right['channel_id'] : ''
            );
        }
        return $leftScore > $rightScore ? -1 : 1;
    }

    private function rankingSignature($items)
    {
        if (!is_array($items)) {
            return '';
        }
        return hash('sha256', implode('|', array_keys($items)));
    }

    private function calculate($aggregate)
    {
        $ratingCount = $aggregate['rating_count'];
        $ratingAverage = $ratingCount > 0
            ? $aggregate['rating_sum'] / $ratingCount : 0;
        $weightedRating = ($aggregate['rating_sum'] + (10 * 3.5))
            / ($ratingCount + 10);
        $watchRatio = $aggregate['watch_ratio_weight'] > 0
            ? $aggregate['watch_ratio_weighted_sum'] / $aggregate['watch_ratio_weight']
            : 0;
        $score = ($weightedRating * 12)
            + min(12, log($ratingCount + 1) * 3)
            + min(18, log($aggregate['view_count'] + 1) * 3)
            + ($watchRatio * 15)
            + min(12, log($aggregate['appreciated_video_count'] + 1) * 5)
            + min(10, $aggregate['high_ranked_video_count'] * 2)
            + $this->recencyScore($aggregate['last_activity_at'], 30, 10);
        return array(
            'channel_id' => $aggregate['channel_id'],
            'title' => $aggregate['title'],
            'score' => round($score, 4),
            'rating_average' => round($ratingAverage, 4),
            'weighted_rating' => round($weightedRating, 4),
            'rating_count' => $ratingCount,
            'video_count' => $aggregate['video_count'],
            'view_count' => $aggregate['view_count'],
            'watch_ratio_average' => round($watchRatio, 4),
            'appreciated_video_count' => $aggregate['appreciated_video_count'],
            'high_ranked_video_count' => $aggregate['high_ranked_video_count'],
            'last_activity_at' => $aggregate['last_activity_at'],
            'calculated_at' => gmdate('Y-m-d\TH:i:s\Z')
        );
    }

    private function emptyAggregate($channelId)
    {
        return array(
            'channel_id' => $channelId,
            'title' => '',
            'video_count' => 0,
            'rating_count' => 0,
            'rating_sum' => 0,
            'view_count' => 0,
            'watch_ratio_weighted_sum' => 0,
            'watch_ratio_weight' => 0,
            'appreciated_video_count' => 0,
            'high_ranked_video_count' => 0,
            'last_activity_at' => null
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

    private function isMoreRecent($candidate, $current)
    {
        $candidateTime = !empty($candidate)
            ? strtotime((string) $candidate) : false;
        $currentTime = !empty($current)
            ? strtotime((string) $current) : false;
        return $candidateTime !== false &&
            ($currentTime === false || $candidateTime > $currentTime);
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
}
