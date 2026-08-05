<?php

require_once '../../../lib-common.php';
require_once $_CONF['path'] . 'plugins/videos/autoinstall.php';

if (!SEC_inGroup('Root')) {
    $content = COM_showMessageText(
        'Cette opération est réservée au groupe Root.',
        'Réparation du plugin Videos',
        true
    );
    echo COM_createHTMLDocument(
        $content,
        array(
            'pagetitle' => 'Réparation du plugin Videos',
            'headercode' => '<meta name="robots" '
                . 'content="noindex,nofollow">' . "\n"
        )
    );
    exit;
}

$pluginCount = (int) DB_count($_TABLES['plugins'], 'pi_name', 'videos');
$groupId = DB_getItem(
    $_TABLES['groups'],
    'grp_id',
    "grp_name = 'Videos Admin'"
);
$removal = plugin_autouninstall_videos();
$featureIds = array();
foreach ($removal['features'] as $featureName) {
    $escapedName = DB_escapeString($featureName);
    $featureId = DB_getItem(
        $_TABLES['features'],
        'ft_id',
        "ft_name = '$escapedName'"
    );
    if (!empty($featureId)) {
        $featureIds[$featureName] = (int) $featureId;
    }
}
$configurationCount = (int) DB_count(
    $_TABLES['conf_values'],
    'group_name',
    'videos'
);

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($pluginCount > 0) {
        $message = 'Nettoyage refusé : le plugin est enregistré dans Geeklog.';
    } elseif (!SEC_checkToken()) {
        $message = 'Le jeton de sécurité a expiré.';
    } elseif (!isset($_POST['confirm_cleanup']) ||
              $_POST['confirm_cleanup'] !== 'videos') {
        $message = 'La confirmation est invalide.';
    } else {
        $ok = videos_repair_cleanup(
            $groupId,
            $removal['features']
        );
        if ($ok) {
            COM_errorLog(
                'Videos repair: incomplete installation remnants removed.',
                1
            );
            $message = 'Les résidus de l’installation incomplète ont été supprimés. '
                . 'Les fichiers JSON ont été conservés.';
            $groupId = '';
            $featureIds = array();
            $configurationCount = 0;
        } else {
            $message = 'Le nettoyage a rencontré une erreur de base de données. '
                . 'Consultez le journal Geeklog.';
        }
    }
}

$html = '<div class="videos-repair"><h1>Réparation du plugin Videos</h1>';
if ($message !== '') {
    $html .= COM_showMessageText($message, '', true);
}
$html .= '<p>Cette page ne supprime jamais les données JSON persistantes '
    . 'du plugin, qu’elles se trouvent dans l’ancien dossier '
    . '<code>path_data/videos/</code> ou dans le nouveau dossier frère '
    . '<code>path_data-videos/</code>.</p>'
    . '<h2>Diagnostic</h2><ul>'
    . '<li>Plugin enregistré : ' . ($pluginCount > 0 ? 'oui' : 'non') . '</li>'
    . '<li>Groupe Videos Admin : ' . (!empty($groupId) ? 'présent' : 'absent') . '</li>'
    . '<li>Droits résiduels : ' . count($featureIds) . '</li>'
    . '<li>Valeurs de configuration : ' . (int) $configurationCount . '</li>'
    . '</ul>';

if ($pluginCount === 0 &&
    (!empty($groupId) || count($featureIds) > 0 || $configurationCount > 0)) {
    $token = SEC_createToken();
    $html .= '<form method="post" action="">'
        . '<input type="hidden" name="' . CSRF_TOKEN . '" value="'
        . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">'
        . '<input type="hidden" name="confirm_cleanup" value="videos">'
        . '<p><strong>Cette action supprimera uniquement les résidus '
        . 'd’installation identifiés ci-dessus.</strong></p>'
        . '<button type="submit">Nettoyer les résidus Videos</button>'
        . '</form>';
} elseif ($pluginCount > 0) {
    $html .= '<p>Utilisez la désinstallation normale de Geeklog ; '
        . 'la réparation est volontairement désactivée.</p>';
} else {
    $html .= '<p>Aucun résidu n’a été détecté.</p>';
}
$html .= '</div>';

echo COM_createHTMLDocument(
    $html,
    array(
        'pagetitle' => 'Réparation du plugin Videos',
        'headercode' => '<meta name="robots" '
            . 'content="noindex,nofollow">' . "\n"
    )
);

function videos_repair_cleanup($groupId, $features)
{
    global $_TABLES;

    if (!empty($groupId)) {
        $groupId = (int) $groupId;
        DB_delete($_TABLES['groups'], 'grp_id', $groupId);
        if (DB_error()) {
            return false;
        }
        DB_delete(
            $_TABLES['group_assignments'],
            'ug_main_grp_id',
            $groupId
        );
        if (DB_error()) {
            return false;
        }
    }

    foreach ($features as $featureName) {
        SEC_removeFeatureFromDB($featureName);
        if (DB_error()) {
            return false;
        }
    }

    DB_delete($_TABLES['conf_values'], 'group_name', 'videos');
    if (DB_error()) {
        return false;
    }
    DB_delete($_TABLES['topic_assignments'], 'type', 'videos');
    if (DB_error()) {
        return false;
    }
    DB_delete($_TABLES['comments'], 'type', 'videos');
    if (DB_error()) {
        return false;
    }

    return true;
}
