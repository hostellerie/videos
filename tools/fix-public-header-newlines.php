<?php
$root = dirname(__DIR__);
$files = array(
    $root . '/public_html/channel.php',
    $root . '/public_html/channels.php'
);
foreach ($files as $path) {
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    // Replace literal backslash-n endings in single-quoted header fragments
    // with concatenated real newlines.
    $content = str_replace("\\n'", "' . \"\\n\"", $content);
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}
echo "Public header newlines fixed.\n";
