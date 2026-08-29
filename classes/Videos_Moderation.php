<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Moderation
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function setVideoState($videoId, $state, $reason, $actorHash)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) ||
            !in_array($state, array('neutral', 'blocked'), true)) {
            return false;
        }
        $saved = $this->setState(
            'video',
            $videoId,
            $state,
            $reason,
            $actorHash
        );
        if ($saved) {
            $this->signalVideoDecision($videoId);
        }
        return $saved;
    }

    public function setChannelState($channelId, $state, $reason, $actorHash)
    {
        $allowed = array(
            'neutral',
            'allowed',
            'priority',
            'blocked',
            'disabled'
        );
        if (!Videos_Validator::youtubeChannelId($channelId) ||
            !in_array($state, $allowed, true)) {
            return false;
        }
        $saved = $this->setState(
            'channel',
            $channelId,
            $state,
            $reason,
            $actorHash
        );
        if ($saved) {
            $this->signalChannelDecision($channelId);
        }
        return $saved;
    }

    public function getVideoState($videoId)
    {
        return $this->getState('video', $videoId);
    }

    public function getChannelState($channelId)
    {
        return $this->getState('channel', $channelId);
    }

    public function isVideoBlocked($videoId)
    {
        $record = $this->getVideoState($videoId);
        return isset($record['state']) && $record['state'] === 'blocked';
    }

    public function isChannelExcluded($channelId)
    {
        $record = $this->getChannelState($channelId);
        return isset($record['state']) &&
            in_array($record['state'], array('blocked', 'disabled'), true);
    }

    public function getPriorityChannelIds($limit)
    {
        $records = $this->listRecords('channel', max(1, (int) $limit));
        $ids = array();
        foreach ($records as $record) {
            if (isset($record['state'], $record['id']) &&
                $record['state'] === 'priority' &&
                Videos_Validator::youtubeChannelId($record['id'])) {
                $ids[] = $record['id'];
            }
        }
        return $ids;
    }

    public function listRecords($type, $limit)
    {
        if (!in_array($type, array('video', 'channel'), true)) {
            return array();
        }
        $directoryName = $type === 'video' ? 'videos' : 'channels';
        $root = $this->store->getRoot() . 'moderation'
            . DIRECTORY_SEPARATOR . $directoryName;
        if (!is_dir($root)) {
            return array();
        }
        $records = array();
        $limit = max(1, min(2000, (int) $limit));
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->isLink() ||
                    substr($fileInfo->getFilename(), -5) !== '.json') {
                    continue;
                }
                $id = substr($fileInfo->getFilename(), 0, -5);
                $record = $this->getState($type, $id);
                if (!empty($record['state']) &&
                    $record['state'] !== 'neutral') {
                    $records[] = $record;
                }
                if (count($records) >= $limit) {
                    break;
                }
            }
        } catch (UnexpectedValueException $exception) {
            return $records;
        }
        usort($records, array($this, 'compareRecords'));
        return $records;
    }

    public function compareRecords($left, $right)
    {
        return strcmp(
            isset($right['set_at']) ? $right['set_at'] : '',
            isset($left['set_at']) ? $left['set_at'] : ''
        );
    }

    private function signalVideoDecision($videoId)
    {
        if (!function_exists('VIDEOS_signalSaved')) {
            return;
        }
        VIDEOS_signalSaved($videoId);
        VIDEOS_signalSaved('catalogue');
        VIDEOS_signalSaved('rankings:videos');
    }

    private function signalChannelDecision($channelId)
    {
        if (!function_exists('VIDEOS_signalSaved')) {
            return;
        }
        VIDEOS_signalSaved('channel:' . $channelId);
        VIDEOS_signalSaved('catalogue');
        VIDEOS_signalSaved('rankings:channels');
        VIDEOS_signalSaved('rankings:videos');

        if (!class_exists('Videos_Ranking') ||
            !class_exists('Videos_RatingStats') ||
            !class_exists('Videos_VideoStats') ||
            !class_exists('Videos_Cache')) {
            return;
        }
        $cache = new Videos_Cache($this->store);
        $ranking = new Videos_Ranking(
            $this->store,
            new Videos_RatingStats($this->store),
            new Videos_VideoStats($this->store),
            $cache
        );
        foreach ($ranking->getGlobal(500) as $videoId => $item) {
            if (isset($item['channel_id']) &&
                (string) $item['channel_id'] === $channelId) {
                VIDEOS_signalSaved($videoId);
            }
        }
    }

    private function setState($type, $id, $state, $reason, $actorHash)
    {
        $path = $this->path($type, $id);
        if ($path === false) {
            return false;
        }
        if ($state === 'neutral') {
            $deleted = $this->store->delete($path);
            return $deleted !== false;
        }
        $reason = trim(strip_tags((string) $reason));
        if (function_exists('MBYTE_substr')) {
            $reason = MBYTE_substr($reason, 0, 250);
        } else {
            $reason = substr($reason, 0, 250);
        }
        $actorHash = is_string($actorHash) &&
            preg_match('/^[a-f0-9]{64}$/', $actorHash)
            ? $actorHash : '';
        $document = $this->store->createDocument(
            'videos.moderation_record',
            array(
                'entity' => $type,
                'id' => $id,
                'state' => $state,
                'reason' => $reason,
                'set_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'actor_hash' => $actorHash
            )
        );
        return $this->store->write(
            $path,
            'videos.moderation_record',
            $document
        );
    }

    private function getState($type, $id)
    {
        $path = $this->path($type, $id);
        if ($path === false) {
            return array('state' => 'neutral');
        }
        $document = $this->store->read(
            $path,
            'videos.moderation_record',
            array(
                'entity' => $type,
                'id' => $id,
                'state' => 'neutral',
                'reason' => '',
                'set_at' => null,
                'actor_hash' => ''
            )
        );
        return isset($document['data']) && is_array($document['data'])
            ? $document['data'] : array('state' => 'neutral');
    }

    private function path($type, $id)
    {
        if ($type === 'video' &&
            Videos_Validator::youtubeVideoId($id)) {
            return 'moderation/videos/' . substr($id, 0, 2)
                . '/' . $id . '.json';
        }
        if ($type === 'channel' &&
            Videos_Validator::youtubeChannelId($id)) {
            return 'moderation/channels/' . substr($id, 0, 2)
                . '/' . $id . '.json';
        }
        return false;
    }
}
