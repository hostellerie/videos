<?php
$root = dirname(__DIR__);

function videos_patch($path, $replacements)
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

videos_patch($root . '/functions.inc', array(
    "'catalogue' => array('label' => \$LANG_VIDEOS['catalogue'], 'url' => \$_CONF['site_url'] . '/videos/index.php')\n    );" => "'catalogue' => array('label' => \$LANG_VIDEOS['catalogue'], 'url' => \$_CONF['site_url'] . '/videos/index.php'),\n        'channels' => array('label' => \$LANG_VIDEOS['channels'], 'url' => \$_CONF['site_url'] . '/videos/channels.php')\n    );"
));

videos_patch($root . '/public_html/index.php', array(
    "        . '<p>' . htmlspecialchars(\$LANG_VIDEOS['catalogue_search_help'], ENT_QUOTES, 'UTF-8') . '</p></form>';" => "        . '</form>';"
));

videos_patch($root . '/public_html/history.php', array(
    "    . '</h1><p>Ces données sont associées à un identifiant pseudonyme. '\n    . 'Elles sont conservées sans expiration automatique.</p>';" => "    . '</h1>';"
));

videos_patch($root . '/public_html/rankings.php', array(
    "        \$eligible = Videos_Validator::youtubeChannelId(\$channelId)\n            && isset(\$item['video_count']) && (int) \$item['video_count'] >= 2;" => "        \$eligible = Videos_Validator::youtubeChannelId(\$channelId)\n            && VIDEOS_channelPageEligible(\$channelId);"
));

videos_patch($root . '/interoperability.php', array(
    "    if (\$itemId === 'catalogue') {\n        return \$base . 'index.php';\n    }" => "    if (\$itemId === 'catalogue') {\n        return \$base . 'index.php';\n    }\n    if (\$itemId === 'channels') {\n        return \$base . 'channels.php';\n    }",
    "    if (substr(\$path, -17) === '/videos/index.php') {\n        return array('type' => 'videos', 'id' => 'catalogue', 'subtype' => 'collection');\n    }" => "    if (substr(\$path, -17) === '/videos/index.php') {\n        return array('type' => 'videos', 'id' => 'catalogue', 'subtype' => 'collection');\n    }\n    if (substr(\$path, -20) === '/videos/channels.php') {\n        return array('type' => 'videos', 'id' => 'channels', 'subtype' => 'collection');\n    }",
    "    if (\$id === 'rankings:videos' || \$id === 'rankings:channels') {" => "    if (\$id === 'channels') {\n        \$title = isset(\$LANG_VIDEOS['channels_title'])\n            ? \$LANG_VIDEOS['channels_title'] : 'Recommended video channels';\n        return array('id' => \$id, 'type' => 'videos', 'subtype' => 'collection',\n            'title' => \$title, 'url' => plugin_idtourl_videos('', \$id),\n            'description' => \$title, 'excerpt' => \$title, 'image' => '',\n            'date-created' => '', 'date-modified' => \$now, 'uid' => 0, 'author' => '');\n    }\n    if (\$id === 'rankings:videos' || \$id === 'rankings:channels') {"
));

videos_patch($root . '/classes/Videos_ChannelRanking.php', array(
    "            VIDEOS_signalSaved('rankings:channels');" => "            VIDEOS_signalSaved('rankings:channels');\n            VIDEOS_signalSaved('channels');"
));

videos_patch($root . '/classes/Videos_PermanentPool.php', array(
    "                VIDEOS_signalSaved('catalogue');\n            }" => "                VIDEOS_signalSaved('catalogue');\n                VIDEOS_signalSaved('channels');\n            }"
));

videos_patch($root . '/classes/Videos_Moderation.php', array(
    "        VIDEOS_signalSaved('rankings:channels');\n        VIDEOS_signalSaved('rankings:videos');" => "        VIDEOS_signalSaved('rankings:channels');\n        VIDEOS_signalSaved('channels');\n        VIDEOS_signalSaved('rankings:videos');"
));

$translations = array(
    'english.php' => array(
        'channels' => 'Channels',
        'channels_title' => 'Recommended video channels',
        'channels_intro' => 'Discover channels selected for their editorial value and noteworthy videos.',
        'channels_meta_description' => 'Discover recommended video channels and their noteworthy videos selected on this site.',
        'channels_empty' => 'No recommended channel is available yet.',
        'channels_unavailable' => 'The channels directory is temporarily unavailable.',
        'channels_view_channel' => 'View videos from this channel'
    ),
    'french_france.php' => array(
        'channels' => 'Chaînes',
        'channels_title' => 'Chaînes vidéo recommandées',
        'channels_intro' => 'Découvrez les chaînes retenues pour leur intérêt éditorial et leurs vidéos remarquables.',
        'channels_meta_description' => 'Découvrez les chaînes vidéo recommandées et leurs vidéos remarquables sélectionnées sur ce site.',
        'channels_empty' => 'Aucune chaîne recommandée n’est encore disponible.',
        'channels_unavailable' => 'L’annuaire des chaînes est temporairement indisponible.',
        'channels_view_channel' => 'Voir les vidéos de cette chaîne'
    )
);
foreach ($translations as $file => $values) {
    $path = $root . '/language/' . $file;
    $content = file_get_contents($path);
    if ($content === false || strpos($content, "['channels_title']") !== false) {
        continue;
    }
    $lines = "\n// 0.18.0 public channels directory\n";
    foreach ($values as $key => $value) {
        $lines .= '$LANG_VIDEOS[' . var_export($key, true) . '] = ' . var_export($value, true) . ";\n";
    }
    $marker = "\n\$LANG_VIDEOS_FAQ = array(";
    $content = str_replace($marker, $lines . $marker, $content);
    file_put_contents($path, $content);
}

echo "Public channels modernization applied.\n";
