<?php

global $_CONF, $_TABLES, $_DB_table_prefix, $_PLUGINS, $_VIDEOS_CONF;

if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

/**
 * Reject direct web-service dispatch. These interoperability services are
 * intended for trusted internal plugin calls through PLG_invokeService().
 */
function VIDEOS_serviceRejectWeb($args, &$svc_msg)
{
    if (is_array($args) && !empty($args['gl_svc'])) {
        $svc_msg['error_desc'] = 'Videos interoperability services are available only to trusted internal plugin calls.';
        return true;
    }

    return false;
}

/**
 * Shared administration summary for Eclipse and other generic dashboards.
 */
function service_dashboard_summary_videos($args, &$output, &$svc_msg)
{
    global $_CONF, $_VIDEOS_CONF;

    $output = array();
    $svc_msg = array();

    if (VIDEOS_serviceRejectWeb($args, $svc_msg)) {
        return PLG_RET_AUTH_FAILED;
    }
    if (!SEC_hasRights('videos.admin')) {
        $svc_msg['error_desc'] = 'Videos administration permission is required.';
        return PLG_RET_AUTH_FAILED;
    }

    $bootstrap = VIDEOS_interopBootstrap();
    if ($bootstrap === false) {
        $output = array(
            'schema' => 1,
            'status' => 'critical',
            'metrics' => array(),
            'alerts' => array(
                array(
                    'id' => 'storage',
                    'status' => 'critical',
                    'message' => 'Videos local storage is not available.'
                )
            ),
            'links' => array(
                array(
                    'label' => 'Videos',
                    'url' => rtrim($_CONF['site_admin_url'], '/') . '/plugins/videos/'
                )
            ),
            'updated' => time()
        );
        return PLG_RET_OK;
    }

    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    $pool = new Videos_PermanentPool($store, $cache);
    $poolStatus = $pool->status();
    $search = VIDEOS_getSearchService($bootstrap);
    $publicCount = $search === false ? 0 : count($search->inventory(1000));
    $knownChannels = count($cache->listKnownChannels(500));

    $enabled = !isset($_VIDEOS_CONF['enabled']) || !empty($_VIDEOS_CONF['enabled']);
    $discoveryEnabled = !empty($_VIDEOS_CONF['discovery_enabled']);
    $status = $enabled ? 'ok' : 'warning';
    $alerts = array();

    if (!$enabled) {
        $alerts[] = array(
            'id' => 'plugin-disabled',
            'status' => 'warning',
            'message' => 'Videos public output is disabled.'
        );
    }

    $output = array(
        'schema' => 1,
        'status' => $status,
        'metrics' => array(
            array('id' => 'public_videos', 'label' => 'Public videos', 'value' => $publicCount),
            array(
                'id' => 'retained_videos',
                'label' => 'Retained videos',
                'value' => isset($poolStatus['item_count']) ? (int) $poolStatus['item_count'] : 0
            ),
            array(
                'id' => 'pinned_videos',
                'label' => 'Pinned videos',
                'value' => isset($poolStatus['pinned_count']) ? (int) $poolStatus['pinned_count'] : 0
            ),
            array('id' => 'known_channels', 'label' => 'Known channels', 'value' => $knownChannels),
            array(
                'id' => 'excluded_videos',
                'label' => 'Excluded videos',
                'value' => isset($poolStatus['excluded_count']) ? (int) $poolStatus['excluded_count'] : 0
            )
        ),
        'alerts' => $alerts,
        'links' => array(
            array(
                'label' => 'Manage Videos',
                'url' => rtrim($_CONF['site_admin_url'], '/') . '/plugins/videos/'
            ),
            array(
                'label' => 'Videos statistics',
                'url' => rtrim($_CONF['site_admin_url'], '/') . '/plugins/videos/stats.php'
            )
        ),
        'updated' => time(),
        'provider' => array(
            'external_sync' => $discoveryEnabled ? 'enabled' : 'disabled',
            'public_runtime' => 'local-only'
        )
    );

    return PLG_RET_OK;
}

