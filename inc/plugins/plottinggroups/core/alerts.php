<?php
/**
 * plottinggroups – MyAlerts integration (optional dependency)
 *
 * Every path is best effort: when MyAlerts is missing or incompatible the
 * methods return silently and the plugin keeps working without alerts.
 * ExtraDetails are stored RAW – the formatter escapes on render.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class Plottinggroups_Alerts
{
    public static function isAvailable(): bool
    {
        return class_exists('MybbStuff_MyAlerts_AlertTypeManager')
            && class_exists('MybbStuff_MyAlerts_AlertManager')
            && class_exists('MybbStuff_MyAlerts_Entity_Alert');
    }

    /**
     * Sends one alert of the given type to every uid in the list. MyAlerts
     * itself skips users who disabled the alert type in their UCP settings.
     *
     * @param string $code      full alert type code (plottinggroups_*)
     * @param int[]  $uids      recipient uids (deduplicated here)
     * @param array  $extra     raw extra details for the formatter
     * @param int    $object_id related object id (gid/pid)
     * @return int number of recipients the alert was handed to MyAlerts for
     */
    public static function send(string $code, array $uids, array $extra, int $object_id = 0): int
    {
        global $mybb, $db, $cache, $plugins;

        if (!self::isAvailable()) {
            return 0;
        }

        $uids = array_values(array_unique(array_filter(array_map('intval', $uids),
            function ($uid) { return $uid > 0; })));
        if (!$uids) {
            return 0;
        }

        $sent = 0;
        try {
            $type_manager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();
            if (!$type_manager) {
                $type_manager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
            }
            $alert_manager = MybbStuff_MyAlerts_AlertManager::getInstance();
            if (!$alert_manager && $type_manager) {
                // MyAlerts creates its instances at global_start; in other
                // contexts (CLI, early hooks) we create them ourselves.
                $alert_manager = MybbStuff_MyAlerts_AlertManager::createInstance(
                    $mybb, $db, $cache, $plugins, $type_manager);
            }
            if (!$type_manager || !$alert_manager) {
                return 0;
            }

            $alert_type = $type_manager->getByCode($code);
            if ($alert_type === null) {
                return 0;
            }

            $from_uid = (int)(isset($mybb->user['uid']) ? $mybb->user['uid'] : 0);

            foreach ($uids as $uid) {
                $alert = new MybbStuff_MyAlerts_Entity_Alert($uid, $alert_type, $object_id);
                if ($from_uid > 0) {
                    $alert->setFromUserId($from_uid);
                }
                $alert->setExtraDetails($extra);
                $alert_manager->addAlert($alert);
                $sent++;
            }

            // addAlert() only queues; MyAlerts flushes at the end of a web
            // request, but not in CLI/early-hook contexts. Committing here is
            // safe: the queue is emptied, later commits find nothing.
            $alert_manager->commit();
        } catch (Throwable $e) {
            // Incompatible MyAlerts version – never break the page for alerts.
        }

        return $sent;
    }

    /**
     * New-group alert on approval. Recipients depend on the
     * plottinggroups_new_group_main_only setting (see
     * getNewGroupRecipientUids).
     */
    public static function sendNewGroup(array $group): int
    {
        return self::send('plottinggroups_new_group', Plottinggroups_Database::getNewGroupRecipientUids(), [
            'group_name' => (string)$group['name'],
            'link'       => 'plottinggroups.php',
        ], (int)$group['gid']);
    }

    /** Proposal alert to the team groups (points at the ModCP review page). */
    public static function sendProposalTeam(array $group): int
    {
        return self::send('plottinggroups_proposal', Plottinggroups_Database::getTeamUids(), [
            'group_name' => (string)$group['name'],
            'link'       => 'modcp.php?action=plottinggroups_groups', // = Plottinggroups_Modcp::ACTION
        ], (int)$group['gid']);
    }

    /**
     * "Your group was tagged in a post" alert to the members of the matched
     * groups (the poster is excluded by the caller). The from-user is the
     * poster ($mybb->user at post-insert time).
     *
     * @param int[]    $uids        member recipients
     * @param string[] $group_names names of the tagged groups
     */
    public static function sendGroupTagged(array $uids, array $group_names, int $tid, int $pid): int
    {
        return self::send('plottinggroups_tagged', $uids, [
            'group_name' => implode(', ', $group_names),
            'link'       => 'showthread.php?tid=' . $tid . '&pid=' . $pid . '#pid' . $pid,
        ], $pid);
    }
}
