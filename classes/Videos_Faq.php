<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Faq
{
    private $language;
    private $configuration;

    public function __construct($language, $configuration)
    {
        $this->language = is_array($language) ? $language : array();
        $this->configuration = is_array($configuration)
            ? $configuration : array();
    }

    public function catalogue()
    {
        return $this->items(
            array(
                'catalogue_selection',
                'catalogue_changes',
                'catalogue_ratings',
                'catalogue_privacy'
            )
        );
    }

    public function rankings($tab)
    {
        if ($tab === 'channels') {
            return $this->items(
                array(
                    'channel_ranking_score',
                    'channel_ranking_diversity',
                    'ranking_update'
                )
            );
        }
        return $this->items(
            array(
                'video_ranking_score',
                'video_ranking_views',
                'ranking_update'
            )
        );
    }

    public function video($video, $ratingStats)
    {
        if (!is_array($video)) {
            return array();
        }
        $snippet = isset($video['snippet']) &&
            is_array($video['snippet'])
            ? $video['snippet'] : array();
        $channel = isset($snippet['channelTitle'])
            ? $this->text($snippet['channelTitle'], 120) : '';
        $published = isset($snippet['publishedAt'])
            ? $this->date($snippet['publishedAt']) : '';
        $duration = isset($video['videos_duration_seconds'])
            ? $this->duration((int) $video['videos_duration_seconds']) : '';
        $average = isset($ratingStats['rating_average'])
            ? number_format(
                (float) $ratingStats['rating_average'],
                2,
                ',',
                ' '
            ) : '0,00';
        $count = isset($ratingStats['rating_count'])
            ? (int) $ratingStats['rating_count'] : 0;
        $replacements = array(
            '%channel%' => $channel !== ''
                ? $channel : $this->value('unknown_value'),
            '%duration%' => $duration !== ''
                ? $duration : $this->value('unknown_value'),
            '%published%' => $published !== ''
                ? $published : $this->value('unknown_value'),
            '%average%' => $average,
            '%count%' => $count
        );
        $items = array();
        foreach (array(
            'video_channel',
            'video_duration',
            'video_publication',
            'video_selection',
            'video_rating'
        ) as $key) {
            $question = $this->value($key . '_q');
            $answer = strtr($this->value($key . '_a'), $replacements);
            if ($question !== '' && $answer !== '') {
                $items[] = array(
                    'question' => $question,
                    'answer' => $answer
                );
            }
        }
        return $items;
    }

    public function render($items, $heading)
    {
        if (!is_array($items) || count($items) === 0) {
            return '';
        }
        $html = '<section class="videos-faq" aria-labelledby="videos-faq-title">'
            . '<h2 id="videos-faq-title">'
            . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8')
            . '</h2><div class="videos-faq-items">';
        foreach ($items as $item) {
            if (!isset($item['question'], $item['answer'])) {
                continue;
            }
            $html .= '<details class="videos-faq-item"><summary>'
                . htmlspecialchars(
                    $item['question'],
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</summary><p>'
                . htmlspecialchars(
                    $item['answer'],
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</p></details>';
        }
        return $html . '</div></section>';
    }

    public function structuredData($items)
    {
        if (empty($this->configuration['seo_enabled']) ||
            empty($this->configuration['faq_structured_data']) ||
            !is_array($items) || count($items) === 0) {
            return '';
        }
        $entities = array();
        foreach ($items as $item) {
            if (!isset($item['question'], $item['answer'])) {
                continue;
            }
            $question = $this->text($item['question'], 300);
            $answer = $this->text($item['answer'], 1000);
            if ($question === '' || $answer === '') {
                continue;
            }
            $entities[] = array(
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text' => $answer
                )
            );
        }
        if (count($entities) === 0) {
            return '';
        }
        $json = json_encode(
            array(
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => $entities
            ),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE |
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        return '<script type="application/ld+json">' . $json
            . '</script>' . "\n";
    }

    private function items($keys)
    {
        $items = array();
        foreach ($keys as $key) {
            $question = $this->value($key . '_q');
            $answer = $this->value($key . '_a');
            if ($question !== '' && $answer !== '') {
                $items[] = array(
                    'question' => $question,
                    'answer' => $answer
                );
            }
        }
        return $items;
    }

    private function value($key)
    {
        return isset($this->language[$key])
            ? $this->text($this->language[$key], 1000) : '';
    }

    private function text($value, $maximum)
    {
        $value = trim(preg_replace(
            '/\s+/u',
            ' ',
            html_entity_decode(
                strip_tags((string) $value),
                ENT_QUOTES,
                'UTF-8'
            )
        ));
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maximum, 'UTF-8');
        }
        return substr($value, 0, $maximum);
    }

    private function date($value)
    {
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? '' : date('d/m/Y', $timestamp);
    }

    private function duration($seconds)
    {
        if ($seconds <= 0) {
            return '';
        }
        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        $remaining = $seconds % 60;
        $parts = array();
        if ($hours > 0) {
            $parts[] = $hours . ' h';
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' min';
        }
        if ($remaining > 0 || count($parts) === 0) {
            $parts[] = $remaining . ' s';
        }
        return implode(' ', $parts);
    }
}
