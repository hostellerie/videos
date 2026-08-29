<?php
$path = dirname(__DIR__) . '/language/english.php';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Cannot read english.php');
}
$needle = "  'Indexation des pages existantes' => 'Index existing pages',\n";
$insert = $needle
    . "  'Le rattrapage inventorie les pages publiques et les envoie à IndexNow en mode batch.' => 'The catch-up inventories public pages and sends them to IndexNow in batch mode.',\n";
if (strpos($content, $needle) === false) {
    throw new RuntimeException('IndexNow translation marker not found');
}
if (strpos($content, "Le rattrapage inventorie les pages publiques et les envoie à IndexNow en mode batch.") === false) {
    $content = str_replace($needle, $insert, $content);
}
if (file_put_contents($path, $content) === false) {
    throw new RuntimeException('Cannot write english.php');
}
echo "IndexNow English translation added.\n";
