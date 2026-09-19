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
    'videos.provider.status'
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
    'service_provider_status_videos'
);
foreach ($requiredServices as $service) {
    if (strpos($services, 'function ' . $service . '(') === false) {
        fwrite(STDERR, 'Missing service: ' . $service . PHP_EOL);
        exit(1);
    }
}

if (strpos($functions, "plugins/videos/services.inc.php") === false) {
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

echo "Videos shared capability contract: OK" . PHP_EOL;
