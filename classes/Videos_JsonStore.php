<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_JsonStore
{
    private $root;
    private $maximumBytes;
    private $lastError;

    public function __construct($root, $maximumBytes)
    {
        $this->root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
        $this->maximumBytes = max(1024, (int) $maximumBytes);
        $this->lastError = '';
    }

    public function getRoot()
    {
        return $this->root;
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    public function initialize()
    {
        $directories = array(
            '',
            'locks',
            'config',
            'cache',
            'cache/search',
            'cache/videos',
            'cache/channels',
            'cache/availability',
            'users',
            'visitors',
            'views',
            'ratings',
            'stats',
            'stats/videos',
            'stats/engagement',
            'stats/engagement/videos',
            'stats/recommendations',
            'stats/recommendations/videos',
            'stats/channels',
            'stats/topics',
            'stats/daily',
            'rankings',
            'rankings/topics',
            'rankings/pages',
            'discovery',
            'quota',
            'abuse',
            'logs',
            'moderation',
            'moderation/videos',
            'moderation/channels',
            'jobs',
            'backups'
        );

        foreach ($directories as $directory) {
            $path = $this->root . str_replace(
                '/',
                DIRECTORY_SEPARATOR,
                $directory
            );
            if (!is_dir($path) && !@mkdir($path, 0750, true)) {
                $this->lastError = 'Cannot create data directory: ' . $directory;
                return false;
            }
        }

        if (!$this->writeProtectionFile(
            '.htaccess',
            "Order allow,deny\nDeny from all\nRequire all denied\n"
        )) {
            return false;
        }
        if (!$this->writeProtectionFile('index.html', '')) {
            return false;
        }

        return true;
    }

    public function createDocument($schema, $data)
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        return array(
            'schema' => $schema,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'data' => $data
        );
    }

    public function read($relativePath, $schema, $defaultData)
    {
        if (!$this->validateRequest($relativePath, $schema)) {
            return $this->createDocument($schema, $defaultData);
        }

        $path = $this->absolutePath($relativePath);
        if (!is_file($path) || filesize($path) === 0) {
            return $this->createDocument($schema, $defaultData);
        }
        if (filesize($path) > $this->maximumBytes) {
            $this->lastError = 'JSON file exceeds its maximum size.';
            return $this->createDocument($schema, $defaultData);
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->lastError = 'Cannot open JSON file for reading.';
            return $this->createDocument($schema, $defaultData);
        }
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            $this->lastError = 'Cannot acquire shared JSON lock.';
            return $this->createDocument($schema, $defaultData);
        }

        $contents = stream_get_contents($handle, $this->maximumBytes + 1);
        flock($handle, LOCK_UN);
        fclose($handle);

        if ($contents === false || strlen($contents) > $this->maximumBytes) {
            $this->lastError = 'Cannot read a bounded JSON document.';
            return $this->createDocument($schema, $defaultData);
        }

        $document = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE ||
            !Videos_Validator::document($document, $schema)) {
            $this->lastError = 'JSON document is corrupt or has an invalid schema.';
            return $this->readBackup($relativePath, $schema, $defaultData);
        }

        return $document;
    }

    public function write($relativePath, $schema, $document)
    {
        if (!$this->validateRequest($relativePath, $schema) ||
            !Videos_Validator::document($document, $schema)) {
            $this->lastError = 'Invalid JSON write request.';
            return false;
        }

        $lock = $this->openLock($relativePath);
        if ($lock === false) {
            return false;
        }

        $result = $this->writeLocked($relativePath, $schema, $document);
        flock($lock, LOCK_UN);
        fclose($lock);

        return $result;
    }

    public function update($relativePath, $schema, $defaultData, $callback)
    {
        if (!is_callable($callback) ||
            !$this->validateRequest($relativePath, $schema)) {
            $this->lastError = 'Invalid JSON update request.';
            return false;
        }

        $lock = $this->openLock($relativePath);
        if ($lock === false) {
            return false;
        }

        $document = $this->read($relativePath, $schema, $defaultData);
        $updated = call_user_func($callback, $document);
        if (!is_array($updated)) {
            flock($lock, LOCK_UN);
            fclose($lock);
            $this->lastError = 'JSON update callback returned invalid data.';
            return false;
        }
        $updated['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');

        $result = $this->writeLocked($relativePath, $schema, $updated);
        flock($lock, LOCK_UN);
        fclose($lock);

        return $result ? $updated : false;
    }

    public function delete($relativePath)
    {
        if (!Videos_Validator::relativePath($relativePath)) {
            $this->lastError = 'Invalid JSON delete path.';
            return false;
        }

        $lock = $this->openLock($relativePath);
        if ($lock === false) {
            return false;
        }

        $path = $this->absolutePath($relativePath);
        $result = !file_exists($path) || @unlink($path);
        if (!$result) {
            $this->lastError = 'Cannot delete JSON file.';
        }

        flock($lock, LOCK_UN);
        fclose($lock);
        return $result;
    }

    private function writeLocked($relativePath, $schema, $document)
    {
        if (!Videos_Validator::document($document, $schema)) {
            $this->lastError = 'Document failed final schema validation.';
            return false;
        }

        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        );
        if ($json === false || strlen($json) > $this->maximumBytes) {
            $this->lastError = 'Encoded JSON is invalid or too large.';
            return false;
        }

        $path = $this->absolutePath($relativePath);
        $directory = dirname($path);
        if (!$this->ensureDirectory($directory)) {
            return false;
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.videos-'
            . $this->randomHex(12) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) {
            $this->lastError = 'Cannot create temporary JSON file.';
            return false;
        }

        $written = 0;
        $length = strlen($json);
        while ($written < $length) {
            $count = fwrite($handle, substr($json, $written));
            if ($count === false || $count === 0) {
                fclose($handle);
                @unlink($temporary);
                $this->lastError = 'Cannot complete temporary JSON write.';
                return false;
            }
            $written += $count;
        }
        fflush($handle);
        fclose($handle);
        @chmod($temporary, 0640);

        $check = json_decode(file_get_contents($temporary), true);
        if (json_last_error() !== JSON_ERROR_NONE ||
            !Videos_Validator::document($check, $schema)) {
            @unlink($temporary);
            $this->lastError = 'Temporary JSON failed verification.';
            return false;
        }

        if (is_file($path)) {
            @copy($path, $path . '.bak');
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            $this->lastError = 'Atomic JSON replacement failed.';
            return false;
        }

        return true;
    }

    private function readBackup($relativePath, $schema, $defaultData)
    {
        $backup = $this->absolutePath($relativePath) . '.bak';
        if (!is_file($backup) || filesize($backup) > $this->maximumBytes) {
            return $this->createDocument($schema, $defaultData);
        }
        $document = json_decode(file_get_contents($backup), true);
        if (json_last_error() === JSON_ERROR_NONE &&
            Videos_Validator::document($document, $schema)) {
            return $document;
        }

        return $this->createDocument($schema, $defaultData);
    }

    private function validateRequest($relativePath, $schema)
    {
        if (!Videos_Validator::relativePath($relativePath) ||
            !Videos_Validator::schemaName($schema)) {
            $this->lastError = 'Invalid path or schema.';
            return false;
        }
        return true;
    }

    private function absolutePath($relativePath)
    {
        return $this->root . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath
        );
    }

    private function openLock($relativePath)
    {
        $path = $this->root . 'locks' . DIRECTORY_SEPARATOR
            . hash('sha256', $relativePath) . '.lock';
        $handle = @fopen($path, 'c+b');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            $this->lastError = 'Cannot acquire exclusive JSON lock.';
            return false;
        }
        return $handle;
    }

    private function ensureDirectory($directory)
    {
        if (is_dir($directory)) {
            return true;
        }
        if (!@mkdir($directory, 0750, true)) {
            $this->lastError = 'Cannot create JSON parent directory.';
            return false;
        }
        return true;
    }

    private function writeProtectionFile($relativePath, $contents)
    {
        $path = $this->absolutePath($relativePath);
        if (is_file($path)) {
            return true;
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            $this->lastError = 'Cannot create data protection file.';
            return false;
        }
        $result = fwrite($handle, $contents);
        fflush($handle);
        fclose($handle);
        @chmod($path, 0640);
        return ($result !== false);
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
        return hash('sha256', uniqid('', true) . mt_rand());
    }
}
