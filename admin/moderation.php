<?php

require_once '../../../lib-common.php';

if (!SEC_hasRights('videos.moderate')) {
    echo COM_createHTMLDocument(
        COM_showMessageText($LANG_VIDEOS['access_denied'], '', true),
        array(
            'pagetitle' => $LANG_VIDEOS['moderation_title'],
            'headercode' => VIDEOS_adminHeaderCode()
        )
    );
    exit;
}

$bootstrap = new Videos_Bootstrap($_CONF);
if (!$bootstrap->isReady()) {
    echo COM_createHTMLDocument(
        COM_showMessageText(
            $LANG_VIDEOS['moderation_storage_error'],
            '',
            true
        ),
        array(
            'pagetitle' => $LANG_VIDEOS['moderation_title'],
            'headercode' => VIDEOS_adminHeaderCode()
        )
    );
    exit;
}

$store = $bootstrap->getStore();
$moderation = new Videos_Moderation($store);
$logger = new Videos_Logger($store);
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SEC_checkToken()) {
        $message = $LANG_VIDEOS['history_csrf'];
    } else {
        $entity = isset($_POST['entity'])
            ? COM_applyFilter($_POST['entity']) : '';
        $id = isset($_POST['entity_id'])
            ? trim((string) $_POST['entity_id']) : '';
        if ($entity === 'channel' && isset($_POST['known_channel_id'])) {
            $knownChannelId = COM_applyFilter($_POST['known_channel_id']);
            if (Videos_Validator::youtubeChannelId($knownChannelId)) {
                $id = $knownChannelId;
            }
        }
        $state = isset($_POST['state'])
            ? COM_applyFilter($_POST['state']) : '';
        $reason = isset($_POST['reason'])
            ? trim((string) $_POST['reason']) : '';
        $actorHash = hash_hmac(
            'sha256',
            'moderator:' . (int) $_USER['uid'],
            $bootstrap->getSecret()
        );
        $saved = false;
        if ($entity === 'video') {
            $saved = $moderation->setVideoState($id, $state, $reason, $actorHash);
        } elseif ($entity === 'channel') {
            $saved = $moderation->setChannelState($id, $state, $reason, $actorHash);
        }
        if ($saved) {
            $message = $LANG_VIDEOS['moderation_saved'];
            $logger->log(
                'info',
                'moderation.' . $entity . '.' . $state,
                'A Videos moderation state was changed.',
                array(
                    'video_id' => $entity === 'video' ? $id : '',
                    'channel_id' => $entity === 'channel' ? $id : ''
                )
            );
        } else {
            $message = $LANG_VIDEOS['moderation_invalid'];
        }
    }
}

$videoRecords = $moderation->listRecords('video', 500);
$channelRecords = $moderation->listRecords('channel', 500);
$cache = new Videos_Cache($store);
$knownChannels = $cache->listKnownChannels(500);
$token = SEC_createToken();

$html = '<div class="videos-admin"><h1>'
    . htmlspecialchars($LANG_VIDEOS['moderation_title'], ENT_QUOTES, 'UTF-8')
    . '</h1>'
    . videos_moderation_nav($_CONF, 'moderation');
if ($message !== '') {
    $html .= COM_showMessageText($message, '', true);
}

$html .= '<section class="videos-admin-section"><h2>'
    . htmlspecialchars($LANG_VIDEOS['moderate_video'], ENT_QUOTES, 'UTF-8')
    . '</h2>' . videos_moderation_form(
        'video',
        array(
            'blocked' => $LANG_VIDEOS['moderation_state_blocked'],
            'neutral' => $LANG_VIDEOS['moderation_state_neutral']
        ),
        $token,
        $LANG_VIDEOS,
        array()
    ) . '</section><section class="videos-admin-section"><h2>'
    . htmlspecialchars($LANG_VIDEOS['moderate_channel'], ENT_QUOTES, 'UTF-8')
    . '</h2>' . videos_moderation_form(
        'channel',
        array(
            'allowed' => $LANG_VIDEOS['moderation_state_allowed'],
            'priority' => $LANG_VIDEOS['moderation_state_priority'],
            'blocked' => $LANG_VIDEOS['moderation_state_blocked'],
            'disabled' => $LANG_VIDEOS['moderation_state_disabled'],
            'neutral' => $LANG_VIDEOS['moderation_state_neutral']
        ),
        $token,
        $LANG_VIDEOS,
        $knownChannels
    ) . '</section>';

