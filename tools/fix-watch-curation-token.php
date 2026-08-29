<?php
$path = dirname(__DIR__) . '/public_html/watch.php';
$content = file_get_contents($path);
if ($content === false) {
    throw new RuntimeException('Cannot read watch.php');
}

$old = "$csrfToken = SEC_createToken();\n";
if (strpos($content, $old) === false) {
    throw new RuntimeException('Initial CSRF token creation not found');
}
$content = str_replace($old, '', $content, $countToken);
if ($countToken !== 1) {
    throw new RuntimeException('Unexpected CSRF token creation count');
}

$marker = <<<'PHP'
$isPermanent = $permanentPool->contains($videoId);
$isPinned = $permanentPool->isPinned($videoId);
PHP;
$replacement = <<<'PHP'
$csrfToken = SEC_createToken();
$curationToken = SEC_createToken();
$isPermanent = $permanentPool->contains($videoId);
$isPinned = $permanentPool->isPinned($videoId);
PHP;
if (strpos($content, $marker) === false) {
    throw new RuntimeException('Post-curation token marker not found');
}
$content = str_replace($marker, $replacement, $content, $countMarker);
if ($countMarker !== 1) {
    throw new RuntimeException('Unexpected post-curation marker count');
}

$content = preg_replace(
    '/videos_watch_curation_form\(([^;\n]+), \$csrfToken\)/',
    'videos_watch_curation_form($1, $curationToken)',
    $content,
    -1,
    $countForms
);
if ($countForms < 4) {
    throw new RuntimeException('Not all curation forms received their own token');
}

if (file_put_contents($path, $content) === false) {
    throw new RuntimeException('Cannot write watch.php');
}
echo "Independent curation token applied to {$countForms} forms.\n";
