<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Cache
{
    private $store;

    public function __construct($store)
    {
        $this->store = $store;
    }

    public function getStore()
    {
        return $this->store;
    }

    public function searchKey($context)
    {
        $normalized = $this->canonicalize($context);
        return hash('sha256', json_encode($normalized));
    }

    public function getSearch($key, $allowStale)
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $key)) {
            return false;
        }
        $document = $this->store->read(
            'cache/search/' . substr($key, 0, 2) . '/' . $key . '.json',
            'videos.search_cache',
            array()
        );
        if (empty($document['data']['key']) ||
            $document['data']['key'] !== $key) {
            return false;
        }

        $now = time();
        $expires = isset($document['data']['expires_at'])
            ? strtotime($document['data']['expires_at']) : 0;
        $stale = isset($document['data']['stale_until'])
            ? strtotime($document['data']['stale_until']) : 0;
        if ($expires >= $now) {
            $document['data']['cache_state'] = 'fresh';
            return $document;
        }
        if ($allowStale && $stale >= $now) {
            $document['data']['cache_state'] = 'stale';
            return $document;
        }
        return false;
    }

    public function putSearch($key, $context, $result, $ttl, $staleTtl)
    {
        $now = time();
        $document = $this->store->createDocument(
            'videos.search_cache',
            array(
                'key' => $key,
                'context' => $this->canonicalize($context),
                'result' => $result,
                'fetched_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'expires_at' => gmdate(
                    'Y-m-d\TH:i:s\Z',
                    $now + max(300, (int) $ttl)
                ),
                'stale_until' => gmdate(
                    'Y-m-d\TH:i:s\Z',
                    $now + max((int) $ttl, (int) $staleTtl)
                )
            )
        );
        return $this->store->write(
            'cache/search/' . substr($key, 0, 2) . '/' . $key . '.json',
            'videos.search_cache',
            $document
        );
    }

    public function findCompatibleSearch($context, $limit)
    {
        if (!is_array($context) || empty($context['query']) ||
            !isset($context['parameters']) ||
            !is_array($context['parameters'])) {
            return false;
        }
        $root = $this->store->getRoot() . 'cache'
            . DIRECTORY_SEPARATOR . 'search';
        if (!is_dir($root)) {
            return false;
        }
        $limit = max(1, min(500, (int) $limit));
        $inspected = 0;
        $best = false;
        $bestTime = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $root,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $fileInfo) {
                if ($inspected >= $limit) {
                    break;
                }
                if (!$fileInfo->isFile() || $fileInfo->isLink() ||
                    substr($fileInfo->getFilename(), -5) !== '.json') {
                    continue;
                }
                $inspected++;
                $absolute = $fileInfo->getPathname();
                if (strpos($absolute, $this->store->getRoot()) !== 0) {
                    continue;
                }
                $relative = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    substr($absolute, strlen($this->store->getRoot()))
                );
                $document = $this->store->read(
                    $relative,
                    'videos.search_cache',
                    array()
                );
                if (!isset($document['data']['context']) ||
                    !is_array($document['data']['context']) ||
                    !$this->compatibleSearchContext(
                        $context,
                        $document['data']['context']
                    )) {
                    continue;
                }
                $staleUntil = isset($document['data']['stale_until'])
                    ? strtotime($document['data']['stale_until']) : false;
                if ($staleUntil === false || $staleUntil < time()) {
                    continue;
                }
                $fetched = isset($document['data']['fetched_at'])
                    ? strtotime($document['data']['fetched_at']) : false;
                $fetched = $fetched === false ? 0 : $fetched;
                if ($best === false || $fetched > $bestTime) {
                    $best = $document;
                    $bestTime = $fetched;
                }
            }
        } catch (UnexpectedValueException $exception) {
            return $best;
        }
        if ($best !== false) {
            $best['data']['cache_state'] = 'compatible';
        }
        return $best;
    }

    public function getVideo($videoId, $allowStale)
    {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        return $this->getResource(
            'videos',
            $videoId,
            'videos.video_cache',
            $allowStale
        );
    }

    public function putVideo($videoId, $video, $ttl, $staleTtl)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) || !is_array($video)) {
            return false;
        }
        return $this->putResource(
            'videos',
            $videoId,
            'videos.video_cache',
            $video,
            $ttl,
            $staleTtl
        );
    }

    public function getChannel($channelId, $allowStale)
    {
        if (!Videos_Validator::youtubeChannelId($channelId)) {
            return false;
        }
        return $this->getResource(
            'channels',
            $channelId,
            'videos.channel_cache',
            $allowStale
        );
    }

    public function putChannel($channelId, $channel, $ttl, $staleTtl)
    {
        if (!Videos_Validator::youtubeChannelId($channelId) ||
            !is_array($channel)) {
            return false;
        }
        return $this->putResource(
            'channels',
            $channelId,
            'videos.channel_cache',
            $channel,
            $ttl,
            $staleTtl
        );
    }

    public function listKnownChannels($limit)
    {
        $root = $this->store->getRoot() . 'cache'
            . DIRECTORY_SEPARATOR . 'channels';
        if (!is_dir($root)) {
            return array();
        }
        $limit = max(1, min(500, (int) $limit));
        $channels = array();
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
                $channelId = substr($fileInfo->getFilename(), 0, -5);
                if (!Videos_Validator::youtubeChannelId($channelId)) {
                    continue;
                }
                $document = $this->store->read(
                    'cache/channels/' . substr($channelId, 0, 2)
                        . '/' . $channelId . '.json',
                    'videos.channel_cache',
                    array()
                );
                $resource = isset($document['data']['resource']) &&
                    is_array($document['data']['resource'])
                    ? $document['data']['resource'] : array();
                $title = isset($resource['snippet']['title'])
                    ? trim((string) $resource['snippet']['title']) : '';
                if ($title === '') {
                    $title = $channelId;
                }
                $channels[$channelId] = array(
                    'channel_id' => $channelId,
                    'title' => $title
                );
                if (count($channels) >= $limit) {
                    break;
                }
            }
        } catch (UnexpectedValueException $exception) {
            return array_values($channels);
        }
        $channels = array_values($channels);
        usort($channels, array($this, 'compareKnownChannels'));
        return $channels;
    }

    public function compareKnownChannels($left, $right)
    {
        $leftTitle = isset($left['title']) ? $left['title'] : '';
        $rightTitle = isset($right['title']) ? $right['title'] : '';
        return strcasecmp($leftTitle, $rightTitle);
    }

    public function getAvailability($videoId)
    {
        if (!Videos_Validator::youtubeVideoId($videoId)) {
            return false;
        }
        $document = $this->store->read(
            'cache/availability/' . substr($videoId, 0, 2)
                . '/' . $videoId . '.json',
            'videos.video_availability',
            array()
        );
        if (!isset($document['data']['video_id']) ||
            $document['data']['video_id'] !== $videoId ||
            !array_key_exists('available', $document['data'])) {
            return false;
        }
        return $document['data'];
    }

    public function putAvailability($videoId, $available, $reason, $ttl)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) ||
            !is_bool($available)) {
            return false;
        }
        $reason = preg_replace(
            '/[^a-z0-9_]+/',
            '_',
            strtolower((string) $reason)
        );
        $reason = trim(substr($reason, 0, 60), '_');
        $now = time();
        $document = $this->store->createDocument(
            'videos.video_availability',
            array(
                'video_id' => $videoId,
                'available' => $available,
                'reason' => $reason,
                'checked_at' => gmdate('Y-m-d\TH:i:s\Z', $now),
                'expires_at' => gmdate(
                    'Y-m-d\TH:i:s\Z',
                    $now + max(300, (int) $ttl)
                )
            )
        );
        return $this->store->write(
            'cache/availability/' . substr($videoId, 0, 2)
                . '/' . $videoId . '.json',
            'videos.video_availability',
            $document
        );
    }

    public function isVideoUnavailable($videoId)
    {
        $availability = $this->getAvailability($videoId);
        return is_array($availability) &&
            isset($availability['available']) &&
            $availability['available'] === false;
    }

    private function getResource($type, $id, $schema, $allowStale)
    {
        $path = 'cache/' . $type . '/' . substr($id, 0, 2)
            . '/' . $id . '.json';
        $document = $this->store->read($path, $schema, array());
        if (!isset($document['data']['resource'])) {
            return false;
        }
        $now = time();
        $expires = isset($document['data']['expires_at'])
            ? strtotime($document['data']['expires_at']) : 0;
        $stale = isset($document['data']['stale_until'])
            ? strtotime($document['data']['stale_until']) : 0;
        if ($expires >= $now || ($allowStale && $stale >= $now)) {
            return $document['data']['resource'];
        }
        return false;
    }

    private function putResource($type, $id, $schema, $resource, $ttl, $staleTtl)
    {
        $now = time();
        $document = $this->store->createDocument(
            $schema,
            array(
                'resource' => $resource,
                'expires_at' => gmdate(
                    'Y-m-d\TH:i:s\Z',
                    $now + max(300, (int) $ttl)
                ),
                'stale_until' => gmdate(
                    'Y-m-d\TH:i:s\Z',
                    $now + max((int) $ttl, (int) $staleTtl)
                )
            )
        );
        return $this->store->write(
            'cache/' . $type . '/' . substr($id, 0, 2)
                . '/' . $id . '.json',
            $schema,
            $document
        );
    }

    private function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $isList = array_keys($value) === range(0, count($value) - 1);
        if ($isList) {
            $result = array();
            foreach ($value as $item) {
                $result[] = $this->canonicalize($item);
            }
            return $result;
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        return $value;
    }

    private function compatibleSearchContext($requested, $candidate)
    {
        if (!isset($candidate['query'], $candidate['parameters']) ||
            !is_array($candidate['parameters']) ||
            (string) $requested['query'] !== (string) $candidate['query']) {
            return false;
        }
        $remoteKeys = array(
            'order',
            'safe_search',
            'language',
            'region',
            'published_after',
            'category_id',
            'channel_id'
        );
        foreach ($remoteKeys as $key) {
            $requestedValue = isset($requested['parameters'][$key])
                ? (string) $requested['parameters'][$key] : '';
            $candidateValue = isset($candidate['parameters'][$key])
                ? (string) $candidate['parameters'][$key] : '';
            if ($requestedValue !== $candidateValue) {
                return false;
            }
        }
        return true;
    }
}
