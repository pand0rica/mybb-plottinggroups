<?php
/**
 * plottinggroups – frontend entry point.
 *
 * Views: groups list with self-service join/leave, member panel, and the
 * propose form. Every state-changing action requires POST + verify_post_check().
 */

define('IN_MYBB', 1);
define('THIS_SCRIPT', 'plottinggroups.php');

$templatelist = 'plottinggroups_page,plottinggroups_groups_bit,plottinggroups_groups_pending_bit,'
    . 'plottinggroups_members,plottinggroups_member_bit,plottinggroups_propose,plottinggroups_edit,'
    . 'plottinggroups_events,plottinggroups_event_bit';

require_once './global.php';

// Inactive plugin: the loader from inc/plugins/plottinggroups.php is not loaded.
if (!function_exists('plottinggroups_load_core')) {
    error_no_permission();
}

plottinggroups_load_core();
require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/frontend.php';

$lang->load('plottinggroups');

add_breadcrumb($lang->plottinggroups_title, 'plottinggroups.php');

$action = $mybb->get_input('action');

/* -------------------------------------------------------------------------
 * POST actions
 * ---------------------------------------------------------------------- */

$post_actions = ['submit_group', 'join_group', 'leave_group', 'remove_group_member',
    'add_group_member', 'save_group', 'delete_group', 'approve_group'];

/** Team-only actions – checked once, after the shared POST/login guard below. */
$team_actions = ['remove_group_member', 'add_group_member', 'save_group', 'delete_group',
    'approve_group'];

if (in_array($action, $post_actions, true)) {
    if ($mybb->request_method !== 'post') {
        error_no_permission();
    }
    verify_post_check($mybb->get_input('my_post_key'));

    if ((int)$mybb->user['uid'] <= 0) {
        error_no_permission();
    }
}

if (in_array($action, $team_actions, true) && !Plottinggroups_Permissions::isTeam($mybb->user)) {
    error_no_permission();
}

