<?php

require_once '../../../lib-common.php';

if (!SEC_hasRights('videos.moderate')) {
    echo COM_createHTMLDocument(
        COM_showMessageText($LANG_VIDEOS['access_denied'], '', true),
        array(
            'pagetitle' => $LANG_VIDEOS['moderation_quick_actions'],
            'headercode' => VIDEOS_adminHeaderCode()
        )
    );
    exit;
}

$entity = isset($_GET['entity'])
    ? COM_applyFilter($_GET['entity']) : '';
$entityId = isset($_GET['entity_id'])
    ? trim((string) $_GET['entity_id']) : '';
$videoId = isset($_GET['video_id'])
    ? COM_applyFilter($_GET['video_id']) : '';
$validEntity = ($entity === 'video' &&
        Videos_Validator::youtubeVideoId($entityId)) ||
    ($entity === 'channel' &&
        Videos_Validator::youtubeChannelId($entityId));
if (!$validEntity || !Videos_Validator::youtubeVideoId($videoId)) {
    echo COM_createHTMLDocument(
        COM_showMessageText($LANG_VIDEOS['moderation_invalid'], '', true),
        array(
            'pagetitle' => $LANG_VIDEOS['moderation_quick_actions'],
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
            'pagetitle' => $LANG_VIDEOS['moderation_quick_actions'],
            'headercode' => VIDEOS_adminHeaderCode()
        )
    );
    exit;
}

$store = $bootstrap->getStore();
$cache = new Videos_Cache($store);
$resource = $entity === 'video'
    ? $cache->getVideo($entityId, true)
    : $cache->getChannel($entityId, true);
$label = is_array($resource) && isset($resource['snippet']['title'])
    ? (string) $resource['snippet']['title'] : $entityId;
$watchUrl = $_CONF['site_url'] . '/videos/watch.php?v='
    . rawurlencode($videoId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!SEC_checkToken()) {
        exit;
    }
    $actorHash = hash_hmac(
        'sha256',
        'moderator:' . (int) $_USER['uid'],
        $bootstrap->getSecret()
    );
    $moderation = new Videos_Moderation($store);
    if ($entity === 'video') {
        $saved = $moderation->setVideoState(
            $entityId,
            'blocked',
            $LANG_VIDEOS['moderation_quick_reason_video'],
            $actorHash
        );
    } else {
        $saved = $moderation->setChannelState(
            $entityId,
            'blocked',
            $LANG_VIDEOS['moderation_quick_reason_channel'],
            $actorHash
        );
    }
    if ($saved) {
        $logger = new Videos_Logger($store);
        $logger->log(
            'info',
            'moderation.' . $entity . '.blocked',
            'A Videos item was blocked after explicit confirmation.',
            array(
                'video_id' => $entity === 'video' ? $entityId : '',
                'channel_id' => $entity === 'channel' ? $entityId : ''
            )
        );
        header('Location: ' . $_CONF['site_url'] . '/videos/index.php');
        exit;
    }
    $message = COM_showMessageText(
        $LANG_VIDEOS['moderation_quick_failed'],
        '',
        true
    );
} else {
    $message = '';
}

$token = SEC_createToken();
$confirmKey = $entity === 'video'
    ? 'moderation_block_video_confirm'
    : 'moderation_block_channel_confirm';
$buttonKey = $entity === 'video'
    ? 'moderation_block_video'
    : 'moderation_block_channel';
$html = '<div class="videos-admin videos-quick-moderation">'
    . '<h1>' . htmlspecialchars(
        $LANG_VIDEOS['moderation_quick_actions'],
        ENT_QUOTES,
        'UTF-8'
    ) . '</h1>' . $message
    . '<section class="videos-admin-section"><h2>'
    . htmlspecialchars($LANG_VIDEOS[$buttonKey], ENT_QUOTES, 'UTF-8')
    . '</h2><p><strong>'
    . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    . '</strong><br><code>'
    . htmlspecialchars($entityId, ENT_QUOTES, 'UTF-8')
    . '</code></p><p>'
    . htmlspecialchars($LANG_VIDEOS[$confirmKey], ENT_QUOTES, 'UTF-8')
    . '</p><div class="videos-confirm-actions"><form method="post" action="">'
    . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
    . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
    . '<button type="submit" class="videos-danger-action">'
    . htmlspecialchars($LANG_VIDEOS[$buttonKey], ENT_QUOTES, 'UTF-8')
    . '</button></form><a href="'
    . htmlspecialchars($watchUrl, ENT_QUOTES, 'UTF-8') . '">'
    . htmlspecialchars($LANG_VIDEOS['cancel'], ENT_QUOTES, 'UTF-8')
    . '</a></div></section></div>';

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => $LANG_VIDEOS['moderation_quick_actions'],
        'headercode' => VIDEOS_adminHeaderCode()
    )
);
