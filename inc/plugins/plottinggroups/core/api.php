<?php
/**
 * plottinggroups – public API facade
 *
 * The stable surface other plugins (e.g. the Plot Control Panel) bind to. A
 * consumer guards every call with:
 *
 *     if (function_exists('plottinggroups_load_core')) {
 *         plottinggroups_load_core();               // main file loaded => active
 *     }
 *     if (class_exists('Plottinggroups_Api')) { ... Plottinggroups_Api::x() ... }
 *
 * Because MyBB only loads a plugin's main file while it is ACTIVE,
 * function_exists('plottinggroups_load_core') is the "installed AND active"
 * gate; load_core() then pulls in this class and Plottinggroups_Database.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class Plottinggroups_Api
{
    /** API contract version, in case the surface ever changes. */
    const VERSION = 1;

    /** @return array gid => name of all approved groups (for select/checkbox UIs). */
    public static function approvedGroups(): array
    {
        $out = [];
        foreach (Plottinggroups_Database::getGroups(true) as $gid => $group) {
            $out[(int)$gid] = (string)$group['name'];
        }

        return $out;
    }

    /** @return array gid => name for the given gids (any approval state; for badges). */
    public static function groupNames(array $gids): array
    {
        return Plottinggroups_Database::groupNamesForGids($gids);
    }

    /** @return int[] distinct member uids of the given groups (alert recipients). */
    public static function memberUids(array $gids): array
    {
        return Plottinggroups_Database::getGroupMemberUids($gids);
    }

    /** @return int[] the approved gids among the given ones (validate input). */
    public static function filterApprovedGids(array $gids): array
    {
        return Plottinggroups_Database::filterApprovedGids($gids);
    }

    /** Relative URL of the groups page (for a consumer's toolbar link). */
    public static function groupsUrl(): string
    {
        return 'plottinggroups.php';
    }

    /**
     * Records a persistent event in a group's "newest events" feed. For events
     * that other plugins cannot re-derive on the fly (use the
     * 'plottinggroups_collect_events' hook for live-derivable ones instead).
     *
     * Only approved groups accept events; invalid gids are silently ignored so
     * a caller never breaks. title/link are stored RAW and escaped by the feed
     * on render – pass plain text; link must be relative or http(s).
     *
     * @param string $type  short type slug, shown via lang key
     *                       plottinggroups_event_type_<type> (fallback: the slug)
     * @param string $title human-readable label (e.g. a plot/thread title)
     * @param string $link  relative or http(s) URL of the source location
     */
    public static function logEvent(int $gid, string $type, string $title, string $link, int $uid = 0, int $dateline = 0): void
    {
        if (!Plottinggroups_Database::filterApprovedGids([$gid])) {
            return;
        }
        $type = trim($type);
        $link = trim($link);
        if ($type === '' || $link === '') {
            return;
        }
        if ($dateline <= 0) {
            $dateline = TIME_NOW;
        }

        Plottinggroups_Database::logEvent($gid, $type, $title, $link, $uid, $dateline);
    }
}
