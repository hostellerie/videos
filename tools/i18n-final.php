<?php
$path = dirname(__DIR__) . '/public_html/index.php';
$content = file_get_contents($path);
$content = str_replace(
    ") . '\">Afficher tout le catalogue</a></div>';",
    ") . '\">' . htmlspecialchars($LANG_VIDEOS['catalogue_show_all'], ENT_QUOTES, 'UTF-8') . '</a></div>';",
    $content
);
file_put_contents($path, $content);
echo "Final catalogue i18n fix applied.\n";
