<?php

$root = dirname(__DIR__);

function videos_i18n_read($path)
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    return $content;
}

function videos_i18n_write($path, $content)
{
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

$helper = <<<'PHP'

function VIDEOS_localizeAdminText($text)
{
    global $LANG_VIDEOS_ADMIN_TEXT;
    if (!is_array($LANG_VIDEOS_ADMIN_TEXT) || $text === '') {
        return $text;
    }
    return strtr((string) $text, $LANG_VIDEOS_ADMIN_TEXT);
}
PHP;

$functionsPath = $root . '/functions.inc';
$functions = videos_i18n_read($functionsPath);
if (strpos($functions, 'function VIDEOS_localizeAdminText(') === false) {
    $marker = "\nfunction plugin_getheadercode_videos()\n";
    $functions = str_replace($marker, $helper . $marker, $functions);
    videos_i18n_write($functionsPath, $functions);
}

$english = array(
    'Le stockage du plugin Videos est indisponible. Consultez les outils de réparation.' => 'Videos plugin storage is unavailable. Use the repair tools.',
    'Ouvrir les outils de réparation' => 'Open repair tools',
    'Le stockage du plugin Videos est indisponible.' => 'Videos plugin storage is unavailable.',
    'Vue générale' => 'Overview',
    'Ajouter ou épingler des vidéos, gérer les chaînes, l’API YouTube, la maintenance et IndexNow.' => 'Add or pin videos, manage channels, the YouTube API, maintenance and IndexNow.',
    'Statistiques' => 'Statistics',
    'Consulter le réservoir, les classements, le fonds permanent, le quota et les caches.' => 'Review the reservoir, rankings, permanent catalogue, quota and caches.',
    'Modération' => 'Moderation',
    'Bloquer, autoriser ou prioriser des vidéos et des chaînes.' => 'Block, allow or prioritize videos and channels.',
    'Repères' => 'At a glance',
    'Vidéos dans le réservoir' => 'Videos in reservoir',
    'Vidéos dans le classement global' => 'Videos in global ranking',
    'Chaînes dans le classement' => 'Channels in ranking',
    'Chaînes prioritaires' => 'Priority channels',
    'Vidéos dans le catalogue permanent' => 'Videos in permanent catalogue',
    'Vidéos épinglées' => 'Pinned videos',
    'Voir toutes les statistiques' => 'View all statistics',
    'Pages publiques' => 'Public pages',
    'Catalogue vidéo' => 'Video catalogue',
    'Classement global des vidéos' => 'Global video ranking',
    'Classement des chaînes' => 'Channel ranking',
    'Le jeton de sécurité a expiré. Veuillez recommencer.' => 'The security token has expired. Please try again.',
    'La clé YouTube Data API a été enregistrée.' => 'The YouTube Data API key was saved.',
    'La clé API est invalide.' => 'The API key is invalid.',
    'La requête de test est invalide.' => 'The test query is invalid.',
    'La recherche de test a échoué.' => 'The test search failed.',
    ' vidéo(s) valide(s) trouvée(s).' => ' valid video(s) found.',
    'La requête d’amorçage est invalide.' => 'The seed query is invalid.',
    ' vidéo(s) ajoutée(s) au réservoir.' => ' video(s) added to the reservoir.',
    'L’amorçage du réservoir a échoué.' => 'Reservoir seeding failed.',
    ' entrée(s) de cache supprimée(s).' => ' cache entries deleted.',
    'Nettoyage partiel : ' => 'Partial cleanup: ',
    ' supprimée(s), ' => ' deleted, ',
    ' échec(s).' => ' failure(s).',
    'La reconstruction des classements a échoué.' => 'Ranking rebuild failed.',
    'Classements reconstruits : ' => 'Rankings rebuilt: ',
    ' vidéo(s) classée(s).' => ' ranked video(s).',
    'La reconstruction du catalogue permanent a échoué.' => 'Permanent catalogue rebuild failed.',
    'Le catalogue permanent a été reconstruit.' => 'The permanent catalogue was rebuilt.',
    'ID ou URL YouTube invalide.' => 'Invalid YouTube ID or URL.',
    'La vidéo est introuvable, privée, non intégrable ou refusée par la politique du plugin.' => 'The video could not be found, is private, cannot be embedded, or is rejected by plugin policy.',
    'Cette vidéo est actuellement bloquée par la modération.' => 'This video is currently blocked by moderation.',
    'Vidéo ajoutée au catalogue permanent et signalée aux consommateurs Geeklog.' => 'Video added to the permanent catalogue and signaled to Geeklog consumers.',
    'Impossible d’ajouter la vidéo au catalogue permanent.' => 'The video could not be added to the permanent catalogue.',
    'Décision éditoriale enregistrée.' => 'Editorial decision saved.',
    'Impossible d’enregistrer cette décision.' => 'This decision could not be saved.',
    'Décision de chaîne invalide.' => 'Invalid channel decision.',
    'Décision éditoriale depuis la page Actions Videos' => 'Editorial decision from the Videos Actions page',
    'Décision sur la chaîne enregistrée.' => 'Channel decision saved.',
    'Impossible de modifier la chaîne.' => 'The channel could not be updated.',
    'Aucune URL publique Videos à signaler.' => 'No public Videos URL is available to submit.',
    'Le batch IndexNow n’a pas pu être envoyé.' => 'The IndexNow batch could not be sent.',
    ' URL(s) Videos envoyée(s) à IndexNow en un seul batch.' => ' Videos URL(s) sent to IndexNow in one batch.',
    'Le plugin IndexNow n’est pas disponible. Aucune fausse création de contenu n’a été émise.' => 'The IndexNow plugin is not available. No fake content creation event was emitted.',
    'Videos — Actions' => 'Videos — Actions',
    'Curation vidéo' => 'Video curation',
    'Ajoutez directement une vidéo par son ID ou son URL YouTube. Elle est récupérée, mise en cache, ajoutée au catalogue permanent puis signalée via les événements Geeklog.' => 'Add a video directly by YouTube ID or URL. It is fetched, cached, added to the permanent catalogue, then signaled through Geeklog events.',
    'ID ou URL YouTube' => 'YouTube ID or URL',
    ' ou https://youtu.be/…' => ' or https://youtu.be/…',
    'Ajouter au catalogue permanent' => 'Add to permanent catalogue',
    'Catalogue permanent' => 'Permanent catalogue',
    'Aucune vidéo conservée.' => 'No video is currently retained.',
    'Vidéo' => 'Video',
    'État' => 'Status',
    'Épinglée' => 'Pinned',
    'Permanente' => 'Permanent',
    'Désépingler' => 'Unpin',
    'Épingler' => 'Pin',
    'Retirer du permanent' => 'Remove from permanent catalogue',
    'Exclure du fonds' => 'Exclude from pool',
    'Vidéos exclues du fonds' => 'Videos excluded from pool',
    'Réautoriser' => 'Allow again',
    'Reconstruire le catalogue permanent' => 'Rebuild permanent catalogue',
    'Décisions sur les chaînes' => 'Channel decisions',
    'Aucune chaîne prioritaire.' => 'No priority channel.',
    'ID chaîne' => 'Channel ID',
    'Décision' => 'Decision',
    'Prioritaire' => 'Priority',
    'Autorisée' => 'Allowed',
    'Neutre' => 'Neutral',
    'Bloquée' => 'Blocked',
    'Désactivée' => 'Disabled',
    'Appliquer' => 'Apply',
    'Nouvelle clé API' => 'New API key',
    'Enregistrer la clé' => 'Save key',
    'Recherche de test' => 'Test search',
    'Tester la recherche' => 'Test search',
    'Requête d’amorçage' => 'Seed query',
    'Amorcer le réservoir' => 'Seed reservoir',
    'Maintenance' => 'Maintenance',
    'Reconstruire les classements' => 'Rebuild rankings',
    'Recherches' => 'Searches',
    'Vidéos' => 'Videos',
    'Chaînes' => 'Channels',
    'Disponibilité' => 'Availability',
    'Tous' => 'All',
    'Vider le cache' => 'Clear cache',
    'Outils de réparation' => 'Repair tools',
    'Indexation des pages existantes' => 'Index existing pages',
    'Le rattrapage inventorie les pages publiques puis utilise le mode batch d’IndexNow. Il ne génère pas de faux événements de création pour Hello ou les autres plugins.' => 'The catch-up inventories public pages and then uses IndexNow batch mode. It does not generate fake creation events for Hello or other plugins.',
    'Envoyer les pages existantes à IndexNow' => 'Send existing pages to IndexNow',
    'Videos — Statistiques' => 'Videos — Statistics',
    'Contenu public et éditorial' => 'Public and editorial content',
    'Corpus de découverte local' => 'Local discovery corpus',
    'Vidéos recherchables' => 'Searchable videos',
    'Corpus public utilisé par Geeklog et le catalogue' => 'Public corpus used by Geeklog and the catalogue',
    'Classement global' => 'Global ranking',
    'Vidéos ayant des signaux locaux' => 'Videos with local signals',
    'Chaînes classées' => 'Ranked channels',
    'Chaînes issues du classement local' => 'Channels from the local ranking',
    'Décisions éditoriales actives' => 'Active editorial decisions',
    'Vidéos conservées durablement' => 'Videos retained permanently',
    'Sélections fortes, y compris les anciens épinglages 0.17' => 'Strong selections, including legacy 0.17 pins',
    'Exclues du fonds' => 'Excluded from pool',
    'Exclusions éditoriales explicites' => 'Explicit editorial exclusions',
    'Activité YouTube API' => 'YouTube API activity',
    'Recherches aujourd’hui' => 'Searches today',
    'Appels search.list' => 'search.list calls',
    'Appels vidéos' => 'Video calls',
    'Détails videos.list' => 'videos.list details',
    'Appels chaînes' => 'Channel calls',
    'Détails channels.list' => 'channels.list details',
    'Quota suspendu' => 'Quota suspended',
    'Protection locale du quota' => 'Local quota protection',
    'Dernier succès' => 'Last success',
    'Dernière réponse API valide' => 'Last valid API response',
    'Résultats de recherche' => 'Search results',
    'Informations des vidéos' => 'Video information',
    'Informations des chaînes' => 'Channel information',
    'Vérifications de disponibilité' => 'Availability checks',
    'Entrées' => 'Entries',
    'Volume' => 'Size',
    'Entrée la plus récente' => 'Latest entry',
    'Intégration Geeklog' => 'Geeklog integration',
    'Recherche native' => 'Native search',
    'Active via ' => 'Enabled through ',
    'Statistiques natives' => 'Native statistics',
    'Recherche publique' => 'Public search',
    'Le même corpus local est réutilisé sur le catalogue, sans appel YouTube supplémentaire.' => 'The same local corpus is reused by the catalogue.',
    'Diagnostic SEO' => 'SEO diagnostics',
    'Prévisualisation des balises produites pour la première vidéo du classement global.' => 'Preview of the tags generated for the first video in the global ranking.',
    'Vidéo test' => 'Test video',
    'Aucune vidéo disponible pour le diagnostic SEO.' => 'No video is available for SEO diagnostics.',
    'Pages publiques adressables' => 'Addressable public pages',
    'Catalogue' => 'Catalogue',
    'Les vidéos permanentes et les chaînes éligibles possèdent également leur propre URL canonique.' => 'Permanent videos and eligible channels also have their own canonical URL.',
    'Jamais' => 'Never'
);

$french = array();
foreach ($english as $source => $translated) {
    $french[$source] = $source;
}

foreach (array('english.php' => $english, 'french_france.php' => $french) as $file => $map) {
    $path = $root . '/language/' . $file;
    $content = videos_i18n_read($path);
    if (strpos($content, '$LANG_VIDEOS_ADMIN_TEXT = array(') === false) {
        $block = "\n// 0.18.0 administration compatibility translations\n"
            . '$LANG_VIDEOS_ADMIN_TEXT = ' . var_export($map, true) . ";\n";
        $content = str_replace("\n\$LANG_VIDEOS_FAQ = array(", $block . "\n\$LANG_VIDEOS_FAQ = array(", $content);
        videos_i18n_write($path, $content);
    }
}

$indexPath = $root . '/admin/index.php';
$index = videos_i18n_read($indexPath);
$index = str_replace("COM_showMessageText(\n        'Le stockage du plugin Videos est indisponible. Consultez les outils de réparation.',", "COM_showMessageText(\n        VIDEOS_localizeAdminText('Le stockage du plugin Videos est indisponible. Consultez les outils de réparation.'),", $index);
$index = str_replace("echo COM_createHTMLDocument(\n    \$html,", "\$html = VIDEOS_localizeAdminText(\$html);\n\necho COM_createHTMLDocument(\n    \$html,", $index);
$index = str_replace("    global \$LANG_VIDEOS;\n    global \$LANG_VIDEOS;\n", "    global \$LANG_VIDEOS;\n", $index);
videos_i18n_write($indexPath, $index);

$actionsPath = $root . '/admin/actions.php';
$actions = videos_i18n_read($actionsPath);
$actions = str_replace("if (\$message !== '') {\n    \$html .= COM_showMessageText(\$message, '', true);", "if (\$message !== '') {\n    \$message = VIDEOS_localizeAdminText(\$message);\n    \$html .= COM_showMessageText(\$message, '', true);", $actions);
$actions = str_replace("echo COM_createHTMLDocument(\$html, array('pagetitle' => 'Videos — Actions',", "\$html = VIDEOS_localizeAdminText(\$html);\necho COM_createHTMLDocument(\$html, array('pagetitle' => VIDEOS_localizeAdminText('Videos — Actions'),", $actions);
$actions = str_replace("    global \$LANG_VIDEOS;\n    global \$LANG_VIDEOS;\n", "    global \$LANG_VIDEOS;\n", $actions);
videos_i18n_write($actionsPath, $actions);

$statsPath = $root . '/admin/stats.php';
$stats = videos_i18n_read($statsPath);
$stats = str_replace("COM_showMessageText('Le stockage du plugin Videos est indisponible.', '', true)", "COM_showMessageText(VIDEOS_localizeAdminText('Le stockage du plugin Videos est indisponible.'), '', true)", $stats);
$stats = str_replace("'pagetitle' => 'Videos — Statistiques'", "'pagetitle' => VIDEOS_localizeAdminText('Videos — Statistiques')", $stats);
$stats = str_replace("echo COM_createHTMLDocument(\n    \$html,", "\$html = VIDEOS_localizeAdminText(\$html);\n\necho COM_createHTMLDocument(\n    \$html,", $stats);
$stats = str_replace("        return 'Jamais';", "        return VIDEOS_localizeAdminText('Jamais');", $stats);
$stats = str_replace("    global \$LANG_VIDEOS;\n    global \$LANG_VIDEOS;\n", "    global \$LANG_VIDEOS;\n", $stats);
videos_i18n_write($statsPath, $stats);

echo "Admin i18n compatibility applied.\n";
