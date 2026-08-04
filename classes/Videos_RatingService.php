<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_RatingService
{
    private $store;
    private $privacy;

    public function __construct($store, $privacy)
    {
        $this->store = $store;
        $this->privacy = $privacy;
    }

    public function rate($visitor, $videoId, $rating, $context)
    {
        if (!$visitor->isValid() ||
            !Videos_Validator::youtubeVideoId($videoId) ||
            !is_int($rating) || $rating < 1 || $rating > 5) {
            return false;
        }
        $subject = $visitor->getSubjectHash();
        $entry = array(
            'video_id' => $videoId,
            'rating' => $rating,
            'rated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'channel_id' => isset($context['channel_id'])
                ? substr($context['channel_id'], 0, 64) : '',
            'context_hash' => isset($context['context_hash'])
                ? substr($context['context_hash'], 0, 64) : ''
        );
        $path = 'ratings/' . substr($videoId, 0, 2) . '/'
            . $videoId . '/' . substr($subject, 0, 2) . '.json';
        $result = $this->store->update(
            $path,
            'videos.ratings',
            array('video_id' => $videoId, 'ratings' => array()),
            function ($document) use ($subject, $entry) {
                if (isset($document['data']['ratings'][$subject]['rated_at'])) {
                    $entry['first_rated_at'] =
                        $document['data']['ratings'][$subject]['rated_at'];
                } else {
                    $entry['first_rated_at'] = $entry['rated_at'];
                }
                $document['data']['ratings'][$subject] = $entry;
                return $document;
            }
        );
        if ($result === false) {
            return false;
        }

        if ($visitor->isAccount()) {
            return $this->recordAccountRating(
                $visitor->getUid(),
                $videoId,
                $entry
            );
        }
        return true;
    }

    public function getRating($visitor, $videoId)
    {
        if (!$visitor->isValid() ||
            !Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        $subject = $visitor->getSubjectHash();
        $document = $this->store->read(
            $this->ratingPath($videoId, $subject),
            'videos.ratings',
            array('video_id' => $videoId, 'ratings' => array())
        );
        if (!isset($document['data']['ratings'][$subject]['rating'])) {
            return null;
        }
        $rating = (int) $document['data']['ratings'][$subject]['rating'];
        return ($rating >= 1 && $rating <= 5) ? $rating : null;
    }

    public function remove($visitor, $videoId)
    {
        if (!$visitor->isValid() ||
            !Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        $subject = $visitor->getSubjectHash();
        $path = $this->ratingPath($videoId, $subject);
        $oldEntry = null;
        $updated = $this->store->update(
            $path,
            'videos.ratings',
            array('video_id' => $videoId, 'ratings' => array()),
            function ($document) use ($subject, &$oldEntry) {
                if (isset($document['data']['ratings'][$subject]) &&
                    is_array($document['data']['ratings'][$subject])) {
                    $oldEntry = $document['data']['ratings'][$subject];
                    unset($document['data']['ratings'][$subject]);
                }
                return $document;
            }
        );
        if ($updated === false) {
            return false;
        }

        $accountRemoved = false;
        if ($visitor->isAccount()) {
            $base = $this->privacy->accountPath($visitor->getUid());
            if ($base === false) {
                $this->restoreRating($path, $subject, $oldEntry, $videoId);
                return false;
            }
            $accountUpdated = $this->store->update(
                $base . '/ratings.json',
                'videos.user_ratings',
                array('ratings' => array()),
                function ($document) use ($videoId, &$accountRemoved) {
                    if (isset($document['data']['ratings'][$videoId])) {
                        $accountRemoved = true;
                        unset($document['data']['ratings'][$videoId]);
                    }
                    return $document;
                }
            );
            if ($accountUpdated === false) {
                $this->restoreRating($path, $subject, $oldEntry, $videoId);
                return false;
            }
            $verification = $this->store->read(
                $base . '/ratings.json',
                'videos.user_ratings',
                array('ratings' => array())
            );
            if (isset($verification['data']['ratings'][$videoId])) {
                $this->restoreRating($path, $subject, $oldEntry, $videoId);
                return false;
            }
        }

        $verification = $this->store->read(
            $path,
            'videos.ratings',
            array('video_id' => $videoId, 'ratings' => array())
        );
        if (isset($verification['data']['ratings'][$subject])) {
            return false;
        }
        return array(
            'removed' => is_array($oldEntry) || $accountRemoved
        );
    }

    private function ratingPath($videoId, $subject)
    {
        return 'ratings/' . substr($videoId, 0, 2) . '/'
            . $videoId . '/' . substr($subject, 0, 2) . '.json';
    }

    private function restoreRating($path, $subject, $entry, $videoId)
    {
        if (!is_array($entry)) {
            return true;
        }
        return $this->store->update(
            $path,
            'videos.ratings',
            array('video_id' => $videoId, 'ratings' => array()),
            function ($document) use ($subject, $entry) {
                $document['data']['ratings'][$subject] = $entry;
                return $document;
            }
        ) !== false;
    }

    private function recordAccountRating($uid, $videoId, $entry)
    {
        $base = $this->privacy->accountPath($uid);
        if ($base === false) {
            return false;
        }
        $updated = $this->store->update(
            $base . '/ratings.json',
            'videos.user_ratings',
            array('ratings' => array()),
            function ($document) use ($videoId, $entry) {
                $document['data']['ratings'][$videoId] = $entry;
                return $document;
            }
        );
        if ($updated === false) {
            return false;
        }

        $verification = $this->store->read(
            $base . '/ratings.json',
            'videos.user_ratings',
            array('ratings' => array())
        );
        return isset($verification['data']['ratings'][$videoId]['rating']) &&
            (int) $verification['data']['ratings'][$videoId]['rating'] ===
                (int) $entry['rating'];
    }
}
