<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Engagement
{
    private $store;
    private $privacy;

    public function __construct($store, $privacy)
    {
        $this->store = $store;
        $this->privacy = $privacy;
    }

    public function threshold($duration, $seconds, $percent)
    {
        $duration = max(1, (int) $duration);
        $seconds = max(1, (int) $seconds);
        $percent = max(1, min(100, (int) $percent));
        return min($seconds, (int) ceil($duration * $percent / 100));
    }

    public function recordView($visitor, $videoId, $elapsed, $duration, $context)
    {
        if (!$visitor->isValid() ||
            !Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        $elapsed = max(0, min((int) $elapsed, (int) $duration));
        $event = array(
            'video_id' => $videoId,
            'watched_seconds' => $elapsed,
            'duration_seconds' => (int) $duration,
            'watched_ratio' => round($elapsed / max(1, (int) $duration), 4),
            'qualified' => true,
            'context_hash' => isset($context['context_hash'])
                ? substr($context['context_hash'], 0, 64) : '',
            'channel_id' => isset($context['channel_id'])
                ? substr($context['channel_id'], 0, 64) : '',
            'viewed_at' => gmdate('Y-m-d\TH:i:s\Z')
        );

        if ($visitor->isAccount()) {
            return $this->recordAccountView($visitor->getUid(), $event);
        }
        return $this->recordAnonymousView(
            $visitor->getSubjectHash(),
            $event
        );
    }

    public function updateProgress($visitor, $videoId, $elapsed, $duration)
    {
        if (!$visitor->isValid() ||
            !Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        $duration = max(1, (int) $duration);
        $elapsed = max(0, min((int) $elapsed, $duration));
        if ($visitor->isAccount()) {
            $base = $this->privacy->accountPath($visitor->getUid());
            if ($base === false) {
                return false;
            }
            $month = gmdate('Y-m');
            return $this->updateProgressDocument(
                $base . '/views-' . $month . '.json',
                'videos.user_views',
                array('month' => $month, 'views' => array()),
                $videoId,
                $elapsed,
                $duration
            );
        }

        $month = gmdate('Y-m');
        $subjectHash = $visitor->getSubjectHash();
        return $this->updateProgressDocument(
            'views/' . $month . '/' . substr($subjectHash, 0, 2) . '.json',
            'videos.anonymous_views',
            array('month' => $month, 'views' => array()),
            $subjectHash . ':' . $videoId,
            $elapsed,
            $duration
        );
    }

    private function recordAccountView($uid, $event)
    {
        $base = $this->privacy->accountPath($uid);
        if ($base === false) {
            return false;
        }
        $month = gmdate('Y-m');
        $path = $base . '/views-' . $month . '.json';
        $videoId = $event['video_id'];
        $updated = $this->store->update(
            $path,
            'videos.user_views',
            array('month' => $month, 'views' => array()),
            function ($document) use ($videoId, $event) {
                $previous = isset($document['data']['views'][$videoId])
                    ? $document['data']['views'][$videoId] : array();
                $event['view_count'] = isset($previous['view_count'])
                    ? (int) $previous['view_count'] + 1 : 1;
                $document['data']['views'][$videoId] = $event;
                return $document;
            }
        );
        if ($updated === false) {
            return false;
        }

        return $this->store->update(
            $base . '/views-index.json',
            'videos.user_views_index',
            array('months' => array()),
            function ($document) use ($month) {
                $document['data']['months'][] = $month;
                $document['data']['months'] = array_values(
                    array_unique($document['data']['months'])
                );
                rsort($document['data']['months']);
                return $document;
            }
        ) !== false;
    }

    private function recordAnonymousView($subjectHash, $event)
    {
        $month = gmdate('Y-m');
        $path = 'views/' . $month . '/'
            . substr($subjectHash, 0, 2) . '.json';
        $key = $subjectHash . ':' . $event['video_id'];
        return $this->store->update(
            $path,
            'videos.anonymous_views',
            array('month' => $month, 'views' => array()),
            function ($document) use ($key, $event) {
                $previous = isset($document['data']['views'][$key])
                    ? $document['data']['views'][$key] : array();
                $event['view_count'] = isset($previous['view_count'])
                    ? (int) $previous['view_count'] + 1 : 1;
                $document['data']['views'][$key] = $event;
                return $document;
            }
        ) !== false;
    }

    private function updateProgressDocument(
        $path,
        $schema,
        $defaultData,
        $key,
        $elapsed,
        $duration
    ) {
        $change = false;
        $updated = $this->store->update(
            $path,
            $schema,
            $defaultData,
            function ($document) use (
                $key,
                $elapsed,
                $duration,
                &$change
            ) {
                if (!isset($document['data']['views'][$key]) ||
                    !is_array($document['data']['views'][$key])) {
                    return $document;
                }
                $entry = $document['data']['views'][$key];
                $previousElapsed = isset($entry['watched_seconds'])
                    ? (int) $entry['watched_seconds'] : 0;
                $previousRatio = isset($entry['watched_ratio'])
                    ? (float) $entry['watched_ratio'] : 0;
                if ($elapsed <= $previousElapsed) {
                    $change = array(
                        'updated' => false,
                        'previous_elapsed' => $previousElapsed,
                        'new_elapsed' => $previousElapsed,
                        'previous_ratio' => $previousRatio,
                        'new_ratio' => $previousRatio,
                        'duration' => $duration
                    );
                    return $document;
                }
                $newRatio = round($elapsed / $duration, 4);
                $entry['watched_seconds'] = $elapsed;
                $entry['duration_seconds'] = $duration;
                $entry['watched_ratio'] = $newRatio;
                $entry['last_progress_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $document['data']['views'][$key] = $entry;
                $change = array(
                    'updated' => true,
                    'previous_elapsed' => $previousElapsed,
                    'new_elapsed' => $elapsed,
                    'previous_ratio' => $previousRatio,
                    'new_ratio' => $newRatio,
                    'duration' => $duration
                );
                return $document;
            }
        );
        return $updated === false ? false : $change;
    }
}
