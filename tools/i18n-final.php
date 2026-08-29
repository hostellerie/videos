<?php

$path = dirname(__DIR__) . '/public_html/index.php';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Cannot read public_html/index.php');
}

$from = <<<'TXT'
        ) . '">Afficher tout le catalogue</a></div>';
TXT;
$to = <<<'TXT'
        ) . '">' . htmlspecialchars($LANG_VIDEOS['catalogue_show_all'], ENT_QUOTES, 'UTF-8') . '</a></div>';
TXT;

$content = str_replace($from, $to, $content);
if (file_put_contents($path, $content) === false) {
    throw new RuntimeException('Cannot write public_html/index.php');
}

echo "Final catalogue i18n fix applied.\n";
