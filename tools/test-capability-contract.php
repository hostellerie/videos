<?php

$root = dirname(__DIR__);
$interoperability = file_get_contents($root . '/interoperability.php');
$services = file_get_contents($root . '/services.inc.php');
$functions = file_get_contents($root . '/functions.inc');

$requiredCapabilities = array(
    'content.read',
    'content.collection',
    'content.search',
    'content.popular',
    'content.url.resolve',
    'content.lifecycle',
    'content.syndication',
    'dashboard.summary',
    'videos.channels.read',
    'videos.rankings.read',
    'videos.provider.status',
    'videos.recommendations.render'
);

foreach ($requiredCapabilities as $capability) {
    if (strpos($interoperability, "'" . $capability . "'") === false) {
        fwrite(STDERR, 'Missing capability: ' . $capability . PHP_EOL);
        exit(1);
    }
}

$requiredServices = array(
    'service_dashboard_summary_videos',
    'service_channels_read_videos',
    'service_rankings_read_videos',
    'service_provider_status_videos',
    'service_recommendations_render_videos'
);
foreach ($requiredServices as $service) {
    if (strpos($services, 'function ' . $service . '(') === false) {
        fwrite(STDERR, 'Missing service: ' . $service . PHP_EOL);
        exit(1);
    }
}

if (strpos($functions, "require_once __DIR__ . '/services.inc.php';") === false) {
    fwrite(STDERR, 'functions.inc does not load services.inc.php.' . PHP_EOL);
    exit(1);
}

if (strpos($services, "SEC_hasRights('videos.admin')") === false) {
    fwrite(STDERR, 'dashboard.summary is missing the videos.admin permission check.' . PHP_EOL);
    exit(1);
}

if (strpos($services, "'public_runtime' => 'local-only'") === false) {
    fwrite(STDERR, 'provider status does not document the local-only public runtime.' . PHP_EOL);
    exit(1);
}

if (strpos($services, "VIDEOS_renderVideoBlock(") === false
    || strpos($services, "VIDEOS_wrapBlockContent(") === false
    || strpos($services, "VIDEOS_itemInfoRecord(") === false
    || strpos($services, "_VIDEOS_NEEDS_BLOCK_CSS") === false
    || strpos($services, "'provider_owned' => true") === false
    || strpos($services, "isset(\$args['items'])") === false
) {
    fwrite(STDERR, 'recommendation renderer does not preserve approved provider-owned rendering boundaries.' . PHP_EOL);
    exit(1);
}

echo "Videos shared capability contract: OK" . PHP_EOL;
