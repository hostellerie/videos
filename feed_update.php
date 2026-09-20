<?php

global $_CONF, $_TABLES, $_DB_table_prefix, $_PLUGINS, $_VIDEOS_CONF;

if (!isset($GLOBALS['_CONF'])) {
    die('This file cannot be used on its own.');
}

/**
 * Tell Geeklog whether the Videos syndication feed is already current.
 *
 * The signature mirrors plugin_getfeedcontent_videos(): the update marker is
 * derived from the same ordered editorial corpus, so transient discovery cache
 * changes never invalidate the feed.
 */
function plugin_feedupdatecheck_videos(
    $feed,
    $topic,
    $updateData,
    $limit,
    $updatedType = '',
    $updatedTopic = '',
    $updatedId = ''
) {
    if ($topic !== '' && $topic !== 'videos') {
        return true;
    }
    if ($updatedType !== '' && $updatedType !== 'videos') {
        return true;
    }

    $limit = max(1, min(500, (int) $limit));
    $records = plugin_getiteminfo_videos(
        '*',
        '*',
        0,
        array('limit' => $limit, 'order' => 'modified-desc')
    );
    if (!is_array($records)) {
        return false;
    }

    $parts = array();
    foreach ($records as $record) {
        if (!is_array($record) || empty($record['id'])) {
            continue;
        }
        $dateValue = !empty($record['date-modified'])
            ? $record['date-modified']
            : (isset($record['date-created']) ? $record['date-created'] : '');
        $timestamp = $dateValue !== '' ? strtotime($dateValue) : false;
        if ($timestamp === false) {
            $timestamp = 0;
        }
        $parts[] = (string) $record['id'] . '@' . (string) $timestamp;
    }

    return (string) $updateData === implode(',', $parts);
}
