<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Description
{
    public function excerpt($description, $mode)
    {
        $mode = in_array($mode, array('clean', 'hidden', 'raw'), true)
            ? $mode : 'clean';
        if ($mode === 'hidden') {
            return '';
        }
        $text = html_entity_decode(
            strip_tags((string) $description),
            ENT_QUOTES,
            'UTF-8'
        );
        if ($mode === 'raw') {
            return $this->truncate($this->normalize($text), 500);
        }
        return $this->cleanExcerpt($text);
    }

    private function cleanExcerpt($text)
    {
        $text = preg_replace(
            '#https?://[^\s<]+|www\.[^\s<]+#iu',
            ' ',
            $text
        );
        $text = preg_replace(
            '#\b(?:[a-z0-9-]+\.)+(?:com|fr|net|org|gg|io|tv|be|ch|ca)'
                . '(?:/[^\s<]*)?#iu',
            ' ',
            $text
        );
        $sentences = preg_split(
            '/(?<=[.!?])\s+|[\r\n]+/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        if (!is_array($sentences)) {
            return '';
        }

        $candidates = array();
        $position = 0;
        foreach ($sentences as $sentence) {
            $sentence = trim(preg_replace('/\s+/u', ' ', $sentence));
            $length = $this->length($sentence);
            if ($length < 35) {
                $position++;
                continue;
            }
            $lower = $this->lower($sentence);
            if ($this->isPromotional($lower)) {
                $position++;
                continue;
            }
            $score = min(18, $length / 10) - min(8, $position * 0.5);
            if (preg_match(
                '/\b(découvr|decouvr|appren|compren|install|configur|'
                    . 'guide|tutoriel|présent|present|expliqu|compar|'
                    . 'discover|learn|understand|how to|tutorial|review)\w*/iu',
                $lower
            )) {
                $score += 30;
            }
            $candidates[] = array(
                'text' => $sentence,
                'score' => $score,
                'position' => $position
            );
            $position++;
        }
        if (count($candidates) === 0) {
            return '';
        }
        usort($candidates, array($this, 'compareScore'));
        $selected = array_slice($candidates, 0, 2);
        usort($selected, array($this, 'comparePosition'));
        $parts = array();
        foreach ($selected as $candidate) {
            $parts[] = $candidate['text'];
        }
        return $this->truncate(implode(' ', $parts), 300);
    }

    public function compareScore($left, $right)
    {
        if ($left['score'] == $right['score']) {
            return $left['position'] - $right['position'];
        }
        return $left['score'] > $right['score'] ? -1 : 1;
    }

    public function comparePosition($left, $right)
    {
        return $left['position'] - $right['position'];
    }

    private function isPromotional($text)
    {
        return (bool) preg_match(
            '/patreon|tipeee|discord|instant[\s-]?gaming|réseaux sociaux|'
                . 'reseaux sociaux|rejoignez|abonnez|abonne-toi|subscribe|'
                . 'follow me|sponsor|partenaire|code promo|donation|'
                . 'faites un don|tip jar|charismatique|des fois.*drôle|'
                . 'des fois.*drole/iu',
            $text
        );
    }

    private function normalize($text)
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    private function truncate($text, $maximum)
    {
        $text = $this->normalize($text);
        if ($this->length($text) <= $maximum) {
            return $text;
        }
        $short = $this->substring($text, 0, $maximum + 1);
        $short = preg_replace('/\s+\S*$/u', '', $short);
        return rtrim($short, " \t\n\r\0\x0B.,;:-") . '…';
    }

    private function length($text)
    {
        if (function_exists('MBYTE_strlen')) {
            return MBYTE_strlen($text);
        }
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        return strlen($text);
    }

    private function substring($text, $start, $length)
    {
        if (function_exists('MBYTE_substr')) {
            return MBYTE_substr($text, $start, $length);
        }
        if (function_exists('mb_substr')) {
            return mb_substr($text, $start, $length, 'UTF-8');
        }
        return substr($text, $start, $length);
    }

    private function lower($text)
    {
        if (function_exists('MBYTE_strtolower')) {
            return MBYTE_strtolower($text);
        }
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($text, 'UTF-8');
        }
        return strtolower($text);
    }
}
