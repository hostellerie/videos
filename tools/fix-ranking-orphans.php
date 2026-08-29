<?php
$root = dirname(__DIR__);
$path = $root . '/public_html/rankings.php';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Cannot read rankings.php');
}

$old = <<<'PHP'
        foreach ($candidates as $videoId => $item) {
            $channelId = isset($item['channel_id']) ? (string) $item['channel_id'] : '';
            if ($cache->isVideoUnavailable($videoId) || $moderation->isVideoBlocked($videoId) ||
                $moderation->isChannelExcluded($channelId) || isset($blockedVideos[$videoId]) ||
                isset($blockedChannels[$channelId]) || Videos_VideoPolicy::excludesShortVideo($cache->getVideo($videoId, true), $_VIDEOS_CONF)) {
                continue;
            }
            $videoItems[$videoId] = $item;
PHP;
$new = <<<'PHP'
        foreach ($candidates as $videoId => $item) {
            $video = $cache->getVideo($videoId, true);
            $channelId = isset($item['channel_id']) ? (string) $item['channel_id'] : '';
            if (!is_array($video) || $cache->isVideoUnavailable($videoId) ||
                $moderation->isVideoBlocked($videoId) ||
                $moderation->isChannelExcluded($channelId) || isset($blockedVideos[$videoId]) ||
                isset($blockedChannels[$channelId]) ||
                Videos_VideoPolicy::excludesShortVideo($video, $_VIDEOS_CONF)) {
                continue;
            }
            $videoItems[$videoId] = $item;
PHP;
if (strpos($content, $old) === false) {
    throw new RuntimeException('Ranking candidate block not found');
}
$content = str_replace($old, $new, $content);

$oldThumb = <<<'PHP'
        $video = $cache->getVideo($videoId, true);
        $thumbnail = isset($video['snippet']['thumbnails']['medium']['url']) ? $video['snippet']['thumbnails']['medium']['url'] : '';
PHP;
$newThumb = <<<'PHP'
        $video = $cache->getVideo($videoId, true);
        if (!is_array($video)) {
            continue;
        }
        $thumbnail = videos_rankings_thumbnail($video);
PHP;
if (strpos($content, $oldThumb) === false) {
    throw new RuntimeException('Ranking thumbnail block not found');
}
$content = str_replace($oldThumb, $newThumb, $content);

$marker = <<<'PHP'
function videos_rankings_csv_set($value)
PHP;
$helper = <<<'PHP'
function videos_rankings_thumbnail($video)
{
    if (!is_array($video) || empty($video['snippet']['thumbnails']) ||
        !is_array($video['snippet']['thumbnails'])) {
        return '';
    }
    foreach (array('maxres', 'standard', 'high', 'medium', 'default') as $size) {
        if (!empty($video['snippet']['thumbnails'][$size]['url'])) {
            $url = (string) $video['snippet']['thumbnails'][$size]['url'];
            if (strpos($url, 'https://') === 0) {
                return $url;
            }
        }
    }
    return '';
}

function videos_rankings_csv_set($value)
PHP;
if (strpos($content, $marker) === false) {
    throw new RuntimeException('Ranking helper marker not found');
}
$content = str_replace($marker, $helper, $content);

if (file_put_contents($path, $content) === false) {
    throw new RuntimeException('Cannot write rankings.php');
}
echo "Ranking orphan filtering and thumbnail fallback applied.\n";
