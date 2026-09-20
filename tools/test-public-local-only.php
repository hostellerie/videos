<?php

$root = dirname(__DIR__);
$publicRoot = $root . '/public_html';

$forbidden = array(
    'new Videos_YouTubeClient(' => 'direct YouTube client construction',
    'new Videos_YouTubeService(' => 'direct YouTube service construction',
    'Videos_ProviderFactory::youtube(' => 'external provider construction',
    'Videos_ProviderFactory::youtubeService(' => 'external service construction',
    '->refresh(' => 'external/discovery refresh trigger'
);

$failures = array();
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(
        $publicRoot,
        FilesystemIterator::SKIP_DOTS
    )
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $text = file_get_contents($fileInfo->getPathname());
    if ($text === false) {
        $failures[] = $fileInfo->getPathname() . ': unreadable';
        continue;
    }
    foreach ($forbidden as $needle => $description) {
        if (strpos($text, $needle) !== false) {
            $relative = substr($fileInfo->getPathname(), strlen($root) + 1);
            $failures[] = $relative . ': ' . $description;
        }
    }
}

if (count($failures) > 0) {
    fwrite(
        STDERR,
        "Public runtime must remain local-only:\n- "
        . implode("\n- ", $failures)
        . "\n"
    );
    exit(1);
}

echo "Public runtime local-only contract: OK\n";
