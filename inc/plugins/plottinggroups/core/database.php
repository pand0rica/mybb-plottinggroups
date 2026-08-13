<?php
/**
 * plottinggroups – Database
 *
 * All group CRUD, membership and alert-recipient queries. gids are preserved
 * from the plotcp era (see Installer::migrateData), so other plugins that stored
 * gid references keep resolving.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class Plottinggroups_Database
{
    /* ---------------------------------------------------------------------
     * Groups
     * ------------------------------------------------------------------ */

    /** @return array gid => group row. false = pending first, then approved. */
    public static function getGroups(bool $approved_only = true): array
    {
        global $db;

        $groups = [];
        $where = $approved_only ? 'approved = 1' : '';
        $query = $db->simple_select('plottinggroups_groups', '*', $where,
            ['order_by' => 'approved ASC, display_order ASC, name ASC']);
        while ($row = $db->fetch_array($query)) {
            $groups[(int)$row['gid']] = $row;
        }

        return $groups;
    }

    public static function getGroup(int $gid): ?array
    {
        global $db;

        $row = $db->fetch_array($db->simple_select('plottinggroups_groups', '*', 'gid = ' . $gid));

        return $row ? $row : null;
    }

    public static function groupNameExists(string $name, int $exclude_gid = 0): bool
    {
        global $db;

        $where = "LOWER(name) = '" . $db->escape_string(my_strtolower(trim($name))) . "'";
        if ($exclude_gid > 0) {
            $where .= ' AND gid != ' . $exclude_gid;
        }
        $row = $db->fetch_array($db->simple_select('plottinggroups_groups', 'gid', $where, ['limit' => 1]));

        return (bool)$row;
    }

    /**
     * Approved groups whose name matches one of the given names (case-insensitive).
     * Used by the #"Name" post-tag scan.
     *
     * @return array gid => group row
     */
    public static function getGroupsByNames(array $names): array
    {
        global $db;

        $clean = [];
        foreach ($names as $n) {
            $n = trim((string)$n);
            if ($n !== '') {
                $clean[] = $db->escape_string(my_strtolower($n));
            }
        }
        $clean = array_values(array_unique($clean));
        if (!$clean) {
            return [];
        }

        $groups = [];
        $query = $db->simple_select('plottinggroups_groups', '*',
            "approved = 1 AND LOWER(name) IN ('" . implode("','", $clean) . "')");
        while ($row = $db->fetch_array($query)) {
            $groups[(int)$row['gid']] = $row;
        }

        return $groups;
    }

    /**
     * Extracts group names tagged in a post message with the #"Name" syntax.
     * [quote] blocks are stripped first so quoting a tagged post does not
     * re-trigger. Names are trimmed and de-duplicated.
     *
     * @return string[] tagged names (may be empty)
     */
    public static function extractTaggedNames(string $message): array
    {
        if (strpos($message, '#"') === false) {
            return [];
        }
        $scan = preg_replace('#\[quote.*?\].*?\[/quote\]#si', ' ', $message);
        if (!preg_match_all('#\#"([^"]+)"#', (string)$scan, $matches)) {
            return [];
        }
        $names = [];
        foreach ($matches[1] as $n) {
            $n = trim($n);
            if ($n !== '') {
                $names[] = $n;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * Approved groups whose name contains the given substring (case-insensitive).
     * Powers the #-autocomplete in the post editor. An empty query returns the
     * first $limit approved groups so the dropdown can open on a bare "#".
     *
     * @return array[] each ['gid' => int, 'name' => string]
     */
    public static function searchApprovedGroups(string $query, int $limit = 10): array
    {
        global $db;

        $limit = max(1, min(50, $limit));

        $where = 'approved = 1';
        $query = trim($query);
        if ($query !== '') {
            $where .= " AND LOWER(name) LIKE '%"
                . $db->escape_string_like(my_strtolower($query)) . "%'";
        }

        $results = [];
        $q = $db->simple_select('plottinggroups_groups', 'gid, name', $where,
            ['order_by' => 'name ASC', 'limit' => $limit]);
        while ($row = $db->fetch_array($q)) {
            $results[] = ['gid' => (int)$row['gid'], 'name' => (string)$row['name']];
        }

        return $results;
    }

    /** @return array gid => name for the given gids (any approval state). */
    public static function groupNamesForGids(array $gids): array
    {
        global $db;

        $gids = array_values(array_unique(array_filter(array_map('intval', $gids),
            function ($g) { return $g > 0; })));
        if (!$gids) {
            return [];
        }

        $names = [];
        $query = $db->simple_select('plottinggroups_groups', 'gid, name',
            'gid IN (' . implode(',', $gids) . ')',
            ['order_by' => 'display_order ASC, name ASC']);
        while ($row = $db->fetch_array($query)) {
            $names[(int)$row['gid']] = (string)$row['name'];
        }

        return $names;
    }

    /** Approved gids among the given ones (validates submitted input). */
    public static function filterApprovedGids(array $gids): array
    {
        global $db;

        $gids = array_values(array_unique(array_filter(array_map('intval', $gids),
            function ($gid) { return $gid > 0; })));
        if (!$gids) {
            return [];
        }

        $valid = [];
        $query = $db->simple_select('plottinggroups_groups', 'gid',
            'approved = 1 AND gid IN (' . implode(',', $gids) . ')');
        while ($row = $db->fetch_array($query)) {
            $valid[] = (int)$row['gid'];
        }

        return $valid;
    }

    /** @return int number of proposals still awaiting team approval */
    public static function getPendingGroupCount(): int
    {
        global $db;

        $row = $db->fetch_array($db->simple_select('plottinggroups_groups', 'COUNT(*) AS num', 'approved = 0'));

        return $row ? (int)$row['num'] : 0;
    }

    /** Marks a proposed group as approved (does not send any alert itself). */
    public static function approveGroup(int $gid): void
    {
        global $db;

        $db->update_query('plottinggroups_groups', ['approved' => 1], 'gid = ' . $gid);
    }

    /**
     * Deletes a group including its memberships. Best-effort: also clears any
     * plotcp plot↔group links so plots stop referencing the removed group.
     */
    public static function deleteGroup(int $gid): void
    {
        global $db;

        $db->delete_query('plottinggroups_members', 'gid = ' . $gid);
        $db->delete_query('plottinggroups_groups', 'gid = ' . $gid);
        $db->delete_query('plottinggroups_events', 'gid = ' . $gid);

        if ($db->table_exists('plotcp_plot_groups')) {
            $db->delete_query('plotcp_plot_groups', 'gid = ' . $gid);
        }
    }

    /* ---------------------------------------------------------------------
     * Members
     * ------------------------------------------------------------------ */

    /** @return array gid => member count */
    public static function getGroupMemberCounts(): array
    {
        global $db;

        $counts = [];
        $query = $db->query('SELECT gid, COUNT(*) AS members FROM ' . TABLE_PREFIX . 'plottinggroups_members
            GROUP BY gid');
        while ($row = $db->fetch_array($query)) {
            $counts[(int)$row['gid']] = (int)$row['members'];
        }

        return $counts;
    }

    /**
     * Members of a single group with the user fields for a profile link.
     *
     * @return array[] each ['uid','username','usergroup','displaygroup','dateline']
     */
    public static function getGroupMembers(int $gid): array
    {
        global $db;

        $members = [];
        $query = $db->query('
            SELECT u.uid, u.username, u.usergroup, u.displaygroup, gm.dateline
            FROM ' . TABLE_PREFIX . 'plottinggroups_members gm
            INNER JOIN ' . TABLE_PREFIX . 'users u ON (u.uid = gm.uid)
            WHERE gm.gid = ' . (int)$gid . '
            ORDER BY u.username ASC
        ');
        while ($row = $db->fetch_array($query)) {
            $members[] = $row;
        }

        return $members;
    }

    /**
     * Adds a user to a group. INSERT IGNORE + UNIQUE(gid, uid) makes a repeated
     * add a no-op instead of an error.
     *
     * @return bool true when the membership was newly created
     */
    public static function addMember(int $gid, int $uid): bool
    {
        global $db;

        if ($gid <= 0 || $uid <= 0) {
            return false;
        }

        $db->write_query('INSERT IGNORE INTO ' . TABLE_PREFIX . 'plottinggroups_members
            (gid, uid, dateline) VALUES (' . $gid . ', ' . $uid . ', ' . TIME_NOW . ')');

        return (int)$db->affected_rows() > 0;
    }

    public static function removeMember(int $gid, int $uid): void
    {
        global $db;

        if ($gid <= 0 || $uid <= 0) {
            return;
        }

        $db->delete_query('plottinggroups_members', 'gid = ' . $gid . ' AND uid = ' . $uid);
    }

    public static function isGroupMember(int $gid, int $uid): bool
    {
        global $db;

        $row = $db->fetch_array($db->simple_select('plottinggroups_members', 'id',
            'gid = ' . $gid . ' AND uid = ' . $uid, ['limit' => 1]));

        return (bool)$row;
    }

    /** @return array of gid the user is a member of */
    public static function getUserGroupIds(int $uid): array
    {
        global $db;

        $gids = [];
        $query = $db->simple_select('plottinggroups_members', 'gid', 'uid = ' . $uid);
        while ($row = $db->fetch_array($query)) {
            $gids[] = (int)$row['gid'];
        }

        return $gids;
    }

    /**
     * Resolves a username to a user row (case-insensitive). Used by the team's
     * "add member" forms in the frontend and the ACP.
     *
     * @return array|null ['uid','username'] or null when no such user exists
     */
    public static function getUserByUsername(string $username): ?array
    {
        global $db;

        $username = trim($username);
        if ($username === '') {
            return null;
        }

        $row = $db->fetch_array($db->simple_select('users', 'uid, username',
            "LOWER(username) = '" . $db->escape_string(my_strtolower($username)) . "'",
            ['limit' => 1]));

        return $row ? $row : null;
    }

    /** @return array uid => ['uid','username','usergroup','displaygroup'] */
    public static function getUsersByUids(array $uids): array
    {
        global $db;

        $uids = array_values(array_unique(array_filter(array_map('intval', $uids),
            function ($uid) { return $uid > 0; })));
        if (!$uids) {
            return [];
        }

        $users = [];
        $query = $db->simple_select('users', 'uid, username, usergroup, displaygroup',
            'uid IN (' . implode(',', $uids) . ')');
        while ($row = $db->fetch_array($query)) {
            $users[(int)$row['uid']] = $row;
        }

        return $users;
    }

    /* ---------------------------------------------------------------------
     * Alert recipient queries (deduplicated uid lists)
     * ------------------------------------------------------------------ */

    /** @return int[] distinct uids of all members of the given groups */
    public static function getGroupMemberUids(array $gids): array
    {
        global $db;

        $gids = array_values(array_unique(array_filter(array_map('intval', $gids),
            function ($gid) { return $gid > 0; })));
        if (!$gids) {
            return [];
        }

        $uids = [];
        $query = $db->query('SELECT DISTINCT uid FROM ' . TABLE_PREFIX . 'plottinggroups_members
            WHERE gid IN (' . implode(',', $gids) . ')');
        while ($row = $db->fetch_array($query)) {
            if ((int)$row['uid'] > 0) {
                $uids[] = (int)$row['uid'];
            }
        }

        return $uids;
    }

    /** @return int[] uids of all members of the configured team groups */
    public static function getTeamUids(): array
    {
        global $mybb;

        $setting = isset($mybb->settings['plottinggroups_team_groups'])
            ? (string)$mybb->settings['plottinggroups_team_groups'] : '';

        return self::getUidsInUsergroups($setting);
    }

    /** @return int[] uids whose primary or additional usergroup is in the CSV list */
    public static function getUidsInUsergroups(string $csv): array
    {
        global $db;

        $group_ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $csv)),
            function ($gid) { return $gid > 0; })));
        if (!$group_ids) {
            return [];
        }

        $conditions = ['usergroup IN (' . implode(',', $group_ids) . ')'];
        foreach ($group_ids as $gid) {
            $conditions[] = "CONCAT(',', additionalgroups, ',') LIKE '%,{$gid},%'";
        }

        $uids = [];
        $query = $db->simple_select('users', 'uid', '(' . implode(' OR ', $conditions) . ')');
        while ($row = $db->fetch_array($query)) {
            if ((int)$row['uid'] > 0) {
                $uids[] = (int)$row['uid'];
            }
        }

        return array_values(array_unique($uids));
    }

    /**
     * Recipients of the "new group approved" alert. Banned (7) and awaiting
     * activation (5) are always excluded.
     *
     * The plottinggroups_new_group_main_only setting decides whether sub
     * accounts are skipped. That restriction needs EnhancedAccountSwitcher's
     * users.as_uid column – without it every active user is a main account and
     * the setting has no effect.
     */
    public static function getNewGroupRecipientUids(): array
    {
        global $db, $mybb;

        $where = 'usergroup NOT IN (5,7)';

        $main_only = !isset($mybb->settings['plottinggroups_new_group_main_only'])
            || (int)$mybb->settings['plottinggroups_new_group_main_only'] === 1;

        if ($main_only && $db->field_exists('as_uid', 'users')) {
            $where .= ' AND as_uid = 0';
        }

        $uids = [];
        $query = $db->simple_select('users', 'uid', $where);
        while ($row = $db->fetch_array($query)) {
            if ((int)$row['uid'] > 0) {
                $uids[] = (int)$row['uid'];
            }
        }

        return $uids;
    }

    /* ---------------------------------------------------------------------
     * Events (the "newest events" feed)
     * ------------------------------------------------------------------ */

    /**
     * Records a persistent event for a group. title/link are stored RAW and
     * escaped only on render (same convention as the MyAlerts ExtraDetails).
     */
    public static function logEvent(int $gid, string $type, string $title, string $link, int $uid, int $dateline): void
    {
        global $db;

        if ($gid <= 0) {
            return;
        }

        $db->insert_query('plottinggroups_events', [
            'gid'      => $gid,
            'type'     => $db->escape_string(substr($type, 0, 40)),
            'title'    => $db->escape_string(substr($title, 0, 255)),
            'link'     => $db->escape_string(substr($link, 0, 255)),
            'uid'      => $uid,
            'dateline' => $dateline,
        ]);
    }

    /**
     * Stored events of a group, newest first.
     *
     * @return array[] each ['type','title','link','uid','dateline']
     */
    public static function getStoredEvents(int $gid, int $limit = 100): array
    {
        global $db;

        $events = [];
        $query = $db->simple_select('plottinggroups_events', 'type, title, link, uid, dateline',
            'gid = ' . $gid, ['order_by' => 'dateline DESC', 'limit' => $limit]);
        while ($row = $db->fetch_array($query)) {
            $events[] = [
                'type'     => (string)$row['type'],
                'title'    => (string)$row['title'],
                'link'     => (string)$row['link'],
                'uid'      => (int)$row['uid'],
                'dateline' => (int)$row['dateline'],
            ];
        }

        return $events;
    }

    /**
     * Published plots linked to a group, pulled live from the Plot Control
     * Panel. Read-only bridge, guarded on the plotcp tables existing, so
     * plottinggroups keeps working without plotcp. Newest first.
     *
     * @return array[] each ['type'=>'plot','title','link','uid','dateline']
     */
    public static function getPlotcpEvents(int $gid, int $limit = 100): array
    {
        global $db;

        if (!$db->table_exists('plotcp_plot_groups') || !$db->table_exists('plotcp_plots')) {
            return [];
        }

        $events = [];
        $query = $db->query('
            SELECT p.pid, p.title, p.submitted_uid, p.published_dateline
            FROM ' . TABLE_PREFIX . 'plotcp_plot_groups pg
            INNER JOIN ' . TABLE_PREFIX . 'plotcp_plots p ON (p.pid = pg.pid)
            WHERE pg.gid = ' . (int)$gid . ' AND p.visible = 1
            ORDER BY p.published_dateline DESC
            LIMIT ' . (int)$limit . '
        ');
        while ($row = $db->fetch_array($query)) {
            $events[] = [
                'type'     => 'plot',
                'title'    => (string)$row['title'],
                'link'     => 'plotcp.php?action=view&pid=' . (int)$row['pid'],
                'uid'      => (int)$row['submitted_uid'],
                'dateline' => (int)$row['published_dateline'],
            ];
        }

        return $events;
    }
}
