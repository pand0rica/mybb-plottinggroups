<?php
/**
 * plottinggroups – Frontend rendering
 *
 * The groups page: list (pending vs approved), the member panel of a selected
 * group, and the propose form. Page skeleton lives in templates; small
 * fragments are built here with escaping. Uses core theme classes only.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class Plottinggroups_Frontend
{
    public static function renderGroups(int $selected_gid = 0): string
    {
        global $mybb, $lang, $templates, $theme, $header, $headerinclude, $footer;

        $uid       = (int)$mybb->user['uid'];
        $is_team   = Plottinggroups_Permissions::isTeam($mybb->user);
        $counts    = Plottinggroups_Database::getGroupMemberCounts();
        $user_gids = $uid > 0 ? Plottinggroups_Database::getUserGroupIds($uid) : [];

        $group_rows = '';
        $altbg = 'trow1';
        foreach (Plottinggroups_Database::getGroups(false) as $gid => $group) {
            $pending = (int)$group['approved'] === 0;

            // pending proposals are only visible to team and their submitter
            if ($pending && !$is_team && (int)$group['submitted_uid'] !== $uid) {
                continue;
            }

            $group_description = htmlspecialchars_uni((string)$group['description']);

            if ($pending) {
                // pending groups have no members yet, so no link
                $group_name = htmlspecialchars_uni((string)$group['name']);
                eval('$group_rows .= "' . $templates->get('plottinggroups_groups_pending_bit') . '";');
            } else {
                // approved group names link to their member list below
                $group_name = '<a href="plottinggroups.php?gid=' . (int)$gid
                    . '#plottinggroups_members">' . htmlspecialchars_uni((string)$group['name']) . '</a>';
                $group_row_bg      = ((int)$gid === $selected_gid) ? 'trow_shaded' : $altbg;
                $member_count      = isset($counts[$gid]) ? (int)$counts[$gid] : 0;
                $membership_button = $uid > 0
                    ? self::membershipButton((int)$gid, in_array((int)$gid, $user_gids, true))
                    : '&nbsp;';
                eval('$group_rows .= "' . $templates->get('plottinggroups_groups_bit') . '";');
            }
            $altbg = $altbg === 'trow1' ? 'trow2' : 'trow1';
        }
        if ($group_rows === '') {
            $group_rows = '<tr><td class="trow1" colspan="4" align="center">'
                . htmlspecialchars_uni($lang->plottinggroups_no_groups) . '</td></tr>';
        }

        // member list of the selected group (approved groups only; anyone who
        // can see the page may see who is in a group, like the member counts)
        $members_panel = '';
        if ($selected_gid > 0) {
            $sel_group = Plottinggroups_Database::getGroup($selected_gid);
            if ($sel_group && (int)$sel_group['approved'] === 1) {
                $members_title = $lang->sprintf($lang->plottinggroups_members_title,
                    htmlspecialchars_uni((string)$sel_group['name']));

                $member_rows = '';
                $altbg = 'trow1';
                foreach (Plottinggroups_Database::getGroupMembers($selected_gid) as $m) {
                    $member_link = build_profile_link(
                        format_name(htmlspecialchars_uni((string)$m['username']),
                            $m['usergroup'], $m['displaygroup']),
                        (int)$m['uid']);
                    $member_joined = my_date($mybb->settings['dateformat'], (int)$m['dateline']);
                    // team members can remove a person from the group
                    $member_controls = $is_team
                        ? self::removeMemberForm($selected_gid, (int)$m['uid'])
                        : '&nbsp;';
                    eval('$member_rows .= "' . $templates->get('plottinggroups_member_bit') . '";');
                    $altbg = $altbg === 'trow1' ? 'trow2' : 'trow1';
                }
                if ($member_rows === '') {
                    $member_rows = '<tr><td class="trow1" colspan="3">'
                        . htmlspecialchars_uni($lang->plottinggroups_no_members) . '</td></tr>';
                }
                eval('$members_panel = "' . $templates->get('plottinggroups_members') . '";');
            }
        }

        $propose_form = '';
        if ($uid > 0
            && isset($mybb->settings['plottinggroups_allow_submissions'])
            && (int)$mybb->settings['plottinggroups_allow_submissions'] === 1
        ) {
            eval('$propose_form = "' . $templates->get('plottinggroups_propose') . '";');
        }

        eval('$html = "' . $templates->get('plottinggroups_page') . '";');

        return $html;
    }

    private static function membershipButton(int $gid, bool $is_member): string
    {
        global $mybb, $lang;

        $action = $is_member ? 'leave_group' : 'join_group';
        $label  = $is_member ? $lang->plottinggroups_btn_leave : $lang->plottinggroups_btn_join;

        return '<form action="plottinggroups.php" method="post" class="plottinggroups_inline_form">'
            . '<input type="hidden" name="action" value="' . $action . '" />'
            . '<input type="hidden" name="gid" value="' . $gid . '" />'
            . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
            . '<input type="submit" class="button plottinggroups_button_small" value="'
            . htmlspecialchars_uni($label) . '" />'
            . '</form>';
    }

    /** Team-only button to remove a member from a group. */
    private static function removeMemberForm(int $gid, int $uid): string
    {
        global $mybb, $lang;

        return '<form action="plottinggroups.php" method="post" class="plottinggroups_inline_form" '
            . 'onsubmit="return confirm(\'' . addslashes($lang->plottinggroups_confirm_remove_member) . '\');">'
            . '<input type="hidden" name="action" value="remove_group_member" />'
            . '<input type="hidden" name="gid" value="' . $gid . '" />'
            . '<input type="hidden" name="uid" value="' . $uid . '" />'
            . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
            . '<input type="submit" class="button plottinggroups_button_small" value="'
            . htmlspecialchars_uni($lang->plottinggroups_btn_remove_member) . '" />'
            . '</form>';
    }
}
