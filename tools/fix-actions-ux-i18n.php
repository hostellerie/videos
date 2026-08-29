<?php
$path = dirname(__DIR__) . '/language/english.php';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Cannot read english.php');
}
$marker = <<<'PHP'
foreach (array(
    'Recherche Geeklog' => 'Geeklog search',
PHP;
$insert = <<<'PHP'
foreach (array(
    'Retirer de la sélection' => 'Remove from selection',
    'Exclure des sélections futures' => 'Exclude from future selections',
    '<strong>Retirer de la sélection</strong> enlève la vidéo du catalogue permanent, mais elle pourra être sélectionnée de nouveau. <strong>Exclure des sélections futures</strong> l’empêche d’être réintégrée tant qu’elle n’est pas réautorisée.' => '<strong>Remove from selection</strong> removes the video from the permanent catalogue, but it may be selected again. <strong>Exclude from future selections</strong> prevents it from being re-added until it is allowed again.',
    'État :' => 'Status:',
    'Clé API configurée.' => 'API key configured.',
    'Clé API absente.' => 'API key missing.',
    'Une clé YouTube Data API est nécessaire pour rechercher de nouvelles vidéos et récupérer les données d’une vidéo qui n’est pas encore en cache. Les vidéos déjà mises en cache restent consultables sans nouvel appel API.' => 'A YouTube Data API key is required to search for new videos and fetch data for a video that is not cached yet. Already cached videos remain available without a new API call.',
    'Remplacer la clé API' => 'Replace API key',
    'Ajouter une clé API' => 'Add API key',
    'Remplacer la clé' => 'Replace key',
) as $videosAdminSource => $videosAdminTarget) {
    $LANG_VIDEOS_ADMIN_TEXT[$videosAdminSource] = $videosAdminTarget;
}

foreach (array(
    'Recherche Geeklog' => 'Geeklog search',
PHP;
if (strpos($content, $marker) === false) {
    throw new RuntimeException('Admin translation extension marker not found');
}
$content = str_replace($marker, $insert, $content);
if (file_put_contents($path, $content) === false) {
    throw new RuntimeException('Cannot write english.php');
}
echo "Actions UX English translations added.\n";
