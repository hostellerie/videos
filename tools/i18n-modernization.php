<?php

$root = dirname(__DIR__);

function patch_file($path, $replacements)
{
    $content = file_get_contents($path);
    if ($content === false) {
        throw new RuntimeException('Cannot read ' . $path);
    }
    foreach ($replacements as $from => $to) {
        $content = str_replace($from, $to, $content);
    }
    if (file_put_contents($path, $content) === false) {
        throw new RuntimeException('Cannot write ' . $path);
    }
}

// Shared admin navigation labels.
$adminFiles = array(
    $root . '/admin/index.php' => 'videos_overview_nav',
    $root . '/admin/actions.php' => 'videos_admin_section_nav',
    $root . '/admin/stats.php' => 'videos_stats_nav',
    $root . '/admin/moderation.php' => 'videos_moderation_nav'
);
foreach ($adminFiles as $path => $function) {
    if (!file_exists($path)) {
        continue;
    }
    patch_file($path, array(
        "function {$function}(\$configuration, \$active)\n{\n" => "function {$function}(\$configuration, \$active)\n{\n    global \$LANG_VIDEOS;\n",
        "'overview' => array('index.php', 'Vue générale')" => "'overview' => array('index.php', \$LANG_VIDEOS['admin_nav_overview'])",
        "'actions' => array('actions.php', 'Actions')" => "'actions' => array('actions.php', \$LANG_VIDEOS['admin_nav_actions'])",
        "'stats' => array('stats.php', 'Statistiques')" => "'stats' => array('stats.php', \$LANG_VIDEOS['admin_nav_stats'])",
        "'moderation' => array('moderation.php', 'Modération')" => "'moderation' => array('moderation.php', \$LANG_VIDEOS['admin_nav_moderation'])",
        "aria-label=\"Administration Videos\"" => "aria-label=\"' . htmlspecialchars(\$LANG_VIDEOS['admin_navigation'], ENT_QUOTES, 'UTF-8') . '\""
    ));
}

// Public catalogue/search strings.
$index = $root . '/public_html/index.php';
patch_file($index, array(
    "COM_showMessageText('Le catalogue vidéo est désactivé.', '', true)" => "COM_showMessageText(\$LANG_VIDEOS['catalogue_disabled'], '', true)",
    "\$message = 'Le catalogue vidéo est temporairement indisponible.';" => "\$message = \$LANG_VIDEOS['catalogue_unavailable'];",
    "\$message = 'La recherche vidéo est temporairement indisponible.';" => "\$message = \$LANG_VIDEOS['catalogue_search_unavailable'];",
    "\$message = 'Aucune vidéo du catalogue ne correspond à cette recherche.';" => "\$message = \$LANG_VIDEOS['catalogue_search_empty'];",
    "\$message = 'La thématique vidéo doit être configurée par un administrateur.';" => "\$message = \$LANG_VIDEOS['catalogue_topic_required'];",
    "\$message = 'Aucune vidéo n’est actuellement disponible.';" => "\$message = \$LANG_VIDEOS['catalogue_none_available'];",
    "function videos_catalogue_search_form(\$action, \$query)\n{\n" => "function videos_catalogue_search_form(\$action, \$query)\n{\n    global \$LANG_VIDEOS;\n",
    "'<label for=\"videos-search-q\">Rechercher dans le catalogue</label>'" => "'<label for=\"videos-search-q\">' . htmlspecialchars(\$LANG_VIDEOS['catalogue_search_label'], ENT_QUOTES, 'UTF-8') . '</label>'",
    "' placeholder=\"Titre, chaîne ou mots-clés\">'" => "' placeholder=\"' . htmlspecialchars(\$LANG_VIDEOS['catalogue_search_placeholder'], ENT_QUOTES, 'UTF-8') . '\">'",
    "'<button type=\"submit\">Rechercher</button></div>'" => "'<button type=\"submit\">' . htmlspecialchars(\$LANG_VIDEOS['catalogue_search_button'], ENT_QUOTES, 'UTF-8') . '</button></div>'",
    "'<p>La recherche utilise les vidéos déjà connues du site et ne consomme '\n        . 'aucun quota YouTube supplémentaire.</p></form>'" => "'<p>' . htmlspecialchars(\$LANG_VIDEOS['catalogue_search_help'], ENT_QUOTES, 'UTF-8') . '</p></form>'",
    "COM_numberFormat(\$searchTotal) . '</strong> résultat(s) pour « '" => "COM_numberFormat(\$searchTotal) . '</strong> ' . htmlspecialchars(sprintf(\$LANG_VIDEOS['catalogue_search_results'], \$searchQuery), ENT_QUOTES, 'UTF-8') . ''",
    ". htmlspecialchars(\$searchQuery, ENT_QUOTES, 'UTF-8') . ' ».'\n        . ' <a href=\"'" => ". ' <a href=\"'",
    "'>Afficher tout le catalogue</a></div>';" => "'>' . htmlspecialchars(\$LANG_VIDEOS['catalogue_show_all'], ENT_QUOTES, 'UTF-8') . '</a></div>';",
    "? 'Recherche « ' . \$searchQuery . ' » - ' . \$publicTitle" => "? sprintf(\$LANG_VIDEOS['catalogue_search_page_title'], \$searchQuery) . ' - ' . \$publicTitle"
));

