<?php
$root = dirname(__DIR__);

function read_or_fail($path) {
    $c = file_get_contents($path);
    if ($c === false) throw new RuntimeException('Cannot read ' . $path);
    return $c;
}
function write_or_fail($path, $c) {
    if (file_put_contents($path, $c) === false) throw new RuntimeException('Cannot write ' . $path);
}

// 1) Record local quota refusals.
$path = $root . '/classes/Videos_Quota.php';
$c = read_or_fail($path);
$c = str_replace(
"                'last_error' => null\n            ),\n            function (\$document) use (\$method, \$limit) {\n                if (!empty(\$document['data']['suspended'])) {\n                    \$document['data']['reservation_granted'] = false;\n                    return \$document;\n                }\n                \$count = isset(\$document['data']['counts'][\$method])\n                    ? (int) \$document['data']['counts'][\$method] : 0;\n                if (\$limit > 0 && \$count >= \$limit) {\n                    \$document['data']['reservation_granted'] = false;\n                    return \$document;\n                }",
"                'last_error' => null,\n                'last_rejection' => null\n            ),\n            function (\$document) use (\$method, \$limit) {\n                \$count = isset(\$document['data']['counts'][\$method])\n                    ? (int) \$document['data']['counts'][\$method] : 0;\n                if (!empty(\$document['data']['suspended'])) {\n                    \$document['data']['reservation_granted'] = false;\n                    \$document['data']['last_rejection'] = array(\n                        'method' => \$method, 'reason' => 'quota_suspended',\n                        'count' => \$count, 'limit' => \$limit,\n                        'at' => gmdate('Y-m-d\\TH:i:s\\Z')\n                    );\n                    return \$document;\n                }\n                if (\$limit > 0 && \$count >= \$limit) {\n                    \$document['data']['reservation_granted'] = false;\n                    \$document['data']['last_rejection'] = array(\n                        'method' => \$method, 'reason' => 'local_limit_reached',\n                        'count' => \$count, 'limit' => \$limit,\n                        'at' => gmdate('Y-m-d\\TH:i:s\\Z')\n                    );\n                    return \$document;\n                }",
$c, $n1);
if ($n1 !== 1) throw new RuntimeException('Quota reserve block not found');
// Add default field to remaining defaults.
$c = str_replace("                'last_error' => null\n            )\n        );", "                'last_error' => null,\n                'last_rejection' => null\n            )\n        );", $c);
$c = str_replace("                'last_error' => null\n            ),\n            function (\$document) use (\$error, \$suspend, \$success)", "                'last_error' => null,\n                'last_rejection' => null\n            ),\n            function (\$document) use (\$error, \$suspend, \$success)", $c);
write_or_fail($path, $c);

// 2) Better action failure messages.
$path = $root . '/admin/actions.php';
$c = read_or_fail($path);
$old = <<<'PHP'
                $searchResults = videos_actions_test_search($bootstrap, $query, $_VIDEOS_CONF);
                $message = $searchResults === false
                    ? 'La recherche de test a échoué.'
                    : count($searchResults['video_ids']) . ' vidéo(s) valide(s) trouvée(s).';
PHP;
$new = <<<'PHP'
                $searchResults = videos_actions_test_search($bootstrap, $query, $_VIDEOS_CONF);
                $message = $searchResults === false
                    ? videos_actions_failure_message($store, $_VIDEOS_CONF, 'La recherche de test a échoué.')
                    : count($searchResults['video_ids']) . ' vidéo(s) valide(s) trouvée(s).';
PHP;
if (strpos($c, $old) === false) throw new RuntimeException('Test search message block not found');
$c = str_replace($old, $new, $c);
$old = <<<'PHP'
                $result = videos_actions_seed_discovery($bootstrap, $query, $_VIDEOS_CONF);
                $message = is_array($result) && !empty($result['success'])
                    ? (int) $result['added'] . ' vidéo(s) ajoutée(s) au réservoir.'
                    : 'L’amorçage du réservoir a échoué.';
