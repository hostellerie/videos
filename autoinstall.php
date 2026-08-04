<?php

if (!isset($_CONF)) {
    die('This file cannot be used on its own.');
}

require_once $_CONF['path'] . 'plugins/videos/version.php';

function plugin_autoinstall_videos($pi_name)
{
    $pi_name = 'videos';
    $pi_display_name = 'Videos';
    $pi_admin = $pi_display_name . ' Admin';

    $info = array(
        'pi_name'         => $pi_name,
        'pi_display_name' => $pi_display_name,
        'pi_version'      => VIDEOS_PLUGIN_VERSION,
        'pi_gl_version'   => VIDEOS_MIN_GEEKLOG_VERSION,
        'pi_homepage'     => 'https://www.geeklog.net/'
    );

    $groups = array(
        $pi_admin => 'Has full access to the Videos plugin'
    );

    $features = array(
        'videos.admin'                         => 'Access to Videos administration',
        'videos.config'                        => 'Change Videos configuration',
        'videos.maintenance'                   => 'Run Videos maintenance actions',
        'videos.viewstats'                     => 'View Videos statistics',
        'videos.moderate'                      => 'Moderate videos and channels',
        'videos.personal_history'              => 'Use personal video history',
        'videos.personal_recommendations'      => 'Use personal video recommendations',
        'config.videos.tab_general'            => 'Configure general settings',
        'config.videos.tab_youtube'            => 'Configure YouTube API settings',
        'config.videos.tab_analysis'           => 'Configure site analysis',
        'config.videos.tab_sources'            => 'Configure videos and channels',
        'config.videos.tab_ranking'            => 'Configure ratings and recommendations',
        'config.videos.tab_privacy'            => 'Configure privacy settings',
        'config.videos.tab_block'              => 'Configure the Videos block',
        'config.videos.tab_seo'                => 'Configure public-page SEO',
        'config.videos.tab_maintenance'        => 'Configure maintenance settings'
    );

    $mappings = array();
    foreach ($features as $feature => $description) {
        if (($feature !== 'videos.personal_history') &&
            ($feature !== 'videos.personal_recommendations')) {
            $mappings[$feature] = array($pi_admin);
        }
    }
    $inst_parms = array(
        'info'     => $info,
        'groups'   => $groups,
        'features' => $features,
        'mappings' => $mappings,
        'tables'   => array()
    );

    return $inst_parms;
}

function plugin_load_configuration_videos($pi_name)
{
    global $_CONF, $_VID_DEFAULT;

    require_once $_CONF['path_system'] . 'classes/config.class.php';
    require_once $_CONF['path'] . 'plugins/videos/install_defaults.php';

    return plugin_initconfig_videos(true);
}

function plugin_postinstall_videos($pi_name)
{
    global $_TABLES;

    $groupName = DB_escapeString('Logged-in Users');
    $groupId = DB_getItem(
        $_TABLES['groups'],
        'grp_id',
        "grp_name = '$groupName'"
    );
    if (empty($groupId)) {
        COM_errorLog(
            "Videos postinstall: 'Logged-in Users' group was not found.",
            1
        );
        return false;
    }

    $features = array(
        'videos.personal_history',
        'videos.personal_recommendations'
    );
    foreach ($features as $featureName) {
        $escapedFeature = DB_escapeString($featureName);
        $featureId = DB_getItem(
            $_TABLES['features'],
            'ft_id',
            "ft_name = '$escapedFeature'"
        );
        if (empty($featureId)) {
            COM_errorLog(
                "Videos postinstall: feature '$featureName' was not found.",
                1
            );
            return false;
        }

        $featureId = (int) $featureId;
        $groupId = (int) $groupId;
        $existing = DB_query(
            "SELECT acc_ft_id FROM {$_TABLES['access']} "
            . "WHERE acc_ft_id = $featureId AND acc_grp_id = $groupId"
        );
        if (DB_error()) {
            COM_errorLog(
                "Videos postinstall: cannot verify feature mapping.",
                1
            );
            return false;
        }
        if (DB_numRows($existing) === 0) {
            DB_query(
                "INSERT INTO {$_TABLES['access']} "
                . "(acc_ft_id, acc_grp_id) "
                . "VALUES ($featureId, $groupId)"
            );
            if (DB_error()) {
                COM_errorLog(
                    "Videos postinstall: cannot add feature mapping.",
                    1
                );
                return false;
            }
        }
    }

    return true;
}

function plugin_compatible_with_this_version_videos($pi_name)
{
    if (version_compare(PHP_VERSION, VIDEOS_MIN_PHP_VERSION, '<')) {
        return false;
    }

    if (!function_exists('json_encode') ||
        !function_exists('json_decode') ||
        !function_exists('hash_hmac') ||
        !function_exists('SEC_createToken') ||
        !function_exists('SEC_checkToken') ||
        !function_exists('COM_showMessageText')) {
        return false;
    }

    return true;
}

function plugin_autouninstall_videos()
{
    return array(
        'tables' => array(),
        'groups' => array('Videos Admin'),
        'features' => array(
            'videos.admin',
            'videos.config',
            'videos.maintenance',
            'videos.viewstats',
            'videos.moderate',
            'videos.personal_history',
            'videos.personal_recommendations',
            'config.videos.tab_general',
            'config.videos.tab_youtube',
            'config.videos.tab_analysis',
            'config.videos.tab_sources',
            'config.videos.tab_ranking',
            'config.videos.tab_privacy',
            'config.videos.tab_block',
            'config.videos.tab_maintenance'
        ),
        'php_blocks' => array(),
        'vars' => array()
    );
}
