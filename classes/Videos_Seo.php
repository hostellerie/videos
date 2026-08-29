<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Seo
{
    private $siteUrl;
    private $siteName;
    private $configuration;

    public function __construct($siteUrl, $siteName, $configuration)
    {
        $this->siteUrl = rtrim((string) $siteUrl, '/');
        $this->siteName = $this->cleanText($siteName, 120);
        $this->configuration = is_array($configuration)
            ? $configuration : array();
    }

    public function enabled()
    {
        return !empty($this->configuration['seo_enabled']);
    }

    public function catalogue($publicTitle, $page, $hasContent)
    {
        $page = max(1, (int) $page);
        $siteLabel = $this->siteName !== ''
            ? $this->siteName : $this->cleanText($publicTitle, 120);
        $canonical = $this->siteUrl . '/videos/index.php';
        if ($page > 1) {
            $canonical .= '?page=' . $page;
        }
        $title = $this->cleanText($publicTitle, 160);
        if ($page > 1) {
            $title .= $this->isFrench()
                ? ' – Page ' . $page
                : ' – Page ' . $page;
        }
        $description = $this->configuredDescription(
            $this->isFrench()
                ? sprintf('Découvrez le catalogue vidéo sélectionné par %s.', $siteLabel)
                : sprintf('Discover the video catalogue selected by %s.', $siteLabel)
        );
        if ($page > 1) {
            $description .= $this->isFrench()
                ? ' Page ' . $page . '.'
                : ' Page ' . $page . '.';
        }
        return $this->header(
            $canonical,
            $title,
            $description,
            !empty($this->configuration['seo_catalogue_index']) && $hasContent,
            '',
            '',
            ''
        );
    }

    public function rankings($title, $description, $tab, $hasContent)
    {
        $tab = $tab === 'channels' ? 'channels' : 'videos';
        $canonical = $this->siteUrl . '/videos/rankings.php?tab=' . rawurlencode($tab);
        return $this->header(
            $canonical,
            $title,
            $this->cleanText($description, 300),
            !empty($this->configuration['seo_rankings_index']) && $hasContent,
            '',
            '',
            ''
        );
    }

    public function privatePage($canonical = '')
    {
        return $this->robots(false, true);
    }

    public function adminPage()
    {
        return $this->robots(false, false);
    }

    public function unavailableVideo($videoId)
    {
        return $this->privatePage();
    }

    public function video($videoId, $video, $description, $embedUrl)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) || !is_array($video)) {
            return $this->unavailableVideo($videoId);
        }
        $snippet = isset($video['snippet']) && is_array($video['snippet'])
            ? $video['snippet'] : array();
        $title = isset($snippet['title'])
            ? $this->cleanText($snippet['title'], 180) : $videoId;
        $channel = isset($snippet['channelTitle'])
            ? $this->cleanText($snippet['channelTitle'], 120) : '';
        $canonical = $this->siteUrl . '/videos/watch.php?v=' . rawurlencode($videoId);
        $description = $this->cleanText($description, 300);
        if ($description === '') {
            $description = $this->videoFallbackDescription(
                $title,
                $channel,
                isset($video['videos_duration_seconds'])
                    ? (int) $video['videos_duration_seconds'] : 0
            );
        }
        $thumbnail = $this->thumbnail($snippet);
        $structuredData = '';
        if (!empty($this->configuration['seo_structured_data'])) {
            $structuredData = $this->videoObject(
                $videoId,
                $video,
                $title,
                $description,
                $thumbnail,
                $canonical,
                $embedUrl
            );
        }
        return $this->header(
            $canonical,
            $title,
            $description,
            true,
            $thumbnail,
            $embedUrl,
            $structuredData
        );
    }

    private function header($canonical, $title, $description, $index, $image, $videoUrl, $structuredData)
    {
        if (!$index) {
            return $this->robots(false, true);
        }
        if (!$this->enabled()) {
            return '';
        }
        $canonical = $this->safeUrl($canonical);
        $title = $this->cleanText($title, 180);
        $description = $this->cleanText($description, 300);
        $header = $this->robots(true, true);
        if ($canonical !== '') {
            $header .= '<link rel="canonical" href="' . $this->escape($canonical) . '">' . "\n";
        }
        if ($description !== '') {
            $header .= '<meta name="description" content="' . $this->escape($description) . '">' . "\n";
        }
        if (!empty($this->configuration['seo_social_metadata'])) {
            $header .= $this->socialMetadata($canonical, $title, $description, $image, $videoUrl);
        }
        if ($structuredData !== '') {
            $header .= '<script type="application/ld+json">' . $structuredData . '</script>' . "\n";
        }
        return $header;
    }

    private function robots($index, $follow)
    {
        if (!$index) {
            return '<meta name="robots" content="noindex,'
                . ($follow ? 'follow' : 'nofollow') . '">' . "\n";
        }
        return '<meta name="robots" content="index,follow,'
            . 'max-snippet:-1,max-image-preview:large,max-video-preview:-1">' . "\n";
    }

    private function socialMetadata($canonical, $title, $description, $image, $videoUrl)
    {
        $header = '<meta property="og:type" content="'
            . ($videoUrl !== '' ? 'video.other' : 'website') . '">' . "\n";
        $properties = array(
            'og:title' => $title,
            'og:description' => $description,
            'og:url' => $canonical,
            'og:site_name' => $this->siteName,
            'og:image' => $this->safeUrl($image)
        );
        foreach ($properties as $property => $value) {
            if ($value !== '') {
                $header .= '<meta property="' . $property . '" content="'
                    . $this->escape($value) . '">' . "\n";
            }
        }
        if ($image !== '' && $title !== '') {
            $header .= '<meta property="og:image:alt" content="'
                . $this->escape($title) . '">' . "\n";
        }
        if ($videoUrl !== '') {
            $safeVideoUrl = $this->safeUrl($videoUrl);
            if ($safeVideoUrl !== '') {
                $header .= '<meta property="og:video" content="'
                    . $this->escape($safeVideoUrl) . '">' . "\n"
                    . '<meta property="og:video:secure_url" content="'
                    . $this->escape($safeVideoUrl) . '">' . "\n"
                    . '<meta property="og:video:type" content="text/html">' . "\n";
            }
        }
        $header .= '<meta name="twitter:card" content="'
            . ($image !== '' ? 'summary_large_image' : 'summary') . '">' . "\n";
        if ($title !== '') {
            $header .= '<meta name="twitter:title" content="' . $this->escape($title) . '">' . "\n";
        }
        if ($description !== '') {
            $header .= '<meta name="twitter:description" content="' . $this->escape($description) . '">' . "\n";
        }
        if ($image !== '') {
            $header .= '<meta name="twitter:image" content="' . $this->escape($image) . '">' . "\n";
            if ($title !== '') {
                $header .= '<meta name="twitter:image:alt" content="' . $this->escape($title) . '">' . "\n";
            }
        }
        return $header;
    }

    private function videoObject($videoId, $video, $title, $description, $thumbnail, $canonical, $embedUrl)
    {
        $snippet = isset($video['snippet']) && is_array($video['snippet'])
            ? $video['snippet'] : array();
        $published = isset($snippet['publishedAt'])
            ? $this->isoDate($snippet['publishedAt']) : '';
        if ($title === '' || $description === '' || $thumbnail === '' || $published === '') {
            return '';
        }
        $data = array(
            '@context' => 'https://schema.org',
            '@type' => 'VideoObject',
            'name' => $title,
            'description' => $description,
            'thumbnailUrl' => array($thumbnail),
            'uploadDate' => $published,
            'url' => $canonical,
            'embedUrl' => $this->safeUrl($embedUrl),
            'mainEntityOfPage' => $canonical
        );
        $duration = isset($video['videos_duration_seconds'])
            ? (int) $video['videos_duration_seconds'] : 0;
        if ($duration > 0) {
            $data['duration'] = $this->isoDuration($duration);
        }
        if ($data['embedUrl'] === '') {
            unset($data['embedUrl']);
        }
        return json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }

    private function videoFallbackDescription($title, $channel, $duration)
    {
        $site = $this->siteName !== ''
            ? $this->siteName : ($this->isFrench() ? 'ce site' : 'this site');
        if ($this->isFrench()) {
            $text = 'Regardez « ' . $title . ' »';
            if ($channel !== '') {
                $text .= ', une vidéo de ' . $channel;
            }
            $text .= ' sélectionnée par ' . $site . '.';
            if ($duration > 0) {
                $text .= ' Durée : ' . $this->humanDuration($duration) . '.';
            }
        } else {
            $text = 'Watch "' . $title . '"';
            if ($channel !== '') {
                $text .= ', a video from ' . $channel;
            }
            $text .= ' selected by ' . $site . '.';
            if ($duration > 0) {
                $text .= ' Duration: ' . $this->humanDuration($duration) . '.';
            }
        }
        return $this->cleanText($text, 300);
    }

    private function configuredDescription($fallback)
    {
        $configured = isset($this->configuration['seo_description_fallback'])
            ? $this->cleanText($this->configuration['seo_description_fallback'], 300) : '';
        return $configured !== '' ? $configured : $this->cleanText($fallback, 300);
    }

    private function thumbnail($snippet)
    {
        if (!isset($snippet['thumbnails']) || !is_array($snippet['thumbnails'])) {
            return '';
        }
        foreach (array('maxres', 'standard', 'high', 'medium', 'default') as $size) {
            if (isset($snippet['thumbnails'][$size]['url'])) {
                $url = $this->safeUrl($snippet['thumbnails'][$size]['url']);
                if ($url !== '') {
                    return $url;
                }
            }
        }
        return '';
    }

    private function safeUrl($value)
    {
        $value = trim((string) $value);
        if ($value === '' || !preg_match('#^https?://[^\s<>"\']+$#i', $value)) {
            return '';
        }
        return $value;
    }

    private function cleanText($value, $maximum)
    {
        $value = html_entity_decode(strip_tags((string) $value), ENT_QUOTES, 'UTF-8');
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maximum, 'UTF-8');
        }
        return substr($value, 0, $maximum);
    }

    private function isoDate($value)
    {
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? '' : gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }

    private function isoDuration($seconds)
    {
        $seconds = max(0, (int) $seconds);
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $remaining = $seconds % 60;
        return 'PT' . ($hours > 0 ? $hours . 'H' : '')
            . ($minutes > 0 ? $minutes . 'M' : '')
            . (($remaining > 0 || ($hours === 0 && $minutes === 0)) ? $remaining . 'S' : '');
    }

    private function humanDuration($seconds)
    {
        $minutes = (int) floor($seconds / 60);
        $remaining = $seconds % 60;
        return ($minutes > 0 ? $minutes . ' min ' : '')
            . ($remaining > 0 ? $remaining . ' s' : '');
    }

    private function isFrench()
    {
        $language = isset($this->configuration['language'])
            ? strtolower((string) $this->configuration['language']) : '';
        return strpos($language, 'fr') === 0;
    }

    private function escape($value)
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
