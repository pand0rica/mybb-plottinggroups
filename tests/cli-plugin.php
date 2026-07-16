<?php
/**
 * plottinggroups – CLI plugin lifecycle helper (test server only).
 *
 * Usage from the MyBB root:
 *   php tests/cli-plugin.php install     # install + activate + mark active
 *   php tests/cli-plugin.php activate    # re-run activation only (idempotency)
 *   php tests/cli-plugin.php uninstall   # deactivate + uninstall + unmark
 *   php tests/cli-plugin.php status      # tables/settings/templates/... counts
 *
 * Mirrors what the ACP plugin manager does. Not part of a release zip.
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only.');
}

define('IN_MYBB', 1);
chdir(dirname(__DIR__));
require_once './inc/init.php';

if (!defined('MYBB_ADMIN_DIR')) {
    $admin_dir = isset($config['admin_dir']) ? $config['admin_dir'] : 'admin';
    define('MYBB_ADMIN_DIR', MYBB_ROOT . $admin_dir . '/');
}

require_once MYBB_ROOT . 'inc/plugins/plottinggroups.php';

function plottinggroups_cli_set_active(bool $active): void
{
    global $cache;

    $plugins_cache = $cache->read('plugins');
    if (!is_array($plugins_cache)) {
        $plugins_cache = [];
    }
    if (!isset($plugins_cache['active']) || !is_array($plugins_cache['active'])) {
        $plugins_cache['active'] = [];
    }
    if ($active) {
        $plugins_cache['active']['plottinggroups'] = 'plottinggroups';
    } else {
        unset($plugins_cache['active']['plottinggroups']);
    }
    $cache->update('plugins', $plugins_cache);
}

function plottinggroups_cli_status(): void
{
    global $db, $cache;

    foreach (['plottinggroups_groups', 'plottinggroups_members'] as $table) {
        echo "table {$table}: " . ($db->table_exists($table) ? 'exists' : 'MISSING') . "\n";
    }

    $row = $db->fetch_array($db->query("SELECT COUNT(*) AS num FROM " . TABLE_PREFIX . "settings WHERE name LIKE 'plottinggroups\_%'"));
    echo 'settings: ' . (int)$row['num'] . "\n";

    $row = $db->fetch_array($db->query("SELECT COUNT(*) AS num FROM " . TABLE_PREFIX . "templates WHERE title LIKE 'plottinggroups%'"));
    echo 'templates: ' . (int)$row['num'] . "\n";

    $row = $db->fetch_array($db->query("SELECT COUNT(*) AS num FROM " . TABLE_PREFIX . "themestylesheets WHERE name = 'plottinggroups.css'"));
    echo 'stylesheets: ' . (int)$row['num'] . "\n";

    if ($db->table_exists('alert_types')) {
        $row = $db->fetch_array($db->query("SELECT COUNT(*) AS num FROM " . TABLE_PREFIX . "alert_types WHERE code LIKE 'plottinggroups\_%'"));
        echo 'alert types: ' . (int)$row['num'] . "\n";
    } else {
        echo "alert types: n/a (MyAlerts not installed)\n";
    }

    $row = $db->fetch_array($db->query("SELECT COUNT(*) AS num FROM " . TABLE_PREFIX . "templates
        WHERE title = 'index' AND template LIKE '%{\$plottinggroups\_group\_notice}%'"));
    echo 'index injections: ' . (int)$row['num'] . "\n";

    $row = $db->fetch_array($db->query("SELECT COUNT(*) AS num FROM " . TABLE_PREFIX . "templates
        WHERE title = 'modcp_nav' AND template LIKE '%{\$plottinggroups\_modcp\_nav}%'"));
    echo 'modcp_nav injections: ' . (int)$row['num'] . "\n";

    if ($db->table_exists('plottinggroups_groups')) {
        $g = $db->fetch_array($db->query("SELECT COUNT(*) AS n FROM " . TABLE_PREFIX . "plottinggroups_groups"));
        $m = $db->fetch_array($db->query("SELECT COUNT(*) AS n FROM " . TABLE_PREFIX . "plottinggroups_members"));
        echo 'groups: ' . (int)$g['n'] . ', members: ' . (int)$m['n'] . "\n";
    }

    $plugins_cache = $cache->read('plugins');
    echo 'plugin cache active: ' . (isset($plugins_cache['active']['plottinggroups']) ? 'yes' : 'no') . "\n";
}

$cmd = isset($argv[1]) ? $argv[1] : 'status';
switch ($cmd) {
    case 'install':
        plottinggroups_install();
        plottinggroups_activate();
        plottinggroups_cli_set_active(true);
        echo "installed + activated\n";
        plottinggroups_cli_status();
        break;

    case 'activate':
        plottinggroups_activate();
        echo "activated\n";
        plottinggroups_cli_status();
        break;

    case 'uninstall':
        plottinggroups_deactivate();
        plottinggroups_uninstall();
        plottinggroups_cli_set_active(false);
        echo "deactivated + uninstalled\n";
        plottinggroups_cli_status();
        break;

    case 'status':
    default:
        plottinggroups_cli_status();
        break;
}
