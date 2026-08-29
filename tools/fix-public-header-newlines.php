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
    // In single-quoted PHP strings, \\n is literal text. Compose headercode
    // with an actual newline instead so Geeklog never renders "\\n".
    $content = str_replace(". '>\\\\n'", ". '>' . \"\\n\"", $content);
    $content = str_replace(". '\\">\\\\n'", ". '\\">' . \"\\n\"", $content);
    $content = str_replace(". '>\\n'", ". '>' . \"\\n\"", $content);
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}
echo "Public header newlines fixed.\n";
