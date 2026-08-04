<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_PlaybackToken
{
    private $secret;
    private $maximumAge;

    public function __construct($secret, $maximumAge)
    {
        $this->secret = $secret;
        $this->maximumAge = max(300, min(14400, (int) $maximumAge));
    }

    public function create($videoId, $subjectHash)
    {
        if (!Videos_Validator::youtubeVideoId($videoId) ||
            !preg_match('/^[a-f0-9]{64}$/', $subjectHash)) {
            return false;
        }
        $payload = array(
            'video_id' => $videoId,
            'subject' => $subjectHash,
            'issued_at' => time(),
            'nonce' => $this->randomHex(12)
        );
        if ($payload['nonce'] === false) {
            return false;
        }
        $encoded = $this->base64UrlEncode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, $this->secret);
        return $encoded . '.' . $signature;
    }

    public function verify($token, $videoId, $subjectHash)
    {
        if (!is_string($token) || strlen($token) > 1000 ||
            !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/', $token, $match)) {
            return false;
        }
        $expected = hash_hmac('sha256', $match[1], $this->secret);
        if (!$this->safeEquals($expected, $match[2])) {
            return false;
        }
        $decoded = $this->base64UrlDecode($match[1]);
        $payload = json_decode($decoded, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload) ||
            !isset($payload['video_id'], $payload['subject'], $payload['issued_at']) ||
            $payload['video_id'] !== $videoId ||
            $payload['subject'] !== $subjectHash ||
            !is_int($payload['issued_at']) ||
            $payload['issued_at'] > time() + 60 ||
            $payload['issued_at'] < time() - $this->maximumAge) {
            return false;
        }
        return $payload;
    }

    private function safeEquals($known, $provided)
    {
        if (function_exists('hash_equals')) {
            return hash_equals($known, $provided);
        }
        if (!is_string($known) || !is_string($provided) ||
            strlen($known) !== strlen($provided)) {
            return false;
        }
        $result = 0;
        for ($i = 0; $i < strlen($known); $i++) {
            $result |= ord($known[$i]) ^ ord($provided[$i]);
        }
        return $result === 0;
    }

    private function base64UrlEncode($value)
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode($value)
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function randomHex($bytes)
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $random = openssl_random_pseudo_bytes($bytes, $strong);
            if ($random !== false && $strong) {
                return bin2hex($random);
            }
        }
        return false;
    }
}

