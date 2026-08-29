<?php
$root = dirname(__DIR__);

function read_text($path)
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    return $content;
}

function write_text($path, $content)
{
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

// 1) Handle curation directly on watch.php so the CSRF token is checked on
// the same endpoint that created it.
$watchPath = $root . '/public_html/watch.php';
$watch = read_text($watchPath);
$needle = <<<'PHP'
$permanentPool = new Videos_PermanentPool($bootstrap->getStore(), $cache);
$isPermanent = $permanentPool->contains($videoId);
$isPinned = $permanentPool->isPinned($videoId);
PHP;
$replacement = <<<'PHP'
$permanentPool = new Videos_PermanentPool($bootstrap->getStore(), $cache);
$curationMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    SEC_hasRights('videos.moderate') &&
    isset($_POST['videos_curation_action'])) {
    if (!SEC_checkToken()) {
        $curationMessage = isset($LANG_VIDEOS['history_csrf'])
            ? $LANG_VIDEOS['history_csrf'] : 'The security token has expired.';
    } else {
        $curationAction = COM_applyFilter($_POST['videos_curation_action']);
        $stateMap = array(
            'pool_add' => 'added',
            'pool_pin' => 'pinned',
            'pool_unpin' => 'unpinned',
            'pool_remove' => 'removed'
        );
        if (isset($stateMap[$curationAction])) {
            $globalRanking = $ranking->getGlobal(500);
            $rankingItem = isset($globalRanking[$videoId])
                ? $globalRanking[$videoId] : array();
            $saved = $permanentPool->setManualState(
                $videoId,
                $stateMap[$curationAction],
                $rankingItem
            );
            $curationMessage = $saved
                ? (isset($LANG_VIDEOS['curation_saved'])
                    ? $LANG_VIDEOS['curation_saved'] : 'Editorial decision saved.')
                : (isset($LANG_VIDEOS['curation_failed'])
                    ? $LANG_VIDEOS['curation_failed'] : 'Unable to save this editorial decision.');
        }
    }
}
$isPermanent = $permanentPool->contains($videoId);
$isPinned = $permanentPool->isPinned($videoId);
PHP;
if (strpos($watch, $needle) === false) {
    throw new RuntimeException('Permanent pool block not found in watch.php');
}
$watch = str_replace($needle, $replacement, $watch);

