<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_RatingStats
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function rebuild($videoId)
    {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        $relativeDirectory = 'ratings/' . substr($videoId, 0, 2)
            . '/' . $videoId;
        $absoluteDirectory = $this->store->getRoot()
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        $ratings = array();

        if (is_dir($absoluteDirectory)) {
            $files = glob(
                $absoluteDirectory . DIRECTORY_SEPARATOR . '*.json'
            );
            if (is_array($files)) {
                foreach ($files as $file) {
                    $name = basename($file);
                    if (!preg_match('/^[a-f0-9]{2}\.json$/', $name)) {
                        continue;
                    }
                    $document = $this->store->read(
                        $relativeDirectory . '/' . $name,
                        'videos.ratings',
                        array('video_id' => $videoId, 'ratings' => array())
                    );
                    if (!isset($document['data']['ratings']) ||
                        !is_array($document['data']['ratings'])) {
                        continue;
                    }
                    foreach ($document['data']['ratings'] as $subject => $entry) {
                        if (!preg_match('/^[a-f0-9]{64}$/', $subject) ||
                            !isset($entry['rating'])) {
                            continue;
                        }
                        $value = (int) $entry['rating'];
                        if ($value >= 1 && $value <= 5) {
                            $ratings[$subject] = $value;
                        }
                    }
                }
            }
        }

        $count = count($ratings);
        $sum = array_sum($ratings);
        $average = $count > 0 ? round($sum / $count, 2) : 0;
        $document = $this->store->createDocument(
            'videos.video_rating_stats',
            array(
                'video_id' => $videoId,
                'rating_sum' => $sum,
                'rating_count' => $count,
                'rating_average' => $average,
                'rebuilt_at' => gmdate('Y-m-d\TH:i:s\Z')
            )
        );
        if (!$this->store->write(
            'stats/videos/' . substr($videoId, 0, 2)
                . '/' . $videoId . '.json',
            'videos.video_rating_stats',
            $document
        )) {
            return false;
        }
        return $document['data'];
    }

    public function get($videoId)
    {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            return $this->emptyStats($videoId);
        }
        $document = $this->store->read(
            'stats/videos/' . substr($videoId, 0, 2)
                . '/' . $videoId . '.json',
            'videos.video_rating_stats',
            $this->emptyStats($videoId)
        );
        $stats = isset($document['data']) && is_array($document['data'])
            ? $document['data'] : $this->emptyStats($videoId);

        /*
         * Version 0.3.0 introduced aggregate rating files. Rebuild an aggregate
         * once, on demand, when ratings written by an older version are found.
         */
        $relativeDirectory = 'ratings/' . substr($videoId, 0, 2)
            . '/' . $videoId;
        $absoluteDirectory = $this->store->getRoot()
            . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        if (empty($stats['rebuilt_at']) && is_dir($absoluteDirectory)) {
            $rebuilt = $this->rebuild($videoId);
            if (is_array($rebuilt)) {
                return $rebuilt;
            }
        }

        return $stats;
    }

    private function emptyStats($videoId)
    {
        return array(
            'video_id' => $videoId,
            'rating_sum' => 0,
            'rating_count' => 0,
            'rating_average' => 0,
            'rebuilt_at' => null
        );
    }
}
