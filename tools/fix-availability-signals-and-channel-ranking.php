<?php
$root = dirname(__DIR__);

function read_text($path)
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    return $content;
}

function write_text($path, $content)
{
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

// Add a deleted lifecycle helper next to VIDEOS_signalSaved().
$interopPath = $root . '/interoperability.php';
$interop = read_text($interopPath);
if (strpos($interop, 'function VIDEOS_signalDeleted(') === false) {
    $needle = <<<'PHP'
function VIDEOS_signalSaved($id)
{
    if (function_exists('PLG_itemSaved')) {
        PLG_itemSaved($id, 'videos');
    }
}
PHP;
    $replacement = $needle . <<<'PHP'

function VIDEOS_signalDeleted($id)
{
    if (function_exists('PLG_itemDeleted')) {
        PLG_itemDeleted($id, 'videos');
    }
}
PHP;
    if (strpos($interop, $needle) === false) {
        throw new RuntimeException('VIDEOS_signalSaved block not found');
    }
    $interop = str_replace($needle, $replacement, $interop);
    write_text($interopPath, $interop);
}

// Signal only real available -> unavailable transitions.
$servicePath = $root . '/classes/Videos_YouTubeService.php';
$service = read_text($servicePath);
$old = <<<'PHP'
    private function recordAvailability($ids, $videos, $ttl)
    {
        foreach ((array) $ids as $videoId) {
            if (!Videos_Validator::youtubeVideoId($videoId)) {
                continue;
            }
            if (!isset($videos[$videoId]) ||
                !is_array($videos[$videoId])) {
                $this->cache->putAvailability(
                    $videoId,
                    false,
                    'not_found_or_private',
                    $ttl
                );
                continue;
            }
            $status = isset($videos[$videoId]['status']) &&
                is_array($videos[$videoId]['status'])
                ? $videos[$videoId]['status'] : array();
            $public = isset($status['privacyStatus']) &&
                $status['privacyStatus'] === 'public';
            $embeddable = !empty($status['embeddable']);
            $reason = 'available';
            if (!$public) {
                $reason = 'not_public';
            } elseif (!$embeddable) {
                $reason = 'not_embeddable';
            }
            $this->cache->putAvailability(
                $videoId,
                $public && $embeddable,
                $reason,
                $ttl
            );
        }
    }
PHP;
$new = <<<'PHP'
    private function recordAvailability($ids, $videos, $ttl)
    {
        foreach ((array) $ids as $videoId) {
            if (!Videos_Validator::youtubeVideoId($videoId)) {
                continue;
            }
            $previous = $this->cache->getAvailability($videoId);
            $wasAvailable = is_array($previous) &&
                isset($previous['available']) && $previous['available'] === true;
            $cachedVideo = $this->cache->getVideo($videoId, true);
            $channelId = is_array($cachedVideo) && !empty($cachedVideo['snippet']['channelId'])
                ? (string) $cachedVideo['snippet']['channelId'] : '';

            if (!isset($videos[$videoId]) || !is_array($videos[$videoId])) {
                $this->cache->putAvailability(
                    $videoId,
                    false,
                    'not_found_or_private',
                    $ttl
                );
                if ($wasAvailable) {
                    $this->signalUnavailableTransition($videoId, $channelId);
                }
                continue;
            }
            $status = isset($videos[$videoId]['status']) &&
                is_array($videos[$videoId]['status'])
                ? $videos[$videoId]['status'] : array();
            $public = isset($status['privacyStatus']) &&
                $status['privacyStatus'] === 'public';
            $embeddable = !empty($status['embeddable']);
            $available = $public && $embeddable;
            $reason = 'available';
            if (!$public) {
                $reason = 'not_public';
            } elseif (!$embeddable) {
                $reason = 'not_embeddable';
            }
            $this->cache->putAvailability(
                $videoId,
                $available,
                $reason,
                $ttl
            );
            if ($wasAvailable && !$available) {
                if ($channelId === '' && !empty($videos[$videoId]['snippet']['channelId'])) {
                    $channelId = (string) $videos[$videoId]['snippet']['channelId'];
                }
                $this->signalUnavailableTransition($videoId, $channelId);
            }
        }
    }

    private function signalUnavailableTransition($videoId, $channelId)
    {
        if (function_exists('VIDEOS_signalDeleted')) {
            VIDEOS_signalDeleted($videoId);
        }
        if (function_exists('VIDEOS_signalSaved')) {
            VIDEOS_signalSaved('catalogue');
            VIDEOS_signalSaved('rankings:videos');
            VIDEOS_signalSaved('rankings:channels');
            VIDEOS_signalSaved('channels');
            if (Videos_Validator::youtubeChannelId($channelId)) {
                VIDEOS_signalSaved('channel:' . $channelId);
            }
        }
    }
PHP;
if (strpos($service, $old) === false) {
    throw new RuntimeException('recordAvailability block not found');
}
$service = str_replace($old, $new, $service);
write_text($servicePath, $service);

// Make channel ranking expose only channels eligible for the public directory.
$rankingsPath = $root . '/public_html/rankings.php';
$rankings = read_text($rankingsPath);
$oldRank = <<<'PHP'
        foreach ($candidates as $channelId => $item) {
            if ($moderation->isChannelExcluded($channelId) || isset($blockedChannels[$channelId])) {
                continue;
            }
            $channelItems[$channelId] = $item;
PHP;
$newRank = <<<'PHP'
        foreach ($candidates as $channelId => $item) {
            if ($moderation->isChannelExcluded($channelId) ||
                isset($blockedChannels[$channelId]) ||
                !VIDEOS_channelPageEligible($channelId, $bootstrap)) {
                continue;
            }
            $channelItems[$channelId] = $item;
PHP;
if (strpos($rankings, $oldRank) === false) {
    throw new RuntimeException('channel ranking candidate block not found');
}
$rankings = str_replace($oldRank, $newRank, $rankings);
write_text($rankingsPath, $rankings);

echo "Availability lifecycle signals and channel ranking eligibility applied.\n";
