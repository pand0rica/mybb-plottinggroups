<?php
/**
 * plottinggroups – Permissions
 *
 * Team membership drives group approval/management (ModCP + ACP). Group
 * settings are evaluated against the primary usergroup AND additionalgroups.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class Plottinggroups_Permissions
{
    /** @return int[] primary + additional usergroup ids of the user array */
    public static function userGroupIds(array $user): array
    {
        $ids = [(int)(isset($user['usergroup']) ? $user['usergroup'] : 0)];
        if (!empty($user['additionalgroups'])) {
            foreach (explode(',', (string)$user['additionalgroups']) as $gid) {
                if ((int)$gid > 0) {
                    $ids[] = (int)$gid;
                }
            }
        }

        return $ids;
    }

    /** True when the user is in one of the groups of a CSV setting value. */
    public static function userInGroups(array $user, string $csv): bool
    {
        $allowed = array_filter(array_map('intval', explode(',', $csv)),
            function ($gid) { return $gid > 0; });
        if (!$allowed) {
            return false;
        }

        return (bool)array_intersect(self::userGroupIds($user), $allowed);
    }

    /** Team members may approve, edit and delete plotting groups. */
    public static function isTeam(array $user): bool
    {
        global $mybb;

        if ((int)(isset($user['uid']) ? $user['uid'] : 0) <= 0) {
            return false;
        }

        $setting = isset($mybb->settings['plottinggroups_team_groups'])
            ? (string)$mybb->settings['plottinggroups_team_groups'] : '';

        return self::userInGroups($user, $setting);
    }
}
