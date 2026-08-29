<?php
$root = dirname(__DIR__);

function read_file_or_fail($path)
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    return $content;
}

function write_file_or_fail($path, $content)
{
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

$actionsPath = $root . '/admin/actions.php';
$actions = read_file_or_fail($actionsPath);
$old = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>YouTube Data API</h2>'
    . '<p><strong>État :</strong> ' . ($youtubeApiKeyConfigured ? 'Clé API configurée.' : 'Clé API absente.') . '</p>'
    . '<p>Une clé YouTube Data API est nécessaire pour rechercher de nouvelles vidéos et récupérer les données d’une vidéo qui n’est pas encore en cache. Les vidéos déjà mises en cache restent consultables sans nouvel appel API.</p>'
    . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="save_key">'
PHP;
$new = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>YouTube Data API</h2>'
    . '<p><strong>État :</strong> ' . ($youtubeApiKeyConfigured ? 'Clé API configurée.' : 'Clé API absente.') . '</p>';
if (!$youtubeApiKeyConfigured) {
    $html .= '<p>Une clé YouTube Data API est nécessaire pour rechercher de nouvelles vidéos et récupérer les données d’une vidéo qui n’est pas encore en cache. Les vidéos déjà mises en cache restent consultables sans nouvel appel API.</p>';
}
$html .= '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="save_key">'
PHP;
if (strpos($actions, $old) === false) {
    throw new RuntimeException('YouTube API help block not found');
}
$actions = str_replace($old, $new, $actions);
write_file_or_fail($actionsPath, $actions);

$statsPath = $root . '/admin/stats.php';
$stats = read_file_or_fail($statsPath);
$startMarker = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>Pages publiques adressables</h2><ul>'
PHP;
$start = strpos($stats, $startMarker);
if ($start === false) {
    throw new RuntimeException('Public pages section not found');
}
$endMarker = <<<'PHP'
$html = VIDEOS_localizeAdminText($html);
PHP;
$end = strpos($stats, $endMarker, $start);
if ($end === false) {
    throw new RuntimeException('Stats localization marker not found');
}
$replacement = <<<'PHP'
$html .= '</div>';

PHP;
$stats = substr($stats, 0, $start) . $replacement . substr($stats, $end);
write_file_or_fail($statsPath, $stats);

echo "Admin API help and stats duplication updated.\n";
