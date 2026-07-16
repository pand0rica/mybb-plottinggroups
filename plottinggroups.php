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
    . 'plottinggroups_members,plottinggroups_member_bit,plottinggroups_propose';

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

$post_actions = ['submit_group', 'join_group', 'leave_group', 'remove_group_member'];

if (in_array($action, $post_actions, true)) {
    if ($mybb->request_method !== 'post') {
        error_no_permission();
    }
    verify_post_check($mybb->get_input('my_post_key'));

    if ((int)$mybb->user['uid'] <= 0) {
        error_no_permission();
    }
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

    /* ---- remove member (team) ------------------------------------------ */
    case 'remove_group_member':
        if (!Plottinggroups_Permissions::isTeam($mybb->user)) {
            error_no_permission();
        }

        $gid   = (int)$mybb->get_input('gid', MyBB::INPUT_INT);
        $group = Plottinggroups_Database::getGroup($gid);
        if (!$group || (int)$group['approved'] !== 1) {
            error($lang->plottinggroups_error_invalid_group);
        }

        $target_uid = (int)$mybb->get_input('uid', MyBB::INPUT_INT);
        if ($target_uid <= 0) {
            error($lang->plottinggroups_error_invalid_group);
        }
        $db->delete_query('plottinggroups_members', 'gid = ' . $gid . ' AND uid = ' . $target_uid);

        redirect('plottinggroups.php?gid=' . $gid . '#plottinggroups_members',
            $lang->plottinggroups_member_removed);
        break;

    /* ---- default: groups list ------------------------------------------ */
    default:
        output_page(Plottinggroups_Frontend::renderGroups(
            (int)$mybb->get_input('gid', MyBB::INPUT_INT)
        ));
        break;
}
