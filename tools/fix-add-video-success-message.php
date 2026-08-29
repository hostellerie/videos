<?php
$root = dirname(__DIR__);

$actionsPath = $root . '/admin/actions.php';
$actions = file_get_contents($actionsPath);
if ($actions === false) {
    throw new RuntimeException('Cannot read actions.php');
}
$actions = str_replace(
    "$message = $saved\n                        ? 'Vidéo ajoutée au catalogue permanent et signalée aux consommateurs Geeklog.'\n                        : 'Impossible d’ajouter la vidéo au catalogue permanent.';",
    "$message = $saved\n                        ? 'Vidéo ajoutée au catalogue permanent.'\n                        : 'Impossible d’ajouter la vidéo au catalogue permanent.';",
    $actions,
    $countMessage
);
if ($countMessage !== 1) {
    throw new RuntimeException('Success message not found');
}
$oldRender = <<<'PHP'
if ($message !== '') {
    $message = VIDEOS_localizeAdminText($message);
    $html .= COM_showMessageText($message, '', true);
}
PHP;
$newRender = <<<'PHP'
if ($message !== '') {
    $message = VIDEOS_localizeAdminText($message);
    if ($message === VIDEOS_localizeAdminText('Vidéo ajoutée au catalogue permanent.')) {
        $html .= '<p class="videos-admin-help"><strong>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</strong></p>';
    } else {
        $html .= COM_showMessageText($message, '', true);
    }
}
PHP;
if (strpos($actions, $oldRender) === false) {
    throw new RuntimeException('Message render block not found');
}
$actions = str_replace($oldRender, $newRender, $actions);
if (file_put_contents($actionsPath, $actions) === false) {
    throw new RuntimeException('Cannot write actions.php');
}

$englishPath = $root . '/language/english.php';
$english = file_get_contents($englishPath);
if ($english === false) {
    throw new RuntimeException('Cannot read english.php');
}
$oldTranslation = "  'Vidéo ajoutée au catalogue permanent et signalée aux consommateurs Geeklog.' => 'Video added to the permanent catalogue and signaled to Geeklog consumers.',\n";
$newTranslation = "  'Vidéo ajoutée au catalogue permanent.' => 'Video added to the permanent catalogue.',\n";
if (strpos($english, $oldTranslation) === false) {
    throw new RuntimeException('Old English translation not found');
}
$english = str_replace($oldTranslation, $newTranslation, $english);
if (file_put_contents($englishPath, $english) === false) {
    throw new RuntimeException('Cannot write english.php');
}

echo "Add-video success message updated.\n";