switch ($action) {
    /* ---- group proposal ------------------------------------------------ */
    case 'submit_group':
        if (!isset($mybb->settings['plottinggroups_allow_submissions'])
            || (int)$mybb->settings['plottinggroups_allow_submissions'] !== 1
        ) {
            error($lang->plottinggroups_error_submissions_disabled);
        }

        $name = trim((string)$mybb->get_input('name'));
        if ($name === '') {
            error($lang->plottinggroups_error_group_name_missing);
        }
        if (Plottinggroups_Database::groupNameExists($name)) {
            error($lang->plottinggroups_error_group_name_exists);
        }

        $gid = (int)$db->insert_query('plottinggroups_groups', [
            'name'          => $db->escape_string($name),
            'description'   => $db->escape_string(trim((string)$mybb->get_input('description'))),
            'approved'      => 0,
            'submitted_uid' => (int)$mybb->user['uid'],
            'dateline'      => TIME_NOW,
        ]);

        Plottinggroups_Alerts::sendProposalTeam(['gid' => $gid, 'name' => $name]);

        redirect('plottinggroups.php', $lang->plottinggroups_group_submitted);
        break;

    /* ---- membership ---------------------------------------------------- */
    case 'join_group':
    case 'leave_group':
        $gid   = (int)$mybb->get_input('gid', MyBB::INPUT_INT);
        $group = Plottinggroups_Database::getGroup($gid);
        if (!$group || (int)$group['approved'] !== 1) {
            error($lang->plottinggroups_error_invalid_group);
        }

        $uid = (int)$mybb->user['uid'];
        if ($action === 'join_group') {
            // INSERT IGNORE + UNIQUE(gid, uid): double joins stay a no-op
            $db->write_query('INSERT IGNORE INTO ' . TABLE_PREFIX . 'plottinggroups_members
                (gid, uid, dateline) VALUES (' . $gid . ', ' . $uid . ', ' . TIME_NOW . ')');
            redirect('plottinggroups.php', $lang->plottinggroups_joined);
        } else {
            $db->delete_query('plottinggroups_members', 'gid = ' . $gid . ' AND uid = ' . $uid);
            redirect('plottinggroups.php', $lang->plottinggroups_left);
        }
        break;

    /* ---- add / remove member (team) ------------------------------------ */
    case 'add_group_member':
    case 'remove_group_member':
        $gid   = (int)$mybb->get_input('gid', MyBB::INPUT_INT);
        $group = Plottinggroups_Database::getGroup($gid);
        if (!$group || (int)$group['approved'] !== 1) {
            error($lang->plottinggroups_error_invalid_group);
        }

        if ($action === 'add_group_member') {
            $user = Plottinggroups_Database::getUserByUsername(
                (string)$mybb->get_input('username'));
            if (!$user) {
                error($lang->plottinggroups_error_user_not_found);
            }
            if (!Plottinggroups_Database::addMember($gid, (int)$user['uid'])) {
                error($lang->plottinggroups_error_already_member);
            }
            redirect('plottinggroups.php?gid=' . $gid . '#plottinggroups_members',
                $lang->plottinggroups_member_added);
        }

        $target_uid = (int)$mybb->get_input('uid', MyBB::INPUT_INT);
        if ($target_uid <= 0) {
            error($lang->plottinggroups_error_invalid_group);
        }
        Plottinggroups_Database::removeMember($gid, $target_uid);

        redirect('plottinggroups.php?gid=' . $gid . '#plottinggroups_members',
            $lang->plottinggroups_member_removed);
        break;

    /* ---- edit / delete group (team) ------------------------------------ */
    case 'save_group':
        $gid   = (int)$mybb->get_input('gid', MyBB::INPUT_INT);
        $group = Plottinggroups_Database::getGroup($gid);
        if (!$group) {
            error($lang->plottinggroups_error_invalid_group);
        }

        $name = trim((string)$mybb->get_input('name'));
        if ($name === '') {
            error($lang->plottinggroups_error_group_name_missing);
        }
        if (Plottinggroups_Database::groupNameExists($name, $gid)) {
            error($lang->plottinggroups_error_group_name_exists);
        }

        $db->update_query('plottinggroups_groups', [
            'name'          => $db->escape_string($name),
            'description'   => $db->escape_string(trim((string)$mybb->get_input('description'))),
            'display_order' => (int)$mybb->get_input('display_order', MyBB::INPUT_INT),
        ], 'gid = ' . $gid);

        redirect('plottinggroups.php', $lang->plottinggroups_group_saved);
        break;

    case 'delete_group':
        $gid   = (int)$mybb->get_input('gid', MyBB::INPUT_INT);
        $group = Plottinggroups_Database::getGroup($gid);
        if (!$group) {
            error($lang->plottinggroups_error_invalid_group);
        }
        Plottinggroups_Database::deleteGroup($gid);

        redirect('plottinggroups.php', $lang->plottinggroups_group_deleted);
        break;

    case 'approve_group':
        $gid   = (int)$mybb->get_input('gid', MyBB::INPUT_INT);
        $group = Plottinggroups_Database::getGroup($gid);
        if (!$group || (int)$group['approved'] === 1) {
            error($lang->plottinggroups_error_invalid_group);
        }
        Plottinggroups_Database::approveGroup($gid);
        // Announce the now-available group to the members (as the ModCP does).
        Plottinggroups_Alerts::sendNewGroup($group);

        redirect('plottinggroups.php', $lang->plottinggroups_group_approved);
        break;

    /* ---- edit form (team, GET) ----------------------------------------- */
    case 'edit_group':
        if (!Plottinggroups_Permissions::isTeam($mybb->user)) {
            error_no_permission();
        }
        output_page(Plottinggroups_Frontend::renderEditForm(
            (int)$mybb->get_input('gid', MyBB::INPUT_INT)
        ));
        break;

    /* ---- newest events feed of a group (GET) --------------------------- */
    case 'events':
        output_page(Plottinggroups_Frontend::renderEvents(
            (int)$mybb->get_input('gid', MyBB::INPUT_INT)
        ));
        break;

    /* ---- default: groups list ------------------------------------------ */
    default:
        output_page(Plottinggroups_Frontend::renderGroups(
            (int)$mybb->get_input('gid', MyBB::INPUT_INT)
        ));
        break;
}
