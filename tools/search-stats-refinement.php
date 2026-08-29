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

// Search type label must be stable and language-driven, never the configurable public title.
foreach (array(
    'english.php' => 'Videos',
    'french_france.php' => 'Vidéos'
) as $file => $label) {
    $path = $root . '/language/' . $file;
    $content = read_text($path);
    if (strpos($content, "'search_type_label'") === false) {
        $content = str_replace(
            "    'plugin_name' => 'Videos',\n",
            "    'plugin_name' => 'Videos',\n    'search_type_label' => " . var_export($label, true) . ",\n",
            $content
        );
    }
    write_text($path, $content);
}

$integrationPath = $root . '/geeklog_integration.php';
$integration = read_text($integrationPath);
$integration = str_replace(
    "function plugin_searchtypes_videos()\n{\n",
    "function plugin_searchtypes_videos()\n{\n    global \$LANG_VIDEOS;\n",
    $integration
);
$integration = str_replace(
    "    return array('videos' => VIDEOS_getPublicTitle());",
    "    return array('videos' => isset(\$LANG_VIDEOS['search_type_label'])\n        ? \$LANG_VIDEOS['search_type_label'] : 'Videos');",
    $integration
);
$integration = str_replace(
    "    global \$_CONF;\n\n    if (\$type !== 'all'",
    "    global \$_CONF, \$LANG_VIDEOS;\n\n    if (\$type !== 'all'",
    $integration
);
$integration = str_replace(
    "    \$criteria = new SearchCriteria('videos', VIDEOS_getPublicTitle());",
    "    \$criteria = new SearchCriteria(\n        'videos',\n        isset(\$LANG_VIDEOS['search_type_label'])\n            ? \$LANG_VIDEOS['search_type_label'] : 'Videos'\n    );",
    $integration
);
write_text($integrationPath, $integration);

// Geeklog Search API 2 expects an integer Unix timestamp in date. A non-numeric
// uid value is displayed as-is, so use the YouTube channel as the visible author.
$searchPath = $root . '/classes/Videos_Search.php';
$search = read_text($searchPath);
$search = str_replace(
    "            \$published = !empty(\$snippet['publishedAt'])\n                ? strtotime((string) \$snippet['publishedAt']) : false;\n            \$ranking",
    "            \$published = !empty(\$snippet['publishedAt'])\n                ? strtotime((string) \$snippet['publishedAt']) : false;\n            \$channel = !empty(\$snippet['channelTitle'])\n                ? trim((string) \$snippet['channelTitle']) : '';\n            \$ranking",
    $search
);
$search = str_replace(
    "                'date' => \$published === false\n                    ? '' : date('Y-m-d H:i:s', \$published),\n                'uid' => 0,",
    "                'date' => \$published === false ? 'LF_NULL' : (int) \$published,\n                'uid' => \$channel !== '' ? \$channel : 'LF_NULL',",
    $search
);
write_text($searchPath, $search);

// Make the main Statistics page action-oriented. Keep implementation details
// available in collapsed diagnostics instead of occupying the page by default.
$statsPath = $root . '/admin/stats.php';
$stats = read_text($statsPath);
$oldIntegration = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>Intégration Geeklog</h2>'
    . '<div class="videos-integration-grid">'
    . '<div><strong>Recherche native</strong><span>Active via '
    . '<code>plugin_searchtypes_videos()</code> et '
    . '<code>plugin_dopluginsearch_videos()</code>.</span></div>'
    . '<div><strong>Statistiques natives</strong><span>Active via '
    . '<code>plugin_statssummary_videos()</code> et '
    . '<code>plugin_showstats_videos()</code>.</span></div>'
    . '<div><strong>Recherche publique</strong><span>Le même corpus local est '
    . 'réutilisé sur le catalogue, sans appel YouTube supplémentaire.</span></div>'
    . '</div></section>';
PHP;
$newIntegration = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>Intégration Geeklog</h2>'
    . '<div class="videos-integration-grid">'
    . '<div><strong>Recherche Geeklog</strong><span>Active</span></div>'
    . '<div><strong>Statistiques Geeklog</strong><span>Actives</span></div>'
    . '<div><strong>Recherche du catalogue</strong><span>Active</span></div>'
    . '<div><strong>Interopérabilité ItemInfo</strong><span>Active</span></div>'
    . '<div><strong>IndexNow</strong><span>'
    . (function_exists('send_to_indexnow') ? 'Disponible' : 'Indisponible')
    . '</span></div>'
    . '</div>'
    . '<details class="videos-advanced-field"><summary>Informations développeur</summary>'
    . '<p><code>plugin_searchtypes_videos()</code> · <code>plugin_dopluginsearch_videos()</code><br>'
    . '<code>plugin_statssummary_videos()</code> · <code>plugin_showstats_videos()</code><br>'
    . '<code>plugin_getiteminfo_videos()</code> · <code>plugin_idtourl_videos()</code></p>'
    . '</details></section>';