// Public channel page strings.
$channel = $root . '/public_html/channel.php';
patch_file($channel, array(
    "'Chaîne vidéo indisponible.'" => "\$LANG_VIDEOS['channel_unavailable']",
    "'Chaîne vidéo temporairement indisponible.'" => "\$LANG_VIDEOS['channel_temporarily_unavailable']",
    "'Cette chaîne n’est pas publiée.'" => "\$LANG_VIDEOS['channel_not_published']",
    "'Cette chaîne ne dispose pas encore d’une sélection éditoriale suffisante.'" => "\$LANG_VIDEOS['channel_insufficient_selection']",
    "'Découvrez les vidéos remarquables de ' . \$title\n        . ' sélectionnées dans ' . VIDEOS_getPublicTitle() . '.'" => "sprintf(\$LANG_VIDEOS['channel_meta_fallback'], \$title, VIDEOS_getPublicTitle())",
    "'Chaîne prioritaire'" => "\$LANG_VIDEOS['channel_priority_badge']",
    "' vidéo' . (\$pinnedCount > 1 ? 's' : '') . ' épinglée'\n        . (\$pinnedCount > 1 ? 's' : '')" => "' ' . (\$pinnedCount > 1 ? \$LANG_VIDEOS['channel_pinned_videos'] : \$LANG_VIDEOS['channel_pinned_video'])",
    "aria-label=\"Informations sur la chaîne\"" => "aria-label=\"' . htmlspecialchars(\$LANG_VIDEOS['channel_information'], ENT_QUOTES, 'UTF-8') . '\"",
    "videos_channel_fact('Abonnés'," => "videos_channel_fact(\$LANG_VIDEOS['channel_subscribers'],",
    "videos_channel_fact('Vues YouTube'," => "videos_channel_fact(\$LANG_VIDEOS['channel_youtube_views'],",
    "videos_channel_fact('Vidéos sur YouTube'," => "videos_channel_fact(\$LANG_VIDEOS['channel_youtube_videos'],",
    "videos_channel_fact('Vidéos remarquables locales'," => "videos_channel_fact(\$LANG_VIDEOS['channel_local_videos'],",
    "videos_channel_fact('Sélection permanente'," => "videos_channel_fact(\$LANG_VIDEOS['channel_permanent_selection'],",
    "videos_channel_fact('Chaîne créée'," => "videos_channel_fact(\$LANG_VIDEOS['channel_created'],",
    "'<section class=\"videos-channel-about\"><h2>À propos de la chaîne</h2><p>'" => "'<section class=\"videos-channel-about\"><h2>' . htmlspecialchars(\$LANG_VIDEOS['channel_about'], ENT_QUOTES, 'UTF-8') . '</h2><p>'",
    "'>Voir la chaîne sur YouTube</a></p>'" => "'>' . htmlspecialchars(\$LANG_VIDEOS['channel_view_youtube'], ENT_QUOTES, 'UTF-8') . '</a></p>'",
    "'<section><h2>Vidéos remarquables</h2><div class=\"videos-grid\">'" => "'<section><h2>' . htmlspecialchars(\$LANG_VIDEOS['channel_featured_videos'], ENT_QUOTES, 'UTF-8') . '</h2><div class=\"videos-grid\">'",
    "'>Sélection permanente</span>'" => "'>' . htmlspecialchars(\$LANG_VIDEOS['channel_permanent_selection'], ENT_QUOTES, 'UTF-8') . '</span>'",
    "'>Épinglée</span>'" => "'>' . htmlspecialchars(\$LANG_VIDEOS['channel_pinned_badge'], ENT_QUOTES, 'UTF-8') . '</span>'"
));

