<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Visitor
{
    const COOKIE_NAME = 'gl_videos_visitor';

    private $privacy;
    private $uid;
    private $rawVisitorId;
    private $subjectHash;
    private $account;

    public function __construct($privacy, $uid)
    {
        $this->privacy = $privacy;
        $this->uid = (int) $uid;
        $this->rawVisitorId = '';
        $this->subjectHash = '';
        $this->account = $this->uid > 1;

        if ($this->account) {
            $this->subjectHash = $this->privacy->accountHash($this->uid);
        } else {
            $this->rawVisitorId = $this->loadOrCreateCookie();
            $this->subjectHash = $this->privacy->visitorHash(
                $this->rawVisitorId
            );
        }
    }

    public function isValid()
    {
        return is_string($this->subjectHash) &&
            (bool) preg_match('/^[a-f0-9]{64}$/', $this->subjectHash);
    }

    public function isAccount()
    {
        return $this->account;
    }

    public function getUid()
    {
        return $this->uid;
    }

    public function getSubjectHash()
    {
        return $this->subjectHash;
    }

    private function loadOrCreateCookie()
    {
        if (isset($_COOKIE[self::COOKIE_NAME]) &&
            preg_match('/^[a-f0-9]{64}$/', $_COOKIE[self::COOKIE_NAME])) {
            return $_COOKIE[self::COOKIE_NAME];
        }

        $identifier = $this->randomHex(32);
        if ($identifier === false) {
            return '';
        }

        $secure = isset($_SERVER['HTTPS']) &&
            strtolower((string) $_SERVER['HTTPS']) !== 'off';
        setcookie(
            self::COOKIE_NAME,
            $identifier,
            time() + 31536000,
            '/; samesite=Lax',
            '',
            $secure,
            true
        );
        $_COOKIE[self::COOKIE_NAME] = $identifier;
        return $identifier;
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
