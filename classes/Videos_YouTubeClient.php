<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_YouTubeClient
{
    private $apiKey;
    private $http;
    private $lastError;

    public function __construct($apiKey, $timeout)
    {
        $this->apiKey = trim((string) $apiKey);
        $this->http = new Videos_HttpClient($timeout);
        $this->lastError = array();
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function search($query, $parameters)
    {
        if (!$this->hasKey()) {
            return $this->fail('missing_api_key', 0, 'YouTube API key is missing.');
        }
        $query = trim((string) $query);
        if ($query === '' || strlen($query) > 300) {
            return $this->fail('invalid_query', 0, 'Invalid search query.');
        }

        $allowedOrder = array('date', 'rating', 'relevance', 'title', 'viewCount');
        $allowedSafe = array('none', 'moderate', 'strict');
        $arguments = array(
            'part' => 'snippet',
            'type' => 'video',
            'q' => $query,
            'maxResults' => max(1, min(50, (int) $parameters['max_results'])),
            'order' => in_array($parameters['order'], $allowedOrder, true)
                ? $parameters['order'] : 'relevance',
            'safeSearch' => in_array($parameters['safe_search'], $allowedSafe, true)
                ? $parameters['safe_search'] : 'moderate',
            'videoEmbeddable' => 'true',
            'videoSyndicated' => 'true'
        );
        if (!empty($parameters['language'])) {
            $arguments['relevanceLanguage'] = $parameters['language'];
        }
        if (!empty($parameters['region'])) {
            $arguments['regionCode'] = $parameters['region'];
        }
        if (!empty($parameters['published_after'])) {
            $arguments['publishedAfter'] = $parameters['published_after'];
        }
        if (!empty($parameters['category_id'])) {
            $arguments['videoCategoryId'] = $parameters['category_id'];
        }
        if (!empty($parameters['channel_id']) &&
            Videos_Validator::youtubeChannelId($parameters['channel_id'])) {
            $arguments['channelId'] = $parameters['channel_id'];
        }

        $response = $this->request('search', $arguments);
        if ($response === false) {
            return false;
        }

        $items = array();
        if (!isset($response['items']) || !is_array($response['items'])) {
            return $items;
        }
        foreach ($response['items'] as $item) {
            if (isset($item['id']['videoId']) &&
                Videos_Validator::youtubeVideoId($item['id']['videoId'])) {
                $items[] = $item['id']['videoId'];
            }
        }
        return array_values(array_unique($items));
    }

    public function videos($ids)
    {
        $ids = $this->validVideoIds($ids);
        if (count($ids) === 0) {
            return array();
        }
        $result = array();
        foreach (array_chunk($ids, 50) as $chunk) {
            $response = $this->request('videos', array(
                'part' => 'snippet,contentDetails,status,statistics',
                'id' => implode(',', $chunk),
                'maxResults' => count($chunk)
            ));
            if ($response === false) {
                return false;
            }
            if (isset($response['items']) && is_array($response['items'])) {
                foreach ($response['items'] as $item) {
                    if (isset($item['id']) &&
                        Videos_Validator::youtubeVideoId($item['id'])) {
                        $result[$item['id']] = $item;
                    }
                }
            }
        }
        return $result;
    }

    public function channels($ids)
    {
        $valid = array();
        foreach ((array) $ids as $id) {
            if (Videos_Validator::youtubeChannelId($id)) {
                $valid[$id] = true;
            }
        }
        $result = array();
        foreach (array_chunk(array_keys($valid), 50) as $chunk) {
            $response = $this->request('channels', array(
                'part' => 'snippet,statistics,status',
                'id' => implode(',', $chunk),
                'maxResults' => count($chunk)
            ));
            if ($response === false) {
                return false;
            }
            if (isset($response['items']) && is_array($response['items'])) {
                foreach ($response['items'] as $item) {
                    if (isset($item['id']) &&
                        Videos_Validator::youtubeChannelId($item['id'])) {
                        $result[$item['id']] = $item;
                    }
                }
            }
        }
        return $result;
    }

    private function request($endpoint, $arguments)
    {
        if (!$this->hasKey()) {
            return $this->fail('missing_api_key', 0, 'YouTube API key is missing.');
        }
        $arguments['key'] = $this->apiKey;
        $url = 'https://www.googleapis.com/youtube/v3/' . $endpoint
            . '?' . http_build_query($arguments, '', '&');
        $response = $this->http->getJson($url);
        if ($response === false) {
            $this->lastError = $this->http->getLastError();
            return false;
        }
        $this->lastError = array();
        return $response;
    }

    private function hasKey()
    {
        return $this->apiKey !== '' &&
            strlen($this->apiKey) >= 20 &&
            strlen($this->apiKey) <= 200;
    }

    private function validVideoIds($ids)
    {
        $valid = array();
        foreach ((array) $ids as $id) {
            if (Videos_Validator::youtubeVideoId($id)) {
                $valid[$id] = true;
            }
        }
        return array_keys($valid);
    }

    private function fail($code, $status, $message)
    {
        $this->lastError = array(
            'code' => $code,
            'http_status' => (int) $status,
            'message' => $message
        );
        return false;
    }
}

