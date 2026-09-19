<?php

if (!isset($GLOBALS['_CONF'])) {
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
        $before = $this->getVideoState($videoId);
        $saved = $this->setState(
            'video',
            $videoId,
            $state,
            $reason,
            $actorHash
        );
        if ($saved) {
            $beforeState = isset($before['state']) ? $before['state'] : 'neutral';
            $this->signalVideoDecision($videoId, $beforeState, $state);
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
        $before = $this->getChannelState($channelId);
        $saved = $this->setState(
            'channel',
            $channelId,
            $state,
            $reason,
            $actorHash
        );
        if ($saved) {
            $beforeState = isset($before['state']) ? $before['state'] : 'neutral';
            $this->signalChannelDecision($channelId, $beforeState, $state);
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

    private function signalVideoDecision($videoId, $beforeState, $afterState)
    {
        if ($beforeState === $afterState) {
            return;
        }

        $pool = new Videos_PermanentPool(
            $this->store,
            new Videos_Cache($this->store)
        );
        if ($pool->contains($videoId)) {
            if ($afterState === 'blocked') {
                $this->signalDeleted($videoId);
            } elseif ($beforeState === 'blocked') {
                $this->signalSaved($videoId);
            }
        }

        $this->signalSaved('catalogue');
        $this->signalSaved('rankings:videos');
    }

    private function signalChannelDecision($channelId, $beforeState, $afterState)
    {
        if ($beforeState === $afterState) {
            return;
        }

        $beforeExcluded = in_array(
            $beforeState,
            array('blocked', 'disabled'),
            true
        );
        $afterExcluded = in_array(
            $afterState,
            array('blocked', 'disabled'),
            true
        );

        if ($afterExcluded && !$beforeExcluded) {
            $this->signalDeleted('channel:' . $channelId);
        } else {
            $this->signalSaved('channel:' . $channelId);
        }

        if ($beforeExcluded !== $afterExcluded) {
            $this->signalChannelVideos($channelId, $afterExcluded);
        }

        $this->signalSaved('catalogue');
        $this->signalSaved('rankings:channels');
        $this->signalSaved('channels');
        $this->signalSaved('rankings:videos');
    }

    private function signalChannelVideos($channelId, $deleted)
    {
        $pool = new Videos_PermanentPool(
            $this->store,
            new Videos_Cache($this->store)
        );
        $records = $pool->records();
        $items = isset($records['items']) && is_array($records['items'])
            ? $records['items'] : array();
        $cache = new Videos_Cache($this->store);

        foreach ($items as $videoId => $item) {
            $video = $cache->getVideo($videoId, true);
            if (!is_array($video) || empty($video['snippet']['channelId']) ||
                (string) $video['snippet']['channelId'] !== $channelId) {
                continue;
            }
            if ($deleted) {
                $this->signalDeleted($videoId);
            } else {
                $this->signalSaved($videoId);
            }
        }
    }

    private function signalSaved($id)
    {
        if (function_exists('VIDEOS_signalSaved')) {
            VIDEOS_signalSaved($id);
        } elseif (function_exists('PLG_itemSaved')) {
            PLG_itemSaved($id, 'videos');
        }
    }

    private function signalDeleted($id)
    {
        if (function_exists('VIDEOS_signalDeleted')) {
            VIDEOS_signalDeleted($id);
        } elseif (function_exists('PLG_itemDeleted')) {
            PLG_itemDeleted($id, 'videos');
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
