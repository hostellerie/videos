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

function remove_between($content, $start, $end)
{
    $startPos = strpos($content, $start);
    if ($startPos === false) {
        throw new RuntimeException('Start marker not found: ' . $start);
    }
    $endPos = strpos($content, $end, $startPos + strlen($start));
    if ($endPos === false) {
        throw new RuntimeException('End marker not found: ' . $end);
    }
    return substr($content, 0, $startPos) . substr($content, $endPos);
}

// Curation is handled on watch.php itself: the CSRF token is created and
// checked by the same endpoint instead of being posted into /admin/.
$watchPath = $root . '/public_html/watch.php';
$watch = read_text($watchPath);
$oldPool = <<<'PHP'
$permanentPool = new Videos_PermanentPool($bootstrap->getStore(), $cache);
$isPermanent = $permanentPool->contains($videoId);
$isPinned = $permanentPool->isPinned($videoId);
PHP;
$newPool = <<<'PHP'
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
                ? $LANG_VIDEOS['curation_saved']
                : $LANG_VIDEOS['curation_failed'];
        }
    }
}
$isPermanent = $permanentPool->contains($videoId);
$isPinned = $permanentPool->isPinned($videoId);
PHP;
if (strpos($watch, $oldPool) === false) {
    throw new RuntimeException('Permanent pool block not found in watch.php');
}
$watch = str_replace($oldPool, $newPool, $watch);

$oldCurationStart = <<<'PHP'
if (SEC_hasRights('videos.moderate')) {
    $quickModerationUrl = $_CONF['site_admin_url'] . '/plugins/videos/quick_moderation.php?video_id=' . rawurlencode($videoId);
    $curationUrl = $_CONF['site_admin_url'] . '/plugins/videos/actions.php';
PHP;
$newCurationStart = <<<'PHP'
if ($curationMessage !== '') {
    $html .= COM_showMessageText($curationMessage, '', true);
}
if (SEC_hasRights('videos.moderate')) {
    $quickModerationUrl = $_CONF['site_admin_url'] . '/plugins/videos/quick_moderation.php?video_id=' . rawurlencode($videoId);
    $curationUrl = $localUrl;
PHP;
if (strpos($watch, $oldCurationStart) === false) {
    throw new RuntimeException('Curation URL block not found in watch.php');
}
$watch = str_replace($oldCurationStart, $newCurationStart, $watch);
$watch = str_replace('name="videos_action"', 'name="videos_curation_action"', $watch);

$oldDelete = <<<'PHP'
$html .= '</fieldset><button type="button" class="videos-rating-delete" data-delete-rating'
    . ($personalRating > 0 ? '' : ' hidden') . ' disabled>'
    . htmlspecialchars($LANG_VIDEOS['rating_delete'], ENT_QUOTES, 'UTF-8')
    . '</button><p class="videos-rating-status" aria-live="polite">'
PHP;
$newDelete = <<<'PHP'
$html .= '</fieldset>';
if ($personalRating > 0) {
    $html .= '<button type="button" class="videos-rating-delete" data-delete-rating disabled>'
        . htmlspecialchars($LANG_VIDEOS['rating_delete'], ENT_QUOTES, 'UTF-8')
        . '</button>';
}
$html .= '<p class="videos-rating-status" aria-live="polite">'
PHP;
if (strpos($watch, $oldDelete) === false) {
    throw new RuntimeException('Rating delete block not found in watch.php');
}
$watch = str_replace($oldDelete, $newDelete, $watch);
write_text($watchPath, $watch);

// Channel moderation now lives only on moderation.php.
$actionsPath = $root . '/admin/actions.php';
$actions = read_text($actionsPath);
$handlerStart = "        } elseif (\$action === 'channel_state' && SEC_hasRights('videos.moderate')) {";
$handlerEnd = "        } elseif (\$action === 'signal_public_pages') {";
$actions = remove_between($actions, $handlerStart, $handlerEnd);
$actions = str_replace("\n\$priorityChannels = \$moderation ? \$moderation->getPriorityChannelIds(100) : array();", '', $actions);
$sectionStart = "if (SEC_hasRights('videos.moderate')) {\n    \$html .= '<section class=\"videos-admin-section\"><h2>Décisions sur les chaînes</h2>';";
$sectionEnd = "\$html .= '<section class=\"videos-admin-section\"><h2>YouTube Data API</h2>'";
$actions = remove_between($actions, $sectionStart, $sectionEnd);
write_text($actionsPath, $actions);

// Moderation is the single source for channel decisions. Signal affected
// public pages whenever an exposure decision changes.
$moderationPath = $root . '/admin/moderation.php';
$moderation = read_text($moderationPath);
$moderation = str_replace("    global \$LANG_VIDEOS;\n    global \$LANG_VIDEOS;\n", "    global \$LANG_VIDEOS;\n", $moderation);
$oldSaved = <<<'PHP'
        if ($saved) {
            $message = $LANG_VIDEOS['moderation_saved'];
            $logger->log(
PHP;
$newSaved = <<<'PHP'
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
if (strpos($moderation, $oldSaved) === false) {
    throw new RuntimeException('Moderation saved block not found');
}
$moderation = str_replace($oldSaved, $newSaved, $moderation);
write_text($moderationPath, $moderation);

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
