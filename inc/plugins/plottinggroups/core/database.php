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
     * All active main accounts. With EnhancedAccountSwitcher installed only
     * accounts with as_uid = 0 (main accounts) are returned; without it every
     * active user counts. Banned (7) and awaiting activation (5) are excluded.
     */
    public static function getMainAccountUids(): array
    {
        global $db;

        $where = 'usergroup NOT IN (5,7)';
        if ($db->field_exists('as_uid', 'users')) {
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
}