// Show the curation result in context.
$needle = <<<'PHP'
if (SEC_hasRights('videos.moderate')) {
    $quickModerationUrl = $_CONF['site_admin_url'] . '/plugins/videos/quick_moderation.php?video_id=' . rawurlencode($videoId);
    $curationUrl = $_CONF['site_admin_url'] . '/plugins/videos/actions.php';
PHP;
$replacement = <<<'PHP'
if ($curationMessage !== '') {
    $html .= COM_showMessageText($curationMessage, '', true);
}
if (SEC_hasRights('videos.moderate')) {
    $quickModerationUrl = $_CONF['site_admin_url'] . '/plugins/videos/quick_moderation.php?video_id=' . rawurlencode($videoId);
    $curationUrl = $localUrl;
PHP;
if (strpos($watch, $needle) === false) {
    throw new RuntimeException('Curation URL block not found in watch.php');
}
$watch = str_replace($needle, $replacement, $watch);

// Change curation forms to use a dedicated action field interpreted locally.
$watch = str_replace(
    "name=\"videos_action\" value=\"' . htmlspecialchars(\$action, ENT_QUOTES, 'UTF-8') . '\"",
    "name=\"videos_curation_action\" value=\"' . htmlspecialchars(\$action, ENT_QUOTES, 'UTF-8') . '\"",
    $watch
);

// If the helper uses a literal videos_action string instead of the escaped pattern.
$watch = str_replace("name=\"videos_action\" value=\"' . \$action . '\"", "name=\"videos_curation_action\" value=\"' . \$action . '\"", $watch);

// Do not render the delete-rating button at all when no personal rating exists.
$old = <<<'PHP'
$html .= '</fieldset><button type="button" class="videos-rating-delete" data-delete-rating'
    . ($personalRating > 0 ? '' : ' hidden') . ' disabled>'
    . htmlspecialchars($LANG_VIDEOS['rating_delete'], ENT_QUOTES, 'UTF-8')
    . '</button><p class="videos-rating-status" aria-live="polite">'
PHP;
$new = <<<'PHP'
$html .= '</fieldset>';
if ($personalRating > 0) {
    $html .= '<button type="button" class="videos-rating-delete" data-delete-rating disabled>'
        . htmlspecialchars($LANG_VIDEOS['rating_delete'], ENT_QUOTES, 'UTF-8')
        . '</button>';
}
$html .= '<p class="videos-rating-status" aria-live="polite">'
PHP;
if (strpos($watch, $old) === false) {
    throw new RuntimeException('Rating delete button block not found in watch.php');
}
$watch = str_replace($old, $new, $watch);
write_text($watchPath, $watch);

// 2) Remove duplicate channel-state processing and UI from actions.php.
$actionsPath = $root . '/admin/actions.php';
$actions = read_text($actionsPath);
$actions = preg_replace(
    "/\n        } elseif \(\$action === 'channel_state' && SEC_hasRights\('videos\.moderate'\)\) \{.*?\n        } elseif \(\$action === 'signal_public_pages'\) \{/s",
    "\n        } elseif (\$action === 'signal_public_pages') {",
    $actions,
    1,
    $countHandler
);
if ($countHandler !== 1) {
    throw new RuntimeException('channel_state handler not removed from actions.php');
}
$actions = str_replace("\n\$priorityChannels = \$moderation ? \$moderation->getPriorityChannelIds(100) : array();", '', $actions);
$actions = preg_replace(
    "/\nif \(SEC_hasRights\('videos\.moderate'\)\) \{\n    \$html \.= '<section class=\\\"videos-admin-section\\\"><h2>Décisions sur les chaînes<\/h2>'.*?\n\}\n\n\$html \.= '<section class=\\\"videos-admin-section\\\"><h2>YouTube Data API<\/h2>'/s",
    "\n\$html .= '<section class=\"videos-admin-section\"><h2>YouTube Data API</h2>'",
    $actions,
    1,
    $countSection
);
if ($countSection !== 1) {
    throw new RuntimeException('Channel decisions section not removed from actions.php');
}
write_text($actionsPath, $actions);

// 3) Small cleanup in moderation.php and signal public structural changes when
// a moderation decision changes a channel/video exposure.
$moderationPath = $root . '/admin/moderation.php';
$moderation = read_text($moderationPath);
$moderation = str_replace("    global \$LANG_VIDEOS;\n    global \$LANG_VIDEOS;\n", "    global \$LANG_VIDEOS;\n", $moderation);
$needle = <<<'PHP'
        if ($saved) {
            $message = $LANG_VIDEOS['moderation_saved'];
            $logger->log(
PHP;
$replacement = <<<'PHP'
        if ($saved) {
            $message = $LANG_VIDEOS['moderation_saved'];
            if ($entity === 'video' && Videos_Validator::youtubeVideoId($id)) {
                VIDEOS_signalSaved($id);
                VIDEOS_signalSaved('catalogue');
                VIDEOS_signalSaved('rankings:videos');
            } elseif ($entity === 'channel' && Videos_Validator::youtubeChannelId($id)) {
                VIDEOS_signalSaved('channel:' . $id);
                VIDEOS_signalSaved('channels');
                VIDEOS_signalSaved('rankings:channels');
                VIDEOS_signalSaved('rankings:videos');
                VIDEOS_signalSaved('catalogue');
            }
            $logger->log(
PHP;
if (strpos($moderation, $needle) === false) {
    throw new RuntimeException('Moderation saved block not found');
}
$moderation = str_replace($needle, $replacement, $moderation);
write_text($moderationPath, $moderation);

// Add localized curation feedback.
foreach (array(
    'english.php' => array(
        'curation_saved' => 'Editorial decision saved.',
        'curation_failed' => 'Unable to save this editorial decision.'
    ),
    'french_france.php' => array(
        'curation_saved' => 'Décision éditoriale enregistrée.',
        'curation_failed' => 'Impossible d’enregistrer cette décision.'
    )
) as $file => $entries) {
    $path = $root . '/language/' . $file;
    $content = read_text($path);
    foreach ($entries as $key => $value) {
        if (strpos($content, "'" . $key . "'") !== false) {
            continue;
        }
        $content = str_replace(
            "    'plugin_name' => 'Videos',\n",
            "    'plugin_name' => 'Videos',\n    '" . $key . "' => " . var_export($value, true) . ",\n",
            $content
        );
    }
    write_text($path, $content);
}

echo "Watch curation, rating button and moderation consolidation applied.\n";