/**
 * Read-only provider status for Agent, Hub and diagnostics.
 */
function service_provider_status_videos($args, &$output, &$svc_msg)
{
    global $_VIDEOS_CONF;

    $output = array();
    $svc_msg = array();

    if (VIDEOS_serviceRejectWeb($args, $svc_msg)) {
        return PLG_RET_AUTH_FAILED;
    }

    $bootstrap = VIDEOS_interopBootstrap();
    $output = array(
        'schema' => 1,
        'provider' => 'videos',
        'status' => $bootstrap === false ? 'unavailable' : 'available',
        'public_runtime' => 'local-only',
        'external_sync' => !empty($_VIDEOS_CONF['discovery_enabled'])
            ? 'enabled' : 'disabled'
    );

    return PLG_RET_OK;
}


/**
 * Return a bounded read-only list of locally known public channels.
 */
function service_channels_read_videos($args, &$output, &$svc_msg)
{
    $output = array();
    $svc_msg = array();

    if (VIDEOS_serviceRejectWeb($args, $svc_msg)) {
        return PLG_RET_AUTH_FAILED;
    }

    $bootstrap = VIDEOS_interopBootstrap();
    if ($bootstrap === false) {
        $svc_msg['error_desc'] = 'Videos local storage is not available.';
        return PLG_RET_ERROR;
    }

    $limit = isset($args['limit']) ? (int) $args['limit'] : 50;
    $limit = max(1, min(100, $limit));

    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    $moderation = new Videos_Moderation($store);
    $known = $cache->listKnownChannels(500);
    $items = array();

    foreach ($known as $channelId => $channel) {
        if ($moderation->isChannelExcluded($channelId)) {
            continue;
        }
        $items[] = array(
            'id' => 'channel:' . $channelId,
            'channel_id' => $channelId,
            'title' => isset($channel['title']) ? (string) $channel['title'] : $channelId,
            'url' => plugin_idtourl_videos('', 'channel:' . $channelId)
        );
        if (count($items) >= $limit) {
            break;
        }
    }

    $output = array(
        'schema' => 1,
        'provider' => 'videos',
        'items' => $items,
        'limit' => $limit
    );

    return PLG_RET_OK;
}

/**
 * Return bounded local video or channel rankings without external requests.
 */
function service_rankings_read_videos($args, &$output, &$svc_msg)
{
    $output = array();
    $svc_msg = array();

    if (VIDEOS_serviceRejectWeb($args, $svc_msg)) {
        return PLG_RET_AUTH_FAILED;
    }

    $bootstrap = VIDEOS_interopBootstrap();
    if ($bootstrap === false) {
        $svc_msg['error_desc'] = 'Videos local storage is not available.';
        return PLG_RET_ERROR;
    }

    $kind = isset($args['kind']) && (string) $args['kind'] === 'channels'
        ? 'channels' : 'videos';
    $limit = isset($args['limit']) ? (int) $args['limit'] : 20;
    $limit = max(1, min(100, $limit));

    $store = $bootstrap->getStore();
    $cache = new Videos_Cache($store);
    if ($kind === 'channels') {
        $ranking = new Videos_ChannelRanking($store, $cache);
        $records = $ranking->getGlobal($limit);
    } else {
        $ranking = new Videos_Ranking(
            $store,
            new Videos_RatingStats($store),
            new Videos_VideoStats($store),
            $cache
        );
        $records = $ranking->getGlobal($limit);
    }

    $items = array();
    foreach ($records as $id => $record) {
        if (!is_array($record)) {
            continue;
        }
        $item = $record;
        $item['id'] = (string) $id;
        $item['url'] = $kind === 'channels'
            ? plugin_idtourl_videos('', 'channel:' . $id)
            : plugin_idtourl_videos('', $id);
        $items[] = $item;
    }

    $output = array(
        'schema' => 1,
        'provider' => 'videos',
        'kind' => $kind,
        'items' => $items,
        'limit' => $limit
    );

    return PLG_RET_OK;
}
