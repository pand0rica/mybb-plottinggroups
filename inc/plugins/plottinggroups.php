<?php
/**
 * Plotting Groups (plottinggroups) – standalone plotting-group management.
 *
 * Users create/join/leave plotting groups and propose new ones for team
 * approval (reviewed in the ModCP). A group can be tagged in a forum post with
 * the syntax #"Group name"; its members then receive a MyAlerts alert. Other
 * plugins (e.g. the Plot Control Panel) may bind these groups through the
 * public Plottinggroups_Api facade – there is no hard dependency in either
 * direction.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

$plugins->add_hook('global_start',                    'plottinggroups_register_alert_formatter');
$plugins->add_hook('xmlhttp',                         'plottinggroups_register_alert_formatter');
$plugins->add_hook('index_start',                     'plottinggroups_handle_index_notice');
$plugins->add_hook('modcp_start',                     'plottinggroups_handle_modcp');
$plugins->add_hook('datahandler_post_insert_post_end',   'plottinggroups_handle_post');
$plugins->add_hook('datahandler_post_insert_thread_end', 'plottinggroups_handle_post');
$plugins->add_hook('admin_tools_menu',                'plottinggroups_admin_menu');
$plugins->add_hook('admin_tools_action_handler',      'plottinggroups_admin_action');

function plottinggroups_info(): array
{
    return [
        'name'          => 'Plotting Groups',
        'description'   => 'Standalone plotting groups for RPG forums: create, manage, join and leave groups, propose new ones for team approval (ModCP), and tag a group in a post (#"Name") to alert its members. Other plugins bind the groups via a public API.',
        'website'       => 'https://github.com/pand0rica/plottinggroups',
        'author'        => 'pand0rica',
        'authorsite'    => 'https://github.com/pand0rica',
        'version'       => '1.0.0',
        'guid'          => '',
        'codename'      => 'plottinggroups',
        'compatibility' => '18*',
    ];
}

function plottinggroups_install(): void
{
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/installer.php';
    Plottinggroups_Installer::createTables();
}

function plottinggroups_is_installed(): bool
{
    global $db;

    return $db->table_exists('plottinggroups_groups');
}

function plottinggroups_activate(): void
{
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/installer.php';
    Plottinggroups_Installer::migrateData();   // adopt plotcp groups if present
    Plottinggroups_Installer::installSettings();
    Plottinggroups_Installer::installTemplates();
    Plottinggroups_Installer::installStylesheet();
    Plottinggroups_Installer::registerAlertTypes();
    Plottinggroups_Installer::injectIndexNotice();
    Plottinggroups_Installer::injectModcpNav();
}

function plottinggroups_deactivate(): void
{
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/installer.php';
    Plottinggroups_Installer::removeModcpNavInjection();
    Plottinggroups_Installer::removeIndexNoticeInjection();
    Plottinggroups_Installer::unregisterAlertTypes();
    Plottinggroups_Installer::removeStylesheet();
    Plottinggroups_Installer::removeTemplates();
    Plottinggroups_Installer::removeSettings();
}

function plottinggroups_uninstall(): void
{
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/installer.php';
    Plottinggroups_Installer::dropTables();
}

/**
 * Loads the core classes once. Handlers call this lazily so ordinary page loads
 * that never touch group data pay no include cost.
 */
function plottinggroups_load_core(): void
{
    static $loaded = false;

    if ($loaded) {
        return;
    }
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/database.php';
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/permissions.php';
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/alerts.php';
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/api.php';
    $loaded = true;
}

/**
 * Alert type codes shared by installer (register/unregister), alert sending and
 * formatter registration. Single source of truth.
 */
function plottinggroups_alert_codes(): array
{
    return [
        'plottinggroups_new_group',
        'plottinggroups_proposal',
        'plottinggroups_tagged',
    ];
}

/**
 * Registers one formatter per alert type. Best effort: silently does nothing
 * when MyAlerts is missing or incompatible. Runs at global_start and xmlhttp
 * (bell dropdown) with a static guard against double registration.
 */