PHP;
$stats = str_replace($oldIntegration, $newIntegration, $stats);
$oldSeoStart = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>Diagnostic SEO</h2>'
    . '<p>Prévisualisation des balises produites pour la première vidéo '
    . 'du classement global.</p>';
if ($seoDiagnostic !== '') {
    $html .= '<p>Vidéo test : <code>'
        . htmlspecialchars($seoDiagnosticVideoId, ENT_QUOTES, 'UTF-8')
        . '</code></p><pre class="videos-seo-preview"><code>'
        . htmlspecialchars($seoDiagnostic, ENT_QUOTES, 'UTF-8')
        . '</code></pre>';
} else {
    $html .= '<p>Aucune vidéo disponible pour le diagnostic SEO.</p>';
}
$html .= '</section>';
PHP;
$newSeoStart = <<<'PHP'
$html .= '<section class="videos-admin-section"><h2>SEO vidéo</h2>';
if ($seoDiagnostic !== '') {
    $checks = array(
        'canonical' => strpos($seoDiagnostic, 'rel="canonical"') !== false,
        'meta description' => strpos($seoDiagnostic, 'name="description"') !== false,
        'Open Graph' => strpos($seoDiagnostic, 'property="og:') !== false,
        'VideoObject' => strpos($seoDiagnostic, '"@type":"VideoObject"') !== false,
        'thumbnailUrl' => strpos($seoDiagnostic, '"thumbnailUrl"') !== false,
        'uploadDate' => strpos($seoDiagnostic, '"uploadDate"') !== false,
        'embedUrl' => strpos($seoDiagnostic, '"embedUrl"') !== false
    );
    $allOk = !in_array(false, $checks, true);
    $html .= '<p><strong>SEO vidéo : ' . ($allOk ? 'OK' : 'À vérifier') . '</strong></p>'
        . '<ul class="videos-admin-status">';
    foreach ($checks as $label => $ok) {
        $html .= '<li>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . ' : '
            . ($ok ? 'OK' : 'À vérifier') . '</li>';
    }
    $html .= '</ul><details class="videos-advanced-field"><summary>Diagnostic technique</summary>'
        . '<p>Vidéo test : <code>'
        . htmlspecialchars($seoDiagnosticVideoId, ENT_QUOTES, 'UTF-8')
        . '</code></p><pre class="videos-seo-preview"><code>'
        . htmlspecialchars($seoDiagnostic, ENT_QUOTES, 'UTF-8')
        . '</code></pre></details>';
} else {
    $html .= '<p>Aucune vidéo disponible pour le diagnostic SEO.</p>';
}
$html .= '</section>';
PHP;
$stats = str_replace($oldSeoStart, $newSeoStart, $stats);
write_text($statsPath, $stats);

// Extend the existing compatibility translation maps for new compact labels.
foreach (array(
    'english.php' => array(
        'Recherche Geeklog' => 'Geeklog search',
        'Statistiques Geeklog' => 'Geeklog statistics',
        'Recherche du catalogue' => 'Catalogue search',
        'Interopérabilité ItemInfo' => 'ItemInfo interoperability',
        'Active' => 'Active',
        'Actives' => 'Active',
        'Disponible' => 'Available',
        'Indisponible' => 'Unavailable',
        'Informations développeur' => 'Developer information',
        'SEO vidéo' => 'Video SEO',
        'SEO vidéo : ' => 'Video SEO: ',
        'À vérifier' => 'Check required',
        'Diagnostic technique' => 'Technical diagnostics'
    ),
    'french_france.php' => array()
) as $file => $extra) {
    if (empty($extra)) {
        continue;
    }
    $path = $root . '/language/' . $file;
    $content = read_text($path);
    $marker = "\$LANG_VIDEOS_FAQ = array(";
    $append = "\nforeach (array(\n";
    foreach ($extra as $source => $target) {
        $append .= '    ' . var_export($source, true) . ' => ' . var_export($target, true) . ",\n";
    }
    $append .= ") as \$videosAdminSource => \$videosAdminTarget) {\n"
        . "    \$LANG_VIDEOS_ADMIN_TEXT[\$videosAdminSource] = \$videosAdminTarget;\n}\n\n";
    if (strpos($content, "'Recherche Geeklog' => 'Geeklog search'") === false) {
        $content = str_replace($marker, $append . $marker, $content);
        write_text($path, $content);
    }
}

echo "Search metadata and Statistics refinement applied.\n";
