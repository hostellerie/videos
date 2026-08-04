<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_RecommendationStats
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function record($videoId, $signal, $sourceVideoId)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) ||
            !in_array($signal, array('accepted', 'skipped'), true) ||
            !Videos_Validator::youtubeVideoId($sourceVideoId) ||
            $videoId === $sourceVideoId) {
            return false;
        }
        return $this->store->update(
            $this->path($videoId),
            'videos.video_recommendation_stats',
            $this->emptyStats($videoId),
            function ($document) use ($signal, $sourceVideoId) {
                $data = isset($document['data']) &&
                    is_array($document['data'])
                    ? $document['data'] : array();
                $key = $signal === 'accepted'
                    ? 'accepted_count' : 'skipped_count';
                $data[$key] = isset($data[$key])
                    ? (int) $data[$key] + 1 : 1;
                $data['last_signal_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $data['last_source_video_id'] = $sourceVideoId;
                $document['data'] = $data;
                return $document;
            }
        );
    }

    public function get($videoId)
    {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            return $this->emptyStats($videoId);
        }
        $document = $this->store->read(
            $this->path($videoId),
            'videos.video_recommendation_stats',
            $this->emptyStats($videoId)
        );
        return isset($document['data']) && is_array($document['data'])
            ? $document['data'] : $this->emptyStats($videoId);
    }

    private function path($videoId)
    {
        return 'stats/recommendations/videos/' . substr($videoId, 0, 2)
            . '/' . $videoId . '.json';
    }

    private function emptyStats($videoId)
    {
        return array(
            'video_id' => $videoId,
            'accepted_count' => 0,
            'skipped_count' => 0,
            'last_signal_at' => null,
            'last_source_video_id' => ''
        );
    }
}
