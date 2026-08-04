<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

class Videos_CacheMaintenance
{
    private $store;
    private $allowedScopes;

    public function __construct($store)
    {
        $this->store = $store;
        $this->allowedScopes = array(
            'search' => 'cache/search',
            'videos' => 'cache/videos',
            'channels' => 'cache/channels',
            'availability' => 'cache/availability'
        );
    }

    public function inspect()
    {
        $result = array();
        foreach ($this->allowedScopes as $scope => $relativeDirectory) {
            $result[$scope] = $this->inspectScope($relativeDirectory);
        }
        return $result;
    }

    public function clear($scope)
    {
        if ($scope === 'all') {
            $scopes = array_keys($this->allowedScopes);
        } elseif (isset($this->allowedScopes[$scope])) {
            $scopes = array($scope);
        } else {
            return array(
                'success' => false,
                'deleted' => 0,
                'failed' => 0,
                'scope' => ''
            );
        }

        $deleted = 0;
        $failed = 0;
        foreach ($scopes as $currentScope) {
            $files = $this->listJsonFiles(
                $this->allowedScopes[$currentScope]
            );
            foreach ($files as $relativePath) {
                if ($this->store->delete($relativePath)) {
                    $deleted++;
                } else {
                    $failed++;
                }
                $backupPath = $relativePath . '.bak';
                if (is_file($this->absolutePath($backupPath)) &&
                    !$this->store->delete($backupPath)) {
                    $failed++;
                }
            }
        }

        return array(
            'success' => ($failed === 0),
            'deleted' => $deleted,
            'failed' => $failed,
            'scope' => $scope
        );
    }

    private function inspectScope($relativeDirectory)
    {
        $files = $this->listJsonFiles($relativeDirectory);
        $bytes = 0;
        $latest = 0;
        foreach ($files as $relativePath) {
            $absolutePath = $this->absolutePath($relativePath);
            $size = @filesize($absolutePath);
            $modified = @filemtime($absolutePath);
            if ($size !== false) {
                $bytes += $size;
            }
            if ($modified !== false && $modified > $latest) {
                $latest = $modified;
            }
        }
        return array(
            'entries' => count($files),
            'bytes' => $bytes,
            'latest_at' => $latest > 0
                ? gmdate('Y-m-d\TH:i:s\Z', $latest) : null
        );
    }

    private function listJsonFiles($relativeDirectory)
    {
        $files = array();
        $absoluteDirectory = $this->absolutePath($relativeDirectory);
        if (!is_dir($absoluteDirectory)) {
            return $files;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $absoluteDirectory,
                    FilesystemIterator::SKIP_DOTS
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || $fileInfo->isLink() ||
                    substr($fileInfo->getFilename(), -5) !== '.json') {
                    continue;
                }
                $absolutePath = $fileInfo->getPathname();
                if (strpos($absolutePath, $absoluteDirectory) !== 0) {
                    continue;
                }
                $relativePath = substr(
                    $absolutePath,
                    strlen($this->store->getRoot())
                );
                $relativePath = str_replace(
                    DIRECTORY_SEPARATOR,
                    '/',
                    $relativePath
                );
                if (Videos_Validator::relativePath($relativePath)) {
                    $files[] = $relativePath;
                }
            }
        } catch (UnexpectedValueException $exception) {
            return $files;
        }

        return $files;
    }

    private function absolutePath($relativePath)
    {
        return $this->store->getRoot() . str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $relativePath
        );
    }
}
