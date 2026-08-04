<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Validator
{
    public static function relativePath($path)
    {
        if (!is_string($path) || $path === '' || strlen($path) > 512) {
            return false;
        }
        if (strpos($path, "\0") !== false ||
            strpos($path, '\\') !== false ||
            preg_match('#(^|/)\.\.(/|$)#', $path) ||
            preg_match('#^[a-zA-Z]:#', $path) ||
            substr($path, 0, 1) === '/') {
            return false;
        }

        return (bool) preg_match('#^[a-zA-Z0-9._/-]+$#', $path);
    }

    public static function schemaName($schema)
    {
        return is_string($schema) &&
            (bool) preg_match('/^videos\.[a-z0-9_.-]{1,80}$/', $schema);
    }

    public static function document($document, $expectedSchema)
    {
        return is_array($document) &&
            isset($document['schema']) &&
            $document['schema'] === $expectedSchema &&
            isset($document['version']) &&
            is_int($document['version']) &&
            $document['version'] >= 1 &&
            isset($document['created_at']) &&
            is_string($document['created_at']) &&
            isset($document['updated_at']) &&
            is_string($document['updated_at']) &&
            array_key_exists('data', $document);
    }

    public static function youtubeVideoId($value)
    {
        return is_string($value) &&
            (bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $value);
    }

    public static function youtubeChannelId($value)
    {
        return is_string($value) &&
            (bool) preg_match('/^UC[A-Za-z0-9_-]{22}$/', $value);
    }

    public static function accountUid($uid)
    {
        return (is_int($uid) || ctype_digit((string) $uid)) &&
            (int) $uid > 1;
    }
}