$translations = array(
    'english.php' => array(
        'admin_nav_overview' => 'Overview',
        'admin_nav_actions' => 'Actions',
        'admin_nav_stats' => 'Statistics',
        'admin_nav_moderation' => 'Moderation',
        'admin_navigation' => 'Videos administration',
        'catalogue_disabled' => 'The video catalogue is disabled.',
        'catalogue_unavailable' => 'The video catalogue is temporarily unavailable.',
        'catalogue_search_unavailable' => 'Video search is temporarily unavailable.',
        'catalogue_search_empty' => 'No catalogue video matches this search.',
        'catalogue_topic_required' => 'The video topic must be configured by an administrator.',
        'catalogue_none_available' => 'No videos are currently available.',
        'catalogue_search_label' => 'Search the catalogue',
        'catalogue_search_placeholder' => 'Title, channel or keywords',
        'catalogue_search_button' => 'Search',
        'catalogue_search_help' => 'Search uses videos already known to this site and consumes no additional YouTube quota.',
        'catalogue_search_results' => 'result(s) for “%s”.',
        'catalogue_show_all' => 'Show the full catalogue',
        'catalogue_search_page_title' => 'Search “%s”',
        'channel_unavailable' => 'Video channel unavailable.',
        'channel_temporarily_unavailable' => 'Video channel temporarily unavailable.',
        'channel_not_published' => 'This channel is not published.',
        'channel_insufficient_selection' => 'This channel does not yet have a sufficient editorial selection.',
        'channel_meta_fallback' => 'Discover noteworthy videos from %s selected in %s.',
        'channel_priority_badge' => 'Priority channel',
        'channel_pinned_video' => 'pinned video',
        'channel_pinned_videos' => 'pinned videos',
        'channel_information' => 'Channel information',
        'channel_subscribers' => 'Subscribers',
        'channel_youtube_views' => 'YouTube views',
        'channel_youtube_videos' => 'Videos on YouTube',
        'channel_local_videos' => 'Noteworthy local videos',
        'channel_permanent_selection' => 'Permanent selection',
        'channel_created' => 'Channel created',
        'channel_about' => 'About the channel',
        'channel_view_youtube' => 'View channel on YouTube',
        'channel_featured_videos' => 'Noteworthy videos',
        'channel_pinned_badge' => 'Pinned'
    ),
    'french_france.php' => array(
        'admin_nav_overview' => 'Vue générale',
        'admin_nav_actions' => 'Actions',
        'admin_nav_stats' => 'Statistiques',
        'admin_nav_moderation' => 'Modération',
        'admin_navigation' => 'Administration Videos',
        'catalogue_disabled' => 'Le catalogue vidéo est désactivé.',
        'catalogue_unavailable' => 'Le catalogue vidéo est temporairement indisponible.',
        'catalogue_search_unavailable' => 'La recherche vidéo est temporairement indisponible.',
        'catalogue_search_empty' => 'Aucune vidéo du catalogue ne correspond à cette recherche.',
        'catalogue_topic_required' => 'La thématique vidéo doit être configurée par un administrateur.',
        'catalogue_none_available' => 'Aucune vidéo n’est actuellement disponible.',
        'catalogue_search_label' => 'Rechercher dans le catalogue',
        'catalogue_search_placeholder' => 'Titre, chaîne ou mots-clés',
        'catalogue_search_button' => 'Rechercher',
        'catalogue_search_help' => 'La recherche utilise les vidéos déjà connues du site et ne consomme aucun quota YouTube supplémentaire.',
        'catalogue_search_results' => 'résultat(s) pour « %s ».',
        'catalogue_show_all' => 'Afficher tout le catalogue',
        'catalogue_search_page_title' => 'Recherche « %s »',
        'channel_unavailable' => 'Chaîne vidéo indisponible.',
        'channel_temporarily_unavailable' => 'Chaîne vidéo temporairement indisponible.',
        'channel_not_published' => 'Cette chaîne n’est pas publiée.',
        'channel_insufficient_selection' => 'Cette chaîne ne dispose pas encore d’une sélection éditoriale suffisante.',
        'channel_meta_fallback' => 'Découvrez les vidéos remarquables de %s sélectionnées dans %s.',
        'channel_priority_badge' => 'Chaîne prioritaire',
        'channel_pinned_video' => 'vidéo épinglée',
        'channel_pinned_videos' => 'vidéos épinglées',
        'channel_information' => 'Informations sur la chaîne',
        'channel_subscribers' => 'Abonnés',
        'channel_youtube_views' => 'Vues YouTube',
        'channel_youtube_videos' => 'Vidéos sur YouTube',
        'channel_local_videos' => 'Vidéos remarquables locales',
        'channel_permanent_selection' => 'Sélection permanente',
        'channel_created' => 'Chaîne créée',
        'channel_about' => 'À propos de la chaîne',
        'channel_view_youtube' => 'Voir la chaîne sur YouTube',
        'channel_featured_videos' => 'Vidéos remarquables',
        'channel_pinned_badge' => 'Épinglée'
    )
);

foreach ($translations as $file => $values) {
    $path = $root . '/language/' . $file;
    $content = file_get_contents($path);
    if ($content === false || strpos($content, "'admin_nav_overview'") !== false) {
        continue;
    }
    $lines = "\n// 0.18.0 interface strings\n";
    foreach ($values as $key => $value) {
        $lines .= '$LANG_VIDEOS[' . var_export($key, true) . '] = '
            . var_export($value, true) . ";\n";
    }
    $marker = "\n\$LANG_VIDEOS_FAQ = array(";
    $content = str_replace($marker, $lines . $marker, $content);
    file_put_contents($path, $content);
}

echo "Videos i18n modernization applied.\n";
