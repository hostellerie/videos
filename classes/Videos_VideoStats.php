<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_VideoStats
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function recordView($videoId, $elapsed, $duration, $channelId)
    {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        $duration = max(1, (int) $duration);
        $elapsed = max(0, min((int) $elapsed, $duration));
        $ratio = $elapsed / $duration;
        $path = $this->path($videoId);

        return $this->store->update(
            $path,
            'videos.video_engagement_stats',
            $this->emptyStats($videoId),
            function ($document) use (
                $elapsed,
                $duration,
                $ratio,
                $channelId
            ) {
                $data = isset($document['data']) &&
                    is_array($document['data'])
                    ? $document['data'] : array();
                $views = isset($data['view_count'])
                    ? (int) $data['view_count'] : 0;
                $ratioSum = isset($data['watch_ratio_sum'])
                    ? (float) $data['watch_ratio_sum'] : 0;
                $views++;
                $ratioSum += $ratio;
                $data['view_count'] = $views;
                $data['watch_seconds_total'] =
                    (isset($data['watch_seconds_total'])
                        ? (int) $data['watch_seconds_total'] : 0) + $elapsed;
                $data['duration_seconds_total'] =
                    (isset($data['duration_seconds_total'])
                        ? (int) $data['duration_seconds_total'] : 0) + $duration;
                $data['watch_ratio_sum'] = round($ratioSum, 6);
                $data['watch_ratio_average'] = round($ratioSum / $views, 4);
                $data['completion_count'] =
                    (isset($data['completion_count'])
                        ? (int) $data['completion_count'] : 0)
                    + ($ratio >= 0.9 ? 1 : 0);
                $data['channel_id'] = substr((string) $channelId, 0, 64);
                $data['last_viewed_at'] = gmdate('Y-m-d\TH:i:s\Z');
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
            'videos.video_engagement_stats',
            $this->emptyStats($videoId)
        );
        return isset($document['data']) && is_array($document['data'])
            ? $document['data'] : $this->emptyStats($videoId);
    }

    public function refineProgress($videoId, $change)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) ||
            !is_array($change) || empty($change['updated'])) {
            return false;
        }
        return $this->store->update(
            $this->path($videoId),
            'videos.video_engagement_stats',
            $this->emptyStats($videoId),
            function ($document) use ($change) {
                $data = $document['data'];
                $existingViews = isset($data['view_count'])
                    ? (int) $data['view_count'] : 0;
                if ($existingViews <= 0) {
                    $data['view_count'] = 1;
                    $data['watch_seconds_total'] =
                        (int) $change['new_elapsed'];
                    $data['duration_seconds_total'] =
                        isset($change['duration'])
                            ? (int) $change['duration'] : 0;
                    $data['watch_ratio_sum'] =
                        round((float) $change['new_ratio'], 6);
                    $data['watch_ratio_average'] =
                        round((float) $change['new_ratio'], 4);
                    $data['completion_count'] =
                        (float) $change['new_ratio'] >= 0.9 ? 1 : 0;
                    $data['last_completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
                    $document['data'] = $data;
                    return $document;
                }
                $views = $existingViews;
                $elapsedDelta = max(
                    0,
                    (int) $change['new_elapsed']
                        - (int) $change['previous_elapsed']
                );
                $ratioDelta = max(
                    0,
                    (float) $change['new_ratio']
                        - (float) $change['previous_ratio']
                );
                $data['watch_seconds_total'] =
                    (isset($data['watch_seconds_total'])
                        ? (int) $data['watch_seconds_total'] : 0)
                    + $elapsedDelta;
                $data['watch_ratio_sum'] = round(
                    (isset($data['watch_ratio_sum'])
                        ? (float) $data['watch_ratio_sum'] : 0)
                    + $ratioDelta,
                    6
                );
                $data['watch_ratio_average'] = round(
                    $data['watch_ratio_sum'] / $views,
                    4
                );
                if ((float) $change['previous_ratio'] < 0.9 &&
                    (float) $change['new_ratio'] >= 0.9) {
                    $data['completion_count'] =
                        (isset($data['completion_count'])
                            ? (int) $data['completion_count'] : 0) + 1;
                }
                $data['last_completed_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $document['data'] = $data;
                return $document;
            }
        );
    }

    private function path($videoId)
    {
        return 'stats/engagement/videos/' . substr($videoId, 0, 2)
            . '/' . $videoId . '.json';
    }

    private function emptyStats($videoId)
    {
        return array(
            'video_id' => $videoId,
            'channel_id' => '',
            'view_count' => 0,
            'watch_seconds_total' => 0,
            'duration_seconds_total' => 0,
            'watch_ratio_sum' => 0,
            'watch_ratio_average' => 0,
            'completion_count' => 0,
            'last_viewed_at' => null,
            'last_completed_at' => null
        );
    }
}