PHP;
$new = <<<'PHP'
                $result = videos_actions_seed_discovery($bootstrap, $query, $_VIDEOS_CONF);
                $message = is_array($result) && !empty($result['success'])
                    ? (int) $result['added'] . ' vidéo(s) ajoutée(s) au réservoir.'
                    : videos_actions_failure_message($store, $_VIDEOS_CONF, 'L’amorçage du réservoir a échoué.');
PHP;
if (strpos($c, $old) === false) throw new RuntimeException('Seed message block not found');
$c = str_replace($old, $new, $c);
$marker = <<<'PHP'
function videos_actions_test_search($bootstrap, $query, $configuration)
PHP;
$helper = <<<'PHP'
function videos_actions_failure_message($store, $configuration, $prefix)
{
    $status = (new Videos_Quota($store))->status();
    $data = isset($status['data']) && is_array($status['data']) ? $status['data'] : array();
    $counts = isset($data['counts']) && is_array($data['counts']) ? $data['counts'] : array();
    $count = isset($counts['search']) ? (int) $counts['search'] : 0;
    $limit = isset($configuration['youtube_daily_search_limit'])
        ? max(0, (int) $configuration['youtube_daily_search_limit']) : 20;
    if (!empty($data['suspended'])) {
        $code = !empty($data['last_error']['code']) ? (string) $data['last_error']['code'] : 'quota';
        return $prefix . ' Le quota YouTube est suspendu (' . $code . ').';
    }
    if ($limit > 0 && $count >= $limit) {
        return $prefix . ' La limite locale de recherches YouTube est atteinte ('
            . $count . '/' . $limit . ' aujourd’hui).';
    }
    if (!empty($data['last_error']['code'])) {
        return $prefix . ' Dernière erreur YouTube : ' . (string) $data['last_error']['code']
            . '. Consultez Statistiques > Activité YouTube API.';
    }
    return $prefix . ' Consultez Statistiques > Activité YouTube API pour le diagnostic.';
}

function videos_actions_test_search($bootstrap, $query, $configuration)
PHP;
if (strpos($c, $marker) === false) throw new RuntimeException('Action helper marker not found');
$c = str_replace($marker, $helper, $c);
write_or_fail($path, $c);

// 3) Enrich API statistics.
$path = $root . '/admin/stats.php';
$c = read_or_fail($path);
$old = <<<'PHP'
$counts = isset($quotaData['counts']) && is_array($quotaData['counts'])
    ? $quotaData['counts'] : array();
PHP;
$new = <<<'PHP'
$counts = isset($quotaData['counts']) && is_array($quotaData['counts'])
    ? $quotaData['counts'] : array();
$searchLimit = isset($_VIDEOS_CONF['youtube_daily_search_limit'])
    ? max(0, (int) $_VIDEOS_CONF['youtube_daily_search_limit']) : 20;
$searchCount = isset($counts['search']) ? (int) $counts['search'] : 0;
$localSearchState = ($searchLimit > 0 && $searchCount >= $searchLimit)
    ? 'Limite atteinte (' . $searchCount . '/' . $searchLimit . ')'
    : $searchCount . '/' . ($searchLimit > 0 ? $searchLimit : '∞');
$lastApiError = !empty($quotaData['last_error']['code'])
    ? (string) $quotaData['last_error']['code'] : 'Aucune';
$lastApiErrorAt = !empty($quotaData['last_error']['at'])
    ? videos_stats_date_text($quotaData['last_error']['at']) : 'Jamais';
$lastRejection = isset($quotaData['last_rejection']) && is_array($quotaData['last_rejection'])
    ? $quotaData['last_rejection'] : array();
