<?php
$path = dirname(__DIR__) . '/admin/repair.php';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Cannot read repair.php');
}

$content = str_replace(
    "$html = '<div class=\"videos-repair\"><h1>Réparation du plugin Videos</h1>';",
    "$html = '<div class=\"videos-repair\"><h1>Diagnostic et récupération Videos</h1>';",
    $content
);

$old = <<<'PHP'
$html .= '<p>Cette page ne supprime jamais les données JSON persistantes '
    . 'du plugin, qu’elles se trouvent dans l’ancien dossier '
    . '<code>path_data/videos/</code> ou dans le nouveau dossier frère '
    . '<code>path_data-videos/</code>.</p>'
    . '<h2>Diagnostic</h2><ul>'
PHP;
$new = <<<'PHP'
$html .= '<p>Cette page sert surtout à diagnostiquer une installation incomplète ou des résidus laissés après une installation interrompue. Elle ne modifie pas une installation Videos normale.</p>'
    . '<p>Les données JSON persistantes du plugin sont toujours conservées, qu’elles se trouvent dans l’ancien dossier '
    . '<code>path_data/videos/</code> ou dans le nouveau dossier frère '
    . '<code>path_data-videos/</code>.</p>'
    . '<h2>État de l’installation</h2><ul>'
PHP;
if (strpos($content, $old) === false) {
    throw new RuntimeException('Intro block not found');
}
$content = str_replace($old, $new, $content);

$old = <<<'PHP'
} elseif ($pluginCount > 0) {
    $html .= '<p>Utilisez la désinstallation normale de Geeklog ; '
        . 'la réparation est volontairement désactivée.</p>';
} else {
PHP;
$new = <<<'PHP'
} elseif ($pluginCount > 0) {
    $html .= '<h2>Aucune réparation de base de données nécessaire</h2>'
        . '<p>Videos est correctement enregistré dans Geeklog. Aucun résidu d’installation ne peut donc être nettoyé depuis cette page.</p>'
        . '<p>Si vous rencontrez un problème de fonctionnement, utilisez plutôt les outils adaptés :</p>'
        . '<ul>'
        . '<li><a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/videos/actions.php', ENT_QUOTES, 'UTF-8') . '">Actions</a> pour reconstruire les classements, vider les caches ou gérer le catalogue permanent ;</li>'
        . '<li><a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/videos/stats.php', ENT_QUOTES, 'UTF-8') . '">Statistiques</a> pour contrôler le stockage, le cache, l’API et les intégrations ;</li>'
        . '<li><a href="' . htmlspecialchars($_CONF['site_admin_url'] . '/plugins/videos/moderation.php', ENT_QUOTES, 'UTF-8') . '">Modération</a> pour les vidéos et chaînes bloquées ou prioritaires.</li>'
        . '</ul>'
        . '<p>La désinstallation Geeklog n’est utile que si vous souhaitez réellement supprimer le plugin, pas pour corriger un problème courant.</p>';
} else {
PHP;
if (strpos($content, $old) === false) {
    throw new RuntimeException('Installed plugin message not found');
}
$content = str_replace($old, $new, $content);

$content = str_replace("'Réparation du plugin Videos'", "'Diagnostic et récupération Videos'", $content);

if (file_put_contents($path, $content) === false) {
    throw new RuntimeException('Cannot write repair.php');
}
echo "Repair page UX updated.\n";
