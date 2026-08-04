<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_CatalogueSelector
{
    private $ratingStats;
    private $privacy;
    private $ranking;

    public function __construct($ratingStats, $privacy, $ranking = null)
    {
        $this->ratingStats = $ratingStats;
        $this->privacy = $privacy;
        $this->ranking = $ranking;
    }

    public function select($videos, $query, $configuration, $uid)
    {
        if (!is_array($videos)) {
            return array('videos' => array(), 'metadata' => array());
        }

        $viewed = $this->viewedSet($uid);
        $excludeViewed = !empty($configuration['_exclude_viewed']);
        $globalRanking = is_object($this->ranking)
            ? $this->ranking->getGlobal(500) : array();
        $priority = $this->listSet(
            isset($configuration['priority_channels'])
                ? $configuration['priority_channels'] : ''
        );
        $maximumPerChannel = isset($configuration['max_same_channel'])
            ? max(0, (int) $configuration['max_same_channel']) : 2;
        $bucket = (int) floor(time() / 21600);
        $ranked = array();
        $position = 0;

        foreach ($videos as $videoId => $video) {
            if (!Videos_Validator::youtubeVideoId($videoId) ||
                !is_array($video) ||
                Videos_VideoPolicy::excludesShortVideo(
                    $video,
                    $configuration
                )) {
                continue;
            }
            $channelId = isset($video['snippet']['channelId'])
                ? (string) $video['snippet']['channelId'] : '';
            $stats = $this->ratingStats->get($videoId);
            $ratingCount = isset($stats['rating_count'])
                ? max(0, (int) $stats['rating_count']) : 0;
            $ratingAverage = isset($stats['rating_average'])
                ? max(0, min(5, (float) $stats['rating_average'])) : 0;
            $weightedRating = (
                ($ratingCount * $ratingAverage) + (5 * 3.5)
            ) / ($ratingCount + 5);
            $wasViewed = isset($viewed[$videoId]);
            if ($excludeViewed && $wasViewed) {
                $position++;
                continue;
            }
            $relevanceScore = max(0, 100 - ($position * 4));
            $ratingScore = ($weightedRating - 3) * 12;
            $confidenceScore = min(10, log($ratingCount + 1) * 4);
            $historyScore = $wasViewed ? -35 : 18;
            $priorityScore = isset($priority[$channelId]) ? 15 : 0;
            $globalScore = isset($globalRanking[$videoId]['score'])
                ? (float) $globalRanking[$videoId]['score'] : 42;
            $globalRankingScore = max(-10, min(
                20,
                ($globalScore - 42) * 0.35
            ));
            $jitterHash = substr(
                hash('sha256', $bucket . '|' . $query . '|' . $videoId),
                0,
                4
            );
            $rotationScore = (hexdec($jitterHash) / 65535) * 12;
            $score = $relevanceScore + $ratingScore + $confidenceScore
                + $historyScore + $priorityScore + $rotationScore
                + $globalRankingScore;

            $ranked[] = array(
                'video_id' => $videoId,
                'video' => $video,
                'channel_id' => $channelId,
                'score' => round($score, 4),
                'original_position' => $position,
                'viewed' => $wasViewed,
                'rating_average' => $ratingAverage,
                'rating_count' => $ratingCount,
                'global_score' => round($globalScore, 4)
            );
            $position++;
        }

        usort($ranked, array($this, 'compareCandidates'));
        $selected = array();
        $metadata = array();
        $lastChannel = null;
        $consecutive = 0;
        while (count($ranked) > 0) {
            $chosenIndex = null;
            foreach ($ranked as $index => $candidate) {
                $channelId = $candidate['channel_id'];
                if ($maximumPerChannel <= 0 || $channelId !== $lastChannel ||
                    $consecutive < $maximumPerChannel) {
                    $chosenIndex = $index;
                    break;
                }
            }
            if ($chosenIndex === null) {
                $chosenIndex = 0;
            }
            $candidate = $ranked[$chosenIndex];
            array_splice($ranked, $chosenIndex, 1);
            $channelId = $candidate['channel_id'];
            $videoId = $candidate['video_id'];
            $selected[$videoId] = $candidate['video'];
            $metadata[$videoId] = array(
                'score' => $candidate['score'],
                'viewed' => $candidate['viewed'],
                'rating_average' => $candidate['rating_average'],
                'rating_count' => $candidate['rating_count'],
                'global_score' => $candidate['global_score']
            );
            if ($channelId === $lastChannel) {
                $consecutive++;
            } else {
                $lastChannel = $channelId;
                $consecutive = 1;
            }
        }

        return array('videos' => $selected, 'metadata' => $metadata);
    }

    public function compareCandidates($left, $right)
    {
        if ($left['score'] == $right['score']) {
            return $left['original_position'] - $right['original_position'];
        }
        return ($left['score'] > $right['score']) ? -1 : 1;
    }

    private function viewedSet($uid)
    {
        $set = array();
        if (!Videos_Validator::accountUid($uid)) {
            return $set;
        }
        $history = $this->privacy->accountHistory((int) $uid);
        if (!is_array($history) || !isset($history['views']) ||
            !is_array($history['views'])) {
            return $set;
        }
        foreach ($history['views'] as $videoId => $unused) {
            if (Videos_Validator::youtubeVideoId($videoId)) {
                $set[$videoId] = true;
            }
        }
        return $set;
    }

    private function listSet($value)
    {
        $items = preg_split(
            '/[\s,;]+/',
            (string) $value,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        $set = array();
        foreach ($items as $item) {
            $set[trim($item)] = true;
        }
        return $set;
    }
}