$html .= videos_moderation_table(
    $videoRecords,
    'video',
    $cache,
    $token,
    $LANG_VIDEOS
);
$html .= videos_moderation_table(
    $channelRecords,
    'channel',
    $cache,
    $token,
    $LANG_VIDEOS
);
$html .= '</div>';

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $LANG_VIDEOS['moderation_title'],
        'headercode' => VIDEOS_adminHeaderCode()
    )
);

function videos_moderation_nav($configuration, $active)
{
    global $LANG_VIDEOS;
    global $LANG_VIDEOS;
    $base = $configuration['site_admin_url'] . '/plugins/videos/';
    $items = array(
        'overview' => array('index.php', $LANG_VIDEOS['admin_nav_overview']),
        'actions' => array('actions.php', $LANG_VIDEOS['admin_nav_actions']),
        'stats' => array('stats.php', $LANG_VIDEOS['admin_nav_stats']),
        'moderation' => array('moderation.php', $LANG_VIDEOS['admin_nav_moderation'])
    );
    $html = '<nav class="videos-navigation" aria-label="' . htmlspecialchars($LANG_VIDEOS['admin_navigation'], ENT_QUOTES, 'UTF-8') . '"><ul>';
    foreach ($items as $key => $item) {
        $html .= '<li><a href="'
            . htmlspecialchars($base . $item[0], ENT_QUOTES, 'UTF-8') . '"'
            . ($key === $active ? ' class="is-active" aria-current="page"' : '')
            . '>' . htmlspecialchars($item[1], ENT_QUOTES, 'UTF-8') . '</a></li>';
    }
    return $html . '</ul></nav>';
}

