<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_KeywordExtractor
{
    private $stopWords;

    public function __construct($additionalStopWords)
    {
        $base = 'a au aux avec ce ces dans de des du elle en et eux il je la '
            . 'le les leur lui ma mais me meme mes moi mon ne nos notre nous '
            . 'on ou par pas pour qu que quelle qui sa se ses son sur ta te '
            . 'tes toi ton tu un une vos votre vous the and for from into of '
            . 'on or that this to with is are be by as at it its';
        $this->stopWords = array();
        foreach ($this->tokenize($base . ' ' . $additionalStopWords) as $word) {
            $this->stopWords[$word] = true;
        }
    }

    public function extract($context, $configuration)
    {
        $mode = isset($configuration['mode'])
            ? $configuration['mode'] : 'mixed';
        $maximum = isset($configuration['maximum'])
            ? max(1, min(15, (int) $configuration['maximum'])) : 8;
        $scores = array();

        if ($mode !== 'manual') {
            $this->scoreText(
                isset($context['site_title']) ? $context['site_title'] : '',
                isset($configuration['title_weight'])
                    ? $configuration['title_weight'] : 5,
                $scores
            );
            $this->scoreText(
                isset($context['page_title']) ? $context['page_title'] : '',
                isset($configuration['title_weight'])
                    ? $configuration['title_weight'] : 5,
                $scores
            );
            $this->scoreText(
                (isset($context['site_description'])
                    ? $context['site_description'] : '') . ' '
                . (isset($context['page_description'])
                    ? $context['page_description'] : '') . ' '
                . (isset($context['meta_keywords'])
                    ? $context['meta_keywords'] : ''),
                isset($configuration['meta_weight'])
                    ? $configuration['meta_weight'] : 4,
                $scores
            );
            $this->scoreText(
                isset($context['content']) ? $context['content'] : '',
                isset($configuration['content_weight'])
                    ? $configuration['content_weight'] : 1,
                $scores
            );
        }

        if ($mode !== 'automatic') {
            $this->scoreText(
                isset($configuration['manual_keywords'])
                    ? $configuration['manual_keywords'] : '',
                8,
                $scores
            );
        }

        $excluded = array();
        foreach ($this->tokenize(
            isset($configuration['excluded_keywords'])
                ? $configuration['excluded_keywords'] : ''
        ) as $word) {
            $excluded[$word] = true;
        }
        foreach ($excluded as $word => $unused) {
            unset($scores[$word]);
        }

        arsort($scores, SORT_NUMERIC);
        $terms = array_slice(array_keys($scores), 0, $maximum);
        $required = $this->tokenize(
            isset($configuration['required_keywords'])
                ? $configuration['required_keywords'] : ''
        );
        foreach (array_reverse($required) as $word) {
            if (!isset($excluded[$word])) {
                array_unshift($terms, $word);
            }
        }

        return array_slice(array_values(array_unique($terms)), 0, $maximum);
    }

    public function buildQuery($terms, $excluded)
    {
        $parts = array();
        foreach ((array) $terms as $term) {
            if (preg_match('/^[a-z0-9-]{2,40}$/', $term)) {
                $parts[] = $term;
            }
        }
        foreach ($this->tokenize($excluded) as $term) {
            $parts[] = '-' . $term;
        }
        return substr(implode(' ', array_unique($parts)), 0, 250);
    }

    private function scoreText($text, $weight, &$scores)
    {
        foreach ($this->tokenize(strip_tags((string) $text)) as $word) {
            if (isset($this->stopWords[$word])) {
                continue;
            }
            if (!isset($scores[$word])) {
                $scores[$word] = 0;
            }
            $scores[$word] += max(1, min(20, (int) $weight));
        }
    }

    private function tokenize($text)
    {
        $text = html_entity_decode(
            strip_tags((string) $text),
            ENT_QUOTES,
            'UTF-8'
        );
        $text = $this->normalize($text);
        $parts = preg_split('/[^a-z0-9-]+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        $result = array();
        foreach ($parts as $part) {
            if (strlen($part) >= 2 && strlen($part) <= 40 &&
                !ctype_digit($part)) {
                $result[] = $part;
            }
        }
        return $result;
    }

    private function normalize($text)
    {
        $map = array(
            'à'=>'a','á'=>'a','â'=>'a','ä'=>'a','ã'=>'a','å'=>'a',
            'ç'=>'c','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','õ'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y',
            'œ'=>'oe','æ'=>'ae'
        );
        return strtolower(strtr($text, $map));
    }
}

