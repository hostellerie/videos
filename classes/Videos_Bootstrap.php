<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_Bootstrap
{
    private $store;
    private $secret;
    private $ready;
    private $dataRoot;
    private $legacyDataRoot;
    private $migrationStatus;

    public function __construct($geeklogConfig)
    {
        $this->ready = false;
        $this->secret = '';
        $this->dataRoot = '';
        $this->legacyDataRoot = '';
        $this->migrationStatus = array(
            'attempted' => false,
            'copied' => 0,
            'skipped' => 0,
            'failed' => 0
        );

        $base = isset($geeklogConfig['path_data'])
            ? $geeklogConfig['path_data']
            : $geeklogConfig['path'] . 'data' . DIRECTORY_SEPARATOR;
        $base = rtrim($base, '/\\');
        $this->legacyDataRoot = $base . DIRECTORY_SEPARATOR
            . 'videos' . DIRECTORY_SEPARATOR;
        $root = $base . '-videos' . DIRECTORY_SEPARATOR;
        if (!empty($geeklogConfig['videos_data_path']) &&
            $this->validCustomRoot(
                $geeklogConfig['videos_data_path'],
                $base,
                $geeklogConfig
            )) {
            $root = rtrim(
                $geeklogConfig['videos_data_path'],
                '/\\'
            ) . DIRECTORY_SEPARATOR;
        }
        $this->dataRoot = $root;

        if (!$this->migrateLegacyData($this->legacyDataRoot, $root)) {
            return;
        }

        $this->store = new Videos_JsonStore($root, 5242880);
        if (!$this->store->initialize()) {
            return;
        }
        $this->recordMigrationStatus();
        $this->loadRecordedMigrationStatus();
        if (!$this->loadOrCreateSecret()) {
            return;
        }

        $this->ready = true;
    }

    public function isReady()
    {
        return $this->ready;
    }

    public function getStore()
    {
        return $this->store;
    }

    public function getSecret()
    {
        return $this->secret;
    }

    public function getDataRoot()
    {
        return $this->dataRoot;
    }

    public function getLegacyDataRoot()
    {
        return $this->legacyDataRoot;
    }

    public function getMigrationStatus()
    {
        return $this->migrationStatus;
    }

    public function getYouTubeApiKey()
    {
        $document = $this->store->read(
            'config/secrets.json',
            'videos.secrets',
            array('privacy_hmac_key' => '', 'youtube_api_key' => '')
        );
        return isset($document['data']['youtube_api_key'])
            ? (string) $document['data']['youtube_api_key']
            : '';
    }

    public function setYouTubeApiKey($apiKey)
    {
        $apiKey = trim((string) $apiKey);
        if ($apiKey !== '' &&
            (strlen($apiKey) < 20 || strlen($apiKey) > 200 ||
             !preg_match('/^[A-Za-z0-9_-]+$/', $apiKey))) {
            return false;
        }

        $result = $this->store->update(
            'config/secrets.json',
            'videos.secrets',
            array('privacy_hmac_key' => $this->secret, 'youtube_api_key' => ''),
            function ($document) use ($apiKey) {
                $document['data']['youtube_api_key'] = $apiKey;
                return $document;
            }
        );
        return ($result !== false);
    }

    private function loadOrCreateSecret()
    {
        $document = $this->store->read(
            'config/secrets.json',
            'videos.secrets',
            array('privacy_hmac_key' => '', 'youtube_api_key' => '')
        );

        if (isset($document['data']['privacy_hmac_key']) &&
            preg_match(
                '/^[a-f0-9]{64,}$/',
                $document['data']['privacy_hmac_key']
            )) {
            $this->secret = $document['data']['privacy_hmac_key'];
            return true;
        }

        $secret = $this->generateSecret();
        if ($secret === false) {
            return false;
        }
        $document['data']['privacy_hmac_key'] = $secret;
        if (!isset($document['data']['youtube_api_key'])) {
            $document['data']['youtube_api_key'] = '';
        }
        $document['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
        if (!$this->store->write(
            'config/secrets.json',
            'videos.secrets',
            $document
        )) {
            return false;
        }

        $this->secret = $secret;
        return true;
    }

    private function generateSecret()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $bytes = openssl_random_pseudo_bytes(32, $strong);
            if ($bytes !== false && $strong) {
                return bin2hex($bytes);
            }
        }
        return false;
    }

    private function validCustomRoot($root, $pathData, $geeklogConfig)
    {
        $root = trim((string) $root);
        $portable = str_replace('\\', '/', $root);
        if ($root === '' || strpos($root, "\0") !== false ||
            preg_match('#(^|/)\.\.(/|$)#', $portable)) {
            return false;
        }
        $absolute = preg_match('#^[A-Za-z]:/#', $portable) ||
            strpos($portable, '/') === 0;
        if (!$absolute) {
            return false;
        }
        $normalizedRoot = $this->normalizePath($root);
        $normalizedData = $this->normalizePath($pathData);
        if ($normalizedRoot === $normalizedData ||
            strpos(
                $normalizedRoot . '/',
                $normalizedData . '/'
            ) === 0) {
            return false;
        }
        if (!empty($geeklogConfig['path_html'])) {
            $normalizedHtml = $this->normalizePath(
                $geeklogConfig['path_html']
            );
            if ($normalizedRoot === $normalizedHtml ||
                strpos($normalizedRoot . '/', $normalizedHtml . '/') === 0) {
                return false;
            }
        }
        return true;
    }

    private function normalizePath($path)
    {
        $path = str_replace('\\', '/', rtrim((string) $path, '/\\'));
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
        }
        return $path;
    }

    private function migrateLegacyData($source, $destination)
    {
        if (!is_dir($source) ||
            $this->normalizePath($source) === $this->normalizePath($destination)) {
            return true;
        }
        if ($this->migrationAlreadyCompleted($source, $destination)) {
            return true;
        }
        $this->migrationStatus['attempted'] = true;
        if (!is_dir($destination) && !@mkdir($destination, 0750, true)) {
            $this->migrationStatus['failed']++;
            return false;
        }
        $lockPath = rtrim($destination, '/\\')
            . DIRECTORY_SEPARATOR . '.videos-migration.lock';
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            $this->migrationStatus['failed']++;
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $source,
                FilesystemIterator::SKIP_DOTS
            ),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $sourcePath = $item->getPathname();
            $relative = substr($sourcePath, strlen(rtrim($source, '/\\')) + 1);
            $relative = str_replace('\\', '/', $relative);
            if ($relative === '' || strpos($relative, 'locks/') === 0 ||
                preg_match('/\.tmp$/', $relative)) {
                continue;
            }
            $targetPath = rtrim($destination, '/\\')
                . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if ($item->isDir()) {
                if (!is_dir($targetPath) && !@mkdir($targetPath, 0750, true)) {
                    $this->migrationStatus['failed']++;
                }
                continue;
            }
            if (!$item->isFile()) {
                continue;
            }
            $copyResult = $this->copyMigrationFile($sourcePath, $targetPath);
            if ($copyResult === 'copied') {
                $this->migrationStatus['copied']++;
            } elseif ($copyResult === 'skipped') {
                $this->migrationStatus['skipped']++;
            } else {
                $this->migrationStatus['failed']++;
            }
        }
        flock($lock, LOCK_UN);
        fclose($lock);
        // Corrupt or unreadable optional files are deliberately left behind.
        // JsonStore will recreate their safe defaults in the new location.
        return true;
    }

    private function copyMigrationFile($source, $destination)
    {
        $isJson = preg_match('/\.(?:json|bak)$/i', $source);
        if (is_file($destination)) {
            if (!$isJson || $this->validJsonFile($destination)) {
                return 'skipped';
            }
        }
        $sourceHandle = @fopen($source, 'rb');
        if ($sourceHandle === false || !flock($sourceHandle, LOCK_SH)) {
            if (is_resource($sourceHandle)) {
                fclose($sourceHandle);
            }
            return 'failed';
        }
        $contents = stream_get_contents($sourceHandle, 5242881);
        flock($sourceHandle, LOCK_UN);
        fclose($sourceHandle);
        if ($contents === false || strlen($contents) > 5242880) {
            return 'failed';
        }
        if ($isJson && !$this->validJsonContents($contents)) {
            return preg_match('/\.bak$/i', $source)
                ? 'skipped' : 'failed';
        }
        $directory = dirname($destination);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true)) {
            return 'failed';
        }
        $temporary = $directory . DIRECTORY_SEPARATOR
            . '.videos-migrate-' . sha1($source . microtime(true)) . '.tmp';
        $handle = @fopen($temporary, 'xb');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return 'failed';
        }
        $written = 0;
        $length = strlen($contents);
        while ($written < $length) {
            $count = fwrite($handle, substr($contents, $written));
            if ($count === false || $count === 0) {
                flock($handle, LOCK_UN);
                fclose($handle);
                @unlink($temporary);
                return 'failed';
            }
            $written += $count;
        }
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if (is_file($destination)) {
            $preserved = $destination . '.pre-migration-'
                . gmdate('YmdHis') . '.bak';
            if (!@rename($destination, $preserved)) {
                @unlink($temporary);
                return 'failed';
            }
        }
        if (!@rename($temporary, $destination)) {
            @unlink($temporary);
            return 'failed';
        }
        @chmod($destination, 0640);
        return 'copied';
    }

    private function validJsonFile($path)
    {
        if (!is_file($path) || filesize($path) <= 0 ||
            filesize($path) > 5242880) {
            return false;
        }
        $contents = @file_get_contents($path);
        return $contents !== false && $this->validJsonContents($contents);
    }

    private function validJsonContents($contents)
    {
        $document = json_decode($contents, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($document) ||
            empty($document['schema']) || !is_string($document['schema'])) {
            return false;
        }
        return Videos_Validator::document($document, $document['schema']);
    }

    private function recordMigrationStatus()
    {
        if (empty($this->migrationStatus['attempted']) ||
            !empty($this->migrationStatus['failed'])) {
            return;
        }
        $document = $this->store->createDocument(
            'videos.storage_migration',
            array(
                'completed_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'source' => $this->legacyDataRoot,
                'destination' => $this->dataRoot,
                'copied' => (int) $this->migrationStatus['copied'],
                'skipped' => (int) $this->migrationStatus['skipped'],
                'failed' => (int) $this->migrationStatus['failed'],
                'legacy_preserved' => true
            )
        );
        $this->store->write(
            'config/storage-migration.json',
            'videos.storage_migration',
            $document
        );
    }

    private function migrationAlreadyCompleted($source, $destination)
    {
        $path = rtrim($destination, '/\\') . DIRECTORY_SEPARATOR
            . 'config' . DIRECTORY_SEPARATOR . 'storage-migration.json';
        if (!$this->validJsonFile($path)) {
            return false;
        }
        $document = json_decode(file_get_contents($path), true);
        return isset($document['data']['source']) &&
            isset($document['data']['destination']) &&
            !empty($document['data']['legacy_preserved']) &&
            $this->normalizePath($document['data']['source']) ===
                $this->normalizePath($source) &&
            $this->normalizePath($document['data']['destination']) ===
                $this->normalizePath($destination);
    }

    private function loadRecordedMigrationStatus()
    {
        if (!empty($this->migrationStatus['attempted'])) {
            return;
        }
        $document = $this->store->read(
            'config/storage-migration.json',
            'videos.storage_migration',
            array()
        );
        if (empty($document['data']['legacy_preserved'])) {
            return;
        }
        $this->migrationStatus = array(
            'attempted' => true,
            'copied' => isset($document['data']['copied'])
                ? (int) $document['data']['copied'] : 0,
            'skipped' => isset($document['data']['skipped'])
                ? (int) $document['data']['skipped'] : 0,
            'failed' => isset($document['data']['failed'])
                ? (int) $document['data']['failed'] : 0
        );
    }
}
