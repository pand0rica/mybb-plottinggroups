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
                // team may approve/edit/delete the proposal right here
                $group_controls = $is_team ? self::groupControls((int)$gid, true) : '';
                eval('$group_rows .= "' . $templates->get('plottinggroups_groups_pending_bit') . '";');
            } else {
                // approved group names link to their member list below
                $group_name = '<a href="plottinggroups.php?gid=' . (int)$gid
                    . '#plottinggroups_members">' . htmlspecialchars_uni((string)$group['name']) . '</a>';
                // trow_selected = the theme's neutral "this row is selected"
                // highlight (trow_shaded is red and reads as deleted/moderated)
                $group_row_bg      = ((int)$gid === $selected_gid) ? 'trow_selected' : $altbg;
                $member_count      = isset($counts[$gid]) ? (int)$counts[$gid] : 0;
                $membership_button = $uid > 0
                    ? self::membershipButton((int)$gid, in_array((int)$gid, $user_gids, true))
                    : '&nbsp;';
                // team may edit/delete the group straight from the list
                $group_controls = $is_team ? self::groupControls((int)$gid) : '';
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

                // "Newest events" button in the panel header (navigation, GET)
                $plottinggroups_events_button = '<a class="button plottinggroups_button_small" '
                    . 'href="plottinggroups.php?action=events&amp;gid=' . $selected_gid . '">'
                    . htmlspecialchars_uni($lang->plottinggroups_btn_events) . '</a>';

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
                // team may add a member by username
                $add_member_row = $is_team ? self::addMemberRow($selected_gid) : '';
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

    /**
     * Team-only group edit form (plottinggroups.php?action=edit_group&gid=X).
     * Mirrors the ModCP editor so the team does not have to leave the frontend.
     */
    public static function renderEditForm(int $gid): string
    {
        global $mybb, $lang, $templates, $theme, $header, $headerinclude, $footer;

        $group = $gid > 0 ? Plottinggroups_Database::getGroup($gid) : null;
        if (!$group) {
            error($lang->plottinggroups_error_invalid_group);
        }

        add_breadcrumb($lang->plottinggroups_edit_group);

        $group_id         = (int)$group['gid'];
        $form_name        = htmlspecialchars_uni((string)$group['name']);
        $form_description = htmlspecialchars_uni((string)$group['description']);
        $form_order       = (int)$group['display_order'];

        eval('$html = "' . $templates->get('plottinggroups_edit') . '";');

        return $html;
    }

    /**
     * Team controls shown next to a group in the list: approve (pending only),
     * edit and delete.
     */
    private static function groupControls(int $gid, bool $pending = false): string
    {
        global $lang;

        $html = '';

        if ($pending) {
            $html .= self::postButton('approve_group', ['gid' => $gid],
                $lang->plottinggroups_btn_approve);
        }

        // GET form rather than a link, so this renders exactly like the submit
        // buttons next to it. No post key: it only opens the edit form, which
        // checks isTeam() itself.
        $html .= '<form action="plottinggroups.php" method="get" class="plottinggroups_inline_form">'
            . '<input type="hidden" name="action" value="edit_group" />'
            . '<input type="hidden" name="gid" value="' . $gid . '" />'
            . '<input type="submit" class="button plottinggroups_button_small" value="'
            . htmlspecialchars_uni($lang->plottinggroups_btn_edit) . '" />'
            . '</form> ';

        // Same delete_group action either way – a proposal is "rejected", an
        // already approved group is "deleted".
        $html .= $pending
            ? self::postButton('delete_group', ['gid' => $gid],
                $lang->plottinggroups_btn_reject, $lang->plottinggroups_confirm_reject)
            : self::postButton('delete_group', ['gid' => $gid],
                $lang->plottinggroups_btn_delete, $lang->plottinggroups_confirm_delete_group);

        return $html;
    }

    /**
     * A single state-changing control: one inline POST form rendered as a
     * button. All team/membership buttons on this page share it so they stay
     * visually identical and always carry the post key.
     *
     * @param array $fields extra hidden int fields (name => value)
     */
    private static function postButton(string $action, array $fields, string $label,
        string $confirm = ''): string
    {
        global $mybb;

        $onsubmit = $confirm !== ''
            ? ' onsubmit="return confirm(\'' . addslashes($confirm) . '\');"'
            : '';

        $hidden = '';
        foreach ($fields as $name => $value) {
            $hidden .= '<input type="hidden" name="' . htmlspecialchars_uni($name)
                . '" value="' . (int)$value . '" />';
        }

        return '<form action="plottinggroups.php" method="post" '
            . 'class="plottinggroups_inline_form"' . $onsubmit . '>'
            . '<input type="hidden" name="action" value="' . htmlspecialchars_uni($action) . '" />'
            . $hidden
            . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
            . '<input type="submit" class="button plottinggroups_button_small" value="'
            . htmlspecialchars_uni($label) . '" />'
            . '</form> ';
    }

    /** Team-only "add member by username" row below the member list. */
    private static function addMemberRow(int $gid): string
    {
        global $mybb, $lang;

        return '<tr><td class="tfoot" colspan="3">'
            . '<form action="plottinggroups.php" method="post" class="plottinggroups_inline_form">'
            . '<input type="hidden" name="action" value="add_group_member" />'
            . '<input type="hidden" name="gid" value="' . $gid . '" />'
            . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
            . '<label for="plottinggroups_add_member">'
            . htmlspecialchars_uni($lang->plottinggroups_add_member) . '</label> '
            . '<input type="text" class="textbox" id="plottinggroups_add_member" '
            . 'name="username" size="25" /> '
            . '<input type="submit" class="button plottinggroups_button_small" value="'
            . htmlspecialchars_uni($lang->plottinggroups_btn_add_member) . '" />'
            . '</form></td></tr>';
    }

    private static function membershipButton(int $gid, bool $is_member): string
    {
        global $lang;

        return self::postButton(
            $is_member ? 'leave_group' : 'join_group',
            ['gid' => $gid],
            $is_member ? $lang->plottinggroups_btn_leave : $lang->plottinggroups_btn_join
        );
    }

    /** Team-only button to remove a member from a group. */
    private static function removeMemberForm(int $gid, int $uid): string
    {
        global $lang;

        return self::postButton('remove_group_member', ['gid' => $gid, 'uid' => $uid],
            $lang->plottinggroups_btn_remove_member,
            $lang->plottinggroups_confirm_remove_member);
    }

    /**
     * The "newest events" feed of a single approved group. Merges stored events
     * (tagged posts, foreign-plugin pushes), live plotcp plot events, and events
     * contributed by other plugins via the plottinggroups_collect_events hook;
     * every source is sanitised, merged and shown newest first.
     */
    public static function renderEvents(int $gid): string
    {
        global $mybb, $lang, $templates, $plugins, $theme, $header, $headerinclude, $footer;

        $group = Plottinggroups_Database::getGroup($gid);
        if (!$group || (int)$group['approved'] !== 1) {
            error($lang->plottinggroups_error_invalid_group);
        }

        $limit = 100;

        // 1) stored + live plotcp events
        $events = array_merge(
            Plottinggroups_Database::getStoredEvents($gid, $limit),
            Plottinggroups_Database::getPlotcpEvents($gid, $limit)
        );

        // 2) events contributed live by other plugins (provider-hook pattern).
        // Providers append raw rows; the consumer sanitises and escapes.
        $hook_args = ['gid' => $gid, 'events' => []];
        $plugins->run_hooks('plottinggroups_collect_events', $hook_args);
        if (!empty($hook_args['events']) && is_array($hook_args['events'])) {
            $events = array_merge($events, $hook_args['events']);
        }

        // 3) sanitise, drop malformed, sort newest first, cap
        $clean = [];
        foreach ($events as $event) {
            $event = self::sanitizeEvent($event);
            if ($event !== null) {
                $clean[] = $event;
            }
        }
        usort($clean, function ($a, $b) {
            return $b['dateline'] <=> $a['dateline'];
        });
        $clean = array_slice($clean, 0, $limit);

        // resolve actor names in one batch
        $actor_uids = [];
        foreach ($clean as $event) {
            if ($event['uid'] > 0) {
                $actor_uids[] = $event['uid'];
            }
        }
        $actors = Plottinggroups_Database::getUsersByUids($actor_uids);

        $events_title = $lang->sprintf($lang->plottinggroups_events_title,
            htmlspecialchars_uni((string)$group['name']));

        $event_rows = '';
        $altbg = 'trow1';
        foreach ($clean as $event) {
            $event_when = my_date($mybb->settings['dateformat'], $event['dateline'])
                . ' ' . my_date($mybb->settings['timeformat'], $event['dateline']);

            $type_key        = 'plottinggroups_event_type_' . $event['type'];
            $event_type_label = htmlspecialchars_uni(
                isset($lang->$type_key) && $lang->$type_key !== ''
                    ? $lang->$type_key : $event['type']);

            $label = $event['title'] !== ''
                ? htmlspecialchars_uni($event['title'])
                : htmlspecialchars_uni($lang->plottinggroups_event_tagged_fallback);
            $event_link = '<a href="' . htmlspecialchars_uni($event['link']) . '">' . $label . '</a>';

            if ($event['uid'] > 0 && isset($actors[$event['uid']])) {
                $a = $actors[$event['uid']];
                $event_actor = build_profile_link(
                    format_name(htmlspecialchars_uni((string)$a['username']),
                        $a['usergroup'], $a['displaygroup']),
                    (int)$a['uid']);
            } else {
                $event_actor = '&nbsp;';
            }

            eval('$event_rows .= "' . $templates->get('plottinggroups_event_bit') . '";');
            $altbg = $altbg === 'trow1' ? 'trow2' : 'trow1';
        }
        if ($event_rows === '') {
            $event_rows = '<tr><td class="trow1" colspan="3" align="center">'
                . htmlspecialchars_uni($lang->plottinggroups_no_events) . '</td></tr>';
        }

        eval('$html = "' . $templates->get('plottinggroups_events') . '";');

        return $html;
    }

    /**
     * Validates and normalises one raw event row from any source. Casts types,
     * requires a type and a safe relative/http(s) link (blocks javascript: and
     * other schemes). Returns null for malformed rows so a broken provider never
     * breaks the feed.
     *
     * @return array|null ['type','title','link','uid','dateline']
     */
    private static function sanitizeEvent($event): ?array
    {
        if (!is_array($event)) {
            return null;
        }

        $type     = isset($event['type']) ? trim((string)$event['type']) : '';
        $title    = isset($event['title']) ? (string)$event['title'] : '';
        $link     = isset($event['link']) ? trim((string)$event['link']) : '';
        $uid      = isset($event['uid']) ? (int)$event['uid'] : 0;
        $dateline = isset($event['dateline']) ? (int)$event['dateline'] : 0;

        if ($type === '' || $link === '') {
            return null;
        }

        // Only relative or http(s) links; block javascript:, data:, etc.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $link)) {
            if (!preg_match('#^https?://#i', $link)) {
                return null;
            }
        }

        return [
            'type'     => $type,
            'title'    => $title,
            'link'     => $link,
            'uid'      => $uid > 0 ? $uid : 0,
            'dateline' => $dateline > 0 ? $dateline : 0,
        ];
    }
}
