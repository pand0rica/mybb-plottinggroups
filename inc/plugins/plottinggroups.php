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
$plugins->add_hook('xmlhttp',                         'plottinggroups_suggest_xmlhttp');
$plugins->add_hook('pre_output_page',                 'plottinggroups_inject_editor_assets');
$plugins->add_hook('index_start',                     'plottinggroups_handle_index_notice');
$plugins->add_hook('global_intermediate',             'plottinggroups_modcp_nav');
$plugins->add_hook('modcp_start',                     'plottinggroups_handle_modcp');
$plugins->add_hook('datahandler_post_insert_post_end',   'plottinggroups_handle_post');
$plugins->add_hook('datahandler_post_insert_thread_end', 'plottinggroups_handle_post');
$plugins->add_hook('parse_message_end',               'plottinggroups_render_tags');
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
        'version'       => '1.1.0',
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
    Plottinggroups_Installer::createTables();   // upgrade path: add tables new in later versions
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
    // Alert types are NOT removed here: re-adding them on activate would give
    // them new ids and orphan every existing alert (MyAlerts INNER JOINs
    // alerts on alert_types.id, so those alerts become invisible).
    Plottinggroups_Installer::removeStylesheet();
    Plottinggroups_Installer::removeTemplates();
    Plottinggroups_Installer::removeSettings();
}

function plottinggroups_uninstall(): void
{
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/installer.php';
    Plottinggroups_Installer::unregisterAlertTypes();
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
 * AJAX endpoint for the editor autocomplete (xmlhttp.php?action=plottinggroups_suggest
 * &query=...). Returns the matching approved groups as JSON and stops. Read-only,
 * so no post-key check; only names that are already public on the groups page are
 * exposed.
 */
function plottinggroups_suggest_xmlhttp(): void
{
    global $mybb;

    if ($mybb->get_input('action') !== 'plottinggroups_suggest') {
        return;
    }

    plottinggroups_load_core();

    $results = Plottinggroups_Database::searchApprovedGroups(
        (string)$mybb->get_input('query'), 10
    );

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['groups' => $results]);
    exit;
}

/**
 * Loads the #-autocomplete assets (script + config) into the post editor pages.
 * Runs on pre_output_page so it can append to the finished HTML; only fires on
 * pages that actually contain a MyBB editor and only when the setting is on.
 */
function plottinggroups_inject_editor_assets($page)
{
    global $mybb, $lang, $templates;

    if (!defined('THIS_SCRIPT')) {
        return $page;
    }

    $editor_scripts = ['newthread.php', 'newreply.php', 'editpost.php', 'showthread.php'];
    if (!in_array(THIS_SCRIPT, $editor_scripts, true)) {
        return $page;
    }

    if (!isset($mybb->settings['plottinggroups_autocomplete'])
        || (int)$mybb->settings['plottinggroups_autocomplete'] !== 1
    ) {
        return $page;
    }

    // No editor on this particular page (e.g. a thread with quick reply off) → skip.
    if (strpos($page, 'name="message"') === false) {
        return $page;
    }

    $lang->load('plottinggroups');

    eval('$plottinggroups_editor_assets = "' . $templates->get('plottinggroups_editor_assets') . '";');

    // Inject before the closing body tag so sceditor is already initialised.
    $pos = strripos($page, '</body>');
    if ($pos === false) {
        return $page . $plottinggroups_editor_assets;
    }

    return substr($page, 0, $pos) . $plottinggroups_editor_assets . substr($page, $pos);
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
 * ModCP navigation entry ({$plottinggroups_modcp_nav}) for team members. modcp.php renders
 * modcp_nav before its modcp_start hook, so the entry has to exist earlier.
 */
function plottinggroups_modcp_nav(): void
{
    global $mybb, $plottinggroups_modcp_nav;

    $plottinggroups_modcp_nav = '';
    if (!defined('THIS_SCRIPT') || THIS_SCRIPT !== 'modcp.php') {
        return;
    }

    plottinggroups_load_core();
    if (!Plottinggroups_Permissions::isTeam($mybb->user)) {
        return;
    }

    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/modcp.php';
    $plottinggroups_modcp_nav = Plottinggroups_Modcp::navItem();
}

/**
 * ModCP integration: for our own action, renders the group review page and stops.
 */
function plottinggroups_handle_modcp(): void
{
    global $mybb;

    plottinggroups_load_core();
    if (!Plottinggroups_Permissions::isTeam($mybb->user)) {
        return;
    }

    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/modcp.php';
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

    // Record the tag in each group's event feed (insert-only, mirroring the
    // alert). The thread subject is the human-readable label; the link matches
    // the alert's showthread target.
    $link    = 'showthread.php?tid=' . $tid . '&pid=' . $pid . '#pid' . $pid;
    $subject = plottinggroups_thread_subject($tid);
    foreach (array_keys($groups) as $gid) {
        Plottinggroups_Database::logEvent((int)$gid, 'tagged', $subject, $link, $poster, TIME_NOW);
    }
}

/**
 * Thread subject for a tid, or an empty string when it cannot be resolved
 * (the frontend falls back to a generic label on render).
 */
function plottinggroups_thread_subject(int $tid): string
{
    global $db;

    if ($tid <= 0) {
        return '';
    }
    $row = $db->fetch_array($db->simple_select('threads', 'subject', 'tid = ' . $tid, ['limit' => 1]));

    return $row ? (string)$row['subject'] : '';
}

/**
 * Renders #"Group name" tags in parsed post messages as a linked pill pointing
 * to the group's member list. Runs on parse_message_end, so the message is
 * already HTML-escaped: the quotes appear as &quot; (or as " when HTML is on).
 * Only approved, existing groups become pills; unknown names stay as typed.
 */
function plottinggroups_render_tags($message)
{
    if (strpos($message, '#') === false) {
        return $message;
    }

    // #"Name" in the escaped (&quot;) and, for allow_html posts, the raw (") form.
    $pattern = '/#(?:&quot;|")([^<"]+?)(?:&quot;|")/';
    if (!preg_match_all($pattern, $message, $matches) || empty($matches[1])) {
        return $message;
    }

    plottinggroups_load_core();

    $names = [];
    foreach ($matches[1] as $escaped) {
        $names[] = html_entity_decode($escaped, ENT_QUOTES, 'UTF-8');
    }

    $groups = Plottinggroups_Database::getGroupsByNames($names); // approved only
    if (!$groups) {
        return $message;
    }

    $by_name = [];
    foreach ($groups as $gid => $row) {
        $by_name[my_strtolower(trim((string)$row['name']))] = [
            'gid'  => (int)$gid,
            'name' => (string)$row['name'],
        ];
    }

    return preg_replace_callback($pattern, function ($m) use ($by_name) {
        $name = trim(html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
        $key  = my_strtolower($name);
        if (!isset($by_name[$key])) {
            return $m[0]; // unknown/unapproved group – leave the raw tag untouched
        }
        $group = $by_name[$key];

        return '<a href="plottinggroups.php?gid=' . $group['gid'] . '#plottinggroups_members" '
            . 'class="plottinggroups_tag" title="'
            . htmlspecialchars_uni($group['name']) . '">#'
            . htmlspecialchars_uni($group['name']) . '</a>';
    }, $message);
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
