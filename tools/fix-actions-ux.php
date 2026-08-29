<?php
$root = dirname(__DIR__);
$path = $root . '/admin/actions.php';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Cannot read actions.php');
}

$old = <<<'PHP'
        $html .= videos_admin_action_form('pool_remove', $videoId, 'Retirer du permanent', $token)
            . videos_admin_action_form('pool_exclude', $videoId, 'Exclure du fonds', $token)
            . '</td></tr>';
PHP;
$new = <<<'PHP'
        $html .= videos_admin_action_form('pool_remove', $videoId, 'Retirer de la sélection', $token)
            . videos_admin_action_form('pool_exclude', $videoId, 'Exclure des sélections futures', $token)
            . '</td></tr>';
PHP;
if (strpos($content, $old) === false) {
    throw new RuntimeException('Permanent action labels not found');
}
$content = str_replace($old, $new, $content);

$old = <<<'PHP'
    $html .= '</tbody></table></div>';
}
if (!empty($records['excluded'])) {
PHP;
$new = <<<'PHP'
    $html .= '</tbody></table></div>'
        . '<p class="videos-admin-help"><strong>Retirer de la sélection</strong> enlève la vidéo du catalogue permanent, mais elle pourra être sélectionnée de nouveau. '
        . '<strong>Exclure des sélections futures</strong> l’empêche d’être réintégrée tant qu’elle n’est pas réautorisée.</p>';
}
if (!empty($records['excluded'])) {
PHP;
if (strpos($content, $old) === false) {
    throw new RuntimeException('Permanent help insertion point not found');
}
$content = str_replace($old, $new, $content);

$old = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>YouTube Data API</h2>'
    . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="save_key">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>Nouvelle clé API <input type="password" name="youtube_api_key" maxlength="200" autocomplete="new-password"></label> '
    . '<button type="submit">Enregistrer la clé</button></form>'
PHP;
$new = <<<'PHP'
$youtubeApiKeyConfigured = $bootstrap->getYouTubeApiKey() !== '';
$html .= '<section class="videos-admin-section"><h2>YouTube Data API</h2>'
    . '<p><strong>État :</strong> ' . ($youtubeApiKeyConfigured ? 'Clé API configurée.' : 'Clé API absente.') . '</p>'
    . '<p>Une clé YouTube Data API est nécessaire pour rechercher de nouvelles vidéos et récupérer les données d’une vidéo qui n’est pas encore en cache. Les vidéos déjà mises en cache restent consultables sans nouvel appel API.</p>'
    . '<form class="videos-admin-form" method="post"><input type="hidden" name="videos_action" value="save_key">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<label>' . ($youtubeApiKeyConfigured ? 'Remplacer la clé API' : 'Ajouter une clé API') . ' <input type="password" name="youtube_api_key" maxlength="200" autocomplete="new-password"></label> '
    . '<button type="submit">' . ($youtubeApiKeyConfigured ? 'Remplacer la clé' : 'Enregistrer la clé') . '</button></form>'
PHP;
if (strpos($content, $old) === false) {
    throw new RuntimeException('YouTube API form block not found');
}
$content = str_replace($old, $new, $content);

if (file_put_contents($path, $content) === false) {
    throw new RuntimeException('Cannot write actions.php');
}
echo "Actions UX updated.\n";