function plottinggroups_register_alert_formatter(): void
{
    static $registered = false;
    global $mybb, $lang;

    if ($registered) {
        return;
    }

    if (!class_exists('MybbStuff_MyAlerts_AlertFormatterManager')
        || !class_exists('MybbStuff_MyAlerts_Formatter_AbstractFormatter')
    ) {
        return;
    }

    $lang->load('plottinggroups_myalerts');

    $manager = MybbStuff_MyAlerts_AlertFormatterManager::getInstance();
    if (!$manager) {
        $manager = MybbStuff_MyAlerts_AlertFormatterManager::createInstance($mybb, $lang);
    }
    if (!$manager) {
        return;
    }

    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/myalerts_formatter.php';
    if (!class_exists('Plottinggroups_MyAlerts_Formatter')) {
        return;
    }

    foreach (plottinggroups_alert_codes() as $code) {
        $manager->registerFormatter(new Plottinggroups_MyAlerts_Formatter($mybb, $lang, $code));
    }

    $registered = true;
}

/**
 * Board index notice ({$plottinggroups_group_notice}, injected after {$header}).
 * Shown only to team members and only while group proposals are waiting.
 */
function plottinggroups_handle_index_notice(): void
{
    global $mybb, $plottinggroups_group_notice;

    $plottinggroups_group_notice = '';

    plottinggroups_load_core();
    if (!Plottinggroups_Permissions::isTeam($mybb->user)) {
        return;
    }

    $count = Plottinggroups_Database::getPendingGroupCount();
    if ($count <= 0) {
        return;
    }

    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/modcp.php';
    $plottinggroups_group_notice = Plottinggroups_Modcp::buildIndexNotice($count);
}

/**
 * ModCP integration: sets the navigation entry ({$plottinggroups_modcp_nav}) for
 * every ModCP page of a team member and, for our own action, renders the group
 * review page and stops.
 */
function plottinggroups_handle_modcp(): void
{
    global $mybb, $plottinggroups_modcp_nav;

    $plottinggroups_modcp_nav = '';

    plottinggroups_load_core();
    if (!Plottinggroups_Permissions::isTeam($mybb->user)) {
        return;
    }

    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/modcp.php';
    $plottinggroups_modcp_nav = Plottinggroups_Modcp::navItem();

    if ($mybb->get_input('action') === Plottinggroups_Modcp::ACTION) {
        Plottinggroups_Modcp::handle();
    }
}

/**
 * Fires on every new reply AND new thread first post (insert only – never on
 * edit). Scans the saved message for group tags of the form #"Group name" and
 * alerts the members of every matched, approved group (minus the poster).
 */
function plottinggroups_handle_post($post): void
{
    global $mybb;

    $message = isset($post->data['message']) ? (string)$post->data['message'] : '';
    if ($message === '' || strpos($message, '#"') === false) {
        return;
    }

    // optional forum restriction
    $fid = (int)(isset($post->data['fid']) ? $post->data['fid'] : 0);
    $forums = isset($mybb->settings['plottinggroups_tag_forums'])
        ? trim((string)$mybb->settings['plottinggroups_tag_forums']) : '';
    if ($forums !== '') {
        $allowed = array_filter(array_map('intval', explode(',', $forums)),
            function ($f) { return $f > 0; });
        if ($allowed && !in_array($fid, $allowed, true)) {
            return;
        }
    }

    plottinggroups_load_core();

    $names = Plottinggroups_Database::extractTaggedNames($message);
    if (!$names) {
        return;
    }

    $groups = Plottinggroups_Database::getGroupsByNames($names); // approved only
    if (!$groups) {
        return;
    }

    $gids        = array_keys($groups);
    $poster      = (int)(isset($post->data['uid']) ? $post->data['uid'] : 0);
    $recipients  = array_values(array_filter(
        Plottinggroups_Database::getGroupMemberUids($gids),
        function ($uid) use ($poster) { return $uid > 0 && $uid !== $poster; }
    ));
    if (!$recipients) {
        return;
    }

    $tid = (int)(!empty($post->tid) ? $post->tid
        : (isset($post->data['tid']) ? $post->data['tid'] : 0));
    $pid = (int)(isset($post->pid) ? $post->pid : 0);

    $group_names = [];
    foreach ($groups as $group) {
        $group_names[] = (string)$group['name'];
    }

    Plottinggroups_Alerts::sendGroupTagged($recipients, $group_names, $tid, $pid);
}

function plottinggroups_admin_menu(array &$sub_menu): void
{
    global $lang;

    $lang->load('plottinggroups');

    $sub_menu[] = [
        'id'    => 'plottinggroups',
        'title' => $lang->plottinggroups,
        'link'  => 'index.php?module=tools-plottinggroups',
    ];
}

function plottinggroups_admin_action(array &$actions): void
{
    $actions['plottinggroups'] = [
        'active' => 'plottinggroups',
        'file'   => 'plottinggroups.php',
    ];
}
