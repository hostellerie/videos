<?php
$root = dirname(__DIR__);
$path = $root . '/admin/actions.php';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Cannot read actions.php');
}

$old = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>Indexation des pages existantes</h2>'
    . '<p>Le rattrapage inventorie les pages publiques puis utilise le mode batch d’IndexNow. Il ne génère pas de faux événements de création pour Hello ou les autres plugins.</p>'
    . '<form method="post"><input type="hidden" name="videos_action" value="signal_public_pages">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<button type="submit">Envoyer les pages existantes à IndexNow</button></form></section></div>';
PHP;
$new = <<<'PHP'
if (function_exists('send_to_indexnow')) {
    $html .= '<section class="videos-admin-section"><h2>Indexation des pages existantes</h2>'
        . '<p>Le rattrapage inventorie les pages publiques et les envoie à IndexNow en mode batch.</p>'
        . '<form method="post"><input type="hidden" name="videos_action" value="signal_public_pages">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<button type="submit">Envoyer les pages existantes à IndexNow</button></form></section>';
}
$html .= '</div>';
PHP;
if (strpos($content, $old) === false) {
    throw new RuntimeException('IndexNow section not found');
}
$content = str_replace($old, $new, $content);
if (file_put_contents($path, $content) === false) {
    throw new RuntimeException('Cannot write actions.php');
}
echo "IndexNow actions visibility updated.\n";