PHP;
if (strpos($c, $old) === false) throw new RuntimeException('Stats quota setup not found');
$c = str_replace($old, $new, $c);
$old = <<<'PHP'
    . videos_stat_card(
        'Quota suspendu',
        !empty($quotaData['suspended']) ? 'Oui' : 'Non',
        'Protection locale du quota'
    )
    . videos_stat_card(
        'Dernier succès',
PHP;
$new = <<<'PHP'
    . videos_stat_card(
        'Quota suspendu',
        !empty($quotaData['suspended']) ? 'Oui' : 'Non',
        'Suspension après une erreur de quota signalée par YouTube'
    )
    . videos_stat_card(
        'Limite locale recherches',
        $localSearchState,
        'Plafond quotidien configuré dans Videos'
    )
    . videos_stat_card(
        'Dernière recherche',
        videos_stats_date_text(isset($quotaData['last_search_at']) ? $quotaData['last_search_at'] : null),
        'Dernière réservation search.list autorisée',
        true
    )
    . videos_stat_card(
        'Dernière erreur API',
        $lastApiError,
        $lastApiErrorAt,
        true
    )
    . videos_stat_card(
        'Dernier succès',
PHP;
if (strpos($c, $old) === false) throw new RuntimeException('Stats cards insertion point not found');
$c = str_replace($old, $new, $c);
$old = <<<'PHP'
    . '</div></section>';

$cacheLabels = array(
PHP;
$new = <<<'PHP'
    . '</div>';
if (!empty($lastRejection)) {
    $reason = isset($lastRejection['reason']) ? (string) $lastRejection['reason'] : '';
    $method = isset($lastRejection['method']) ? (string) $lastRejection['method'] : '';
    $count = isset($lastRejection['count']) ? (int) $lastRejection['count'] : 0;
    $limit = isset($lastRejection['limit']) ? (int) $lastRejection['limit'] : 0;
    $at = !empty($lastRejection['at']) ? videos_stats_date_text($lastRejection['at']) : 'Jamais';
    $html .= '<p class="videos-admin-help"><strong>Dernier appel refusé :</strong> '
        . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . ' — '
        . htmlspecialchars($reason, ENT_QUOTES, 'UTF-8') . ' (' . $count . '/' . $limit . ') — '
        . htmlspecialchars($at, ENT_QUOTES, 'UTF-8') . '.</p>';
}
$html .= '</section>';

$cacheLabels = array(
PHP;
// Replace first occurrence after API section only.
$pos = strpos($c, $old);
if ($pos === false) throw new RuntimeException('Stats API section close not found');
$c = substr_replace($c, $new, $pos, strlen($old));
write_or_fail($path, $c);

// 4) English translations for new admin strings.
$path = $root . '/language/english.php';
$c = read_or_fail($path);
$needle = "foreach (array(\n    'Recherche Geeklog' => 'Geeklog search',";
$insert = <<<'PHP'
foreach (array(
    'La limite locale de recherches YouTube est atteinte (' => 'The local YouTube search limit has been reached (',
    ' aujourd’hui).' => ' today).',
    'Le quota YouTube est suspendu (' => 'The YouTube quota is suspended (',
    'Dernière erreur YouTube : ' => 'Last YouTube error: ',
    'Consultez Statistiques > Activité YouTube API pour le diagnostic.' => 'See Statistics > YouTube API activity for diagnostics.',
    'Limite locale recherches' => 'Local search limit',
    'Limite atteinte (' => 'Limit reached (',
    'Plafond quotidien configuré dans Videos' => 'Daily limit configured in Videos',
    'Dernière recherche' => 'Last search',
    'Dernière réservation search.list autorisée' => 'Last allowed search.list reservation',
    'Dernière erreur API' => 'Last API error',
    'Aucune' => 'None',
    'Suspension après une erreur de quota signalée par YouTube' => 'Suspension after a quota error reported by YouTube',
    'Dernier appel refusé :' => 'Last rejected call:',
) as $videosAdminSource => $videosAdminTarget) {
    $LANG_VIDEOS_ADMIN_TEXT[$videosAdminSource] = $videosAdminTarget;
}

foreach (array(
    'Recherche Geeklog' => 'Geeklog search',
PHP;
if (strpos($c, $needle) === false) throw new RuntimeException('English translation marker not found');
$c = str_replace($needle, $insert, $c);
write_or_fail($path, $c);

echo "YouTube diagnostics added.\n";