function videos_moderation_form(
    $entity,
    $states,
    $token,
    $language,
    $knownChannels
)
{
    $html = '<form method="post" action="" class="videos-admin-form">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="entity" value="'
        . htmlspecialchars($entity, ENT_QUOTES, 'UTF-8') . '">';
    if ($entity === 'channel') {
        $html .= '<label>'
            . htmlspecialchars($language['known_channel'], ENT_QUOTES, 'UTF-8')
            . '<select name="known_channel_id"><option value="">'
            . htmlspecialchars($language['choose_known_channel'], ENT_QUOTES, 'UTF-8')
            . '</option>';
        foreach ($knownChannels as $channel) {
            $channelId = isset($channel['channel_id'])
                ? $channel['channel_id'] : '';
            $channelTitle = isset($channel['title'])
                ? $channel['title'] : $channelId;
            if (!Videos_Validator::youtubeChannelId($channelId)) {
                continue;
            }
            $html .= '<option value="'
                . htmlspecialchars($channelId, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars(
                    $channelTitle . ' — ' . $channelId,
                    ENT_QUOTES,
                    'UTF-8'
                ) . '</option>';
        }
        $html .= '</select></label><p class="videos-form-help">'
            . htmlspecialchars(
                count($knownChannels) > 0
                    ? $language['known_channel_help']
                    : $language['known_channel_empty'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</p><details class="videos-advanced-field"><summary>'
            . htmlspecialchars($language['manual_channel_entry'], ENT_QUOTES, 'UTF-8')
            . '</summary><label>'
            . htmlspecialchars($language['channel_id'], ENT_QUOTES, 'UTF-8')
            . '<input type="text" name="entity_id" maxlength="64">'
            . '</label></details>';
    } else {
        $html .= '<label>'
            . htmlspecialchars($language['video_id'], ENT_QUOTES, 'UTF-8')
            . '<input type="text" name="entity_id" required maxlength="64">'
            . '</label>';
    }
    $html .= '<label>'
        . htmlspecialchars($language['moderation_state'], ENT_QUOTES, 'UTF-8')
        . '<select name="state">';
    foreach ($states as $value => $label) {
        $html .= '<option value="'
            . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }
    return $html . '</select></label><label>'
        . htmlspecialchars($language['moderation_reason'], ENT_QUOTES, 'UTF-8')
        . '<input type="text" name="reason" maxlength="250"></label>'
        . '<button type="submit">'
        . htmlspecialchars($language['moderation_save'], ENT_QUOTES, 'UTF-8')
        . '</button></form>';
}

function videos_moderation_table(
    $records,
    $entity,
    $cache,
    $token,
    $language
) {
    $titleKey = $entity === 'video'
        ? 'moderated_videos' : 'moderated_channels';
    $html = '<section class="videos-admin-section"><h2>'
        . htmlspecialchars($language[$titleKey], ENT_QUOTES, 'UTF-8')
        . '</h2>';
    if (count($records) === 0) {
        return $html . '<p>'
            . htmlspecialchars(
                $language['moderation_no_records'],
                ENT_QUOTES,
                'UTF-8'
            ) . '</p></section>';
    }
    $html .= '<div class="videos-admin-table-wrap"><table class="'
        . 'admin-list videos-admin-table"><thead><tr><th>'
        . htmlspecialchars($language['moderation_item'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($language['moderation_state'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($language['moderation_reason'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($language['moderation_date'], ENT_QUOTES, 'UTF-8')
        . '</th><th>'
        . htmlspecialchars($language['moderation_action'], ENT_QUOTES, 'UTF-8')
        . '</th></tr></thead><tbody>';
    foreach ($records as $record) {
        $id = isset($record['id']) ? $record['id'] : '';
        $label = videos_moderation_label($entity, $id, $cache);
        $stateKey = 'moderation_state_' . $record['state'];
        $stateLabel = isset($language[$stateKey])
            ? $language[$stateKey] : $record['state'];
        $html .= '<tr><td>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
            . '<br><code>' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8')
            . '</code></td><td>'
            . htmlspecialchars($stateLabel, ENT_QUOTES, 'UTF-8')
            . '</td><td>' . htmlspecialchars(
                isset($record['reason']) ? $record['reason'] : '',
                ENT_QUOTES,
                'UTF-8'
            ) . '</td><td>' . htmlspecialchars(
                isset($record['set_at']) ? $record['set_at'] : '',
                ENT_QUOTES,
                'UTF-8'
            ) . '</td><td><form method="post" action="" onsubmit="'
            . 'return confirm(' . htmlspecialchars(
                json_encode($language['moderation_neutral_confirm']),
                ENT_QUOTES,
                'UTF-8'
            ) . ');"><input type="hidden" name="' . CSRF_TOKEN
            . '" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8')
            . '"><input type="hidden" name="entity" value="'
            . htmlspecialchars($entity, ENT_QUOTES, 'UTF-8')
            . '"><input type="hidden" name="entity_id" value="'
            . htmlspecialchars($id, ENT_QUOTES, 'UTF-8')
            . '"><input type="hidden" name="state" value="neutral">'
            . '<button type="submit">'
            . htmlspecialchars($language['moderation_remove'], ENT_QUOTES, 'UTF-8')
            . '</button></form></td></tr>';
    }
    return $html . '</tbody></table></div></section>';
}

function videos_moderation_label($entity, $id, $cache)
{
    $resource = $entity === 'video'
        ? $cache->getVideo($id, true)
        : $cache->getChannel($id, true);
    return is_array($resource) && isset($resource['snippet']['title'])
        ? (string) $resource['snippet']['title'] : $id;
}
