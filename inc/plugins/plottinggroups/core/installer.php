<?php
/**
 * plottinggroups – Installer
 *
 * All install/activate/deactivate/uninstall database work lives here so the
 * lifecycle functions in inc/plugins/plottinggroups.php stay thin. Every
 * artefact created on activation (settings, templates, stylesheet, alert types,
 * two reversible core-template injections) is removed on deactivation.
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class Plottinggroups_Installer
{
    /** Loads inc/adminfunctions_templates.php (find_replace_templatesets) once. */
    private static function loadTemplateFunctions(): void
    {
        if (!function_exists('find_replace_templatesets')) {
            require_once MYBB_ROOT . 'inc/adminfunctions_templates.php';
        }
    }

    /** Same guarded load, plus inc/functions_themes.php (theme stylesheets). */
    private static function loadThemeFunctions(): void
    {
        self::loadTemplateFunctions();
        if (!function_exists('update_theme_stylesheet_list')) {
            require_once MYBB_ADMIN_DIR . 'inc/functions_themes.php';
        }
    }

    public static function createTables(): void
    {
        global $db;

        $collation = $db->build_create_table_collation();

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "plottinggroups_groups` (
            `gid`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name`          VARCHAR(120) NOT NULL DEFAULT '',
            `description`   TEXT NOT NULL,
            `approved`      TINYINT(1) NOT NULL DEFAULT 0,
            `submitted_uid` INT UNSIGNED NOT NULL DEFAULT 0,
            `display_order` INT NOT NULL DEFAULT 0,
            `dateline`      INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`gid`)
        ) ENGINE=InnoDB{$collation};");

        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "plottinggroups_members` (
            `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `gid`      INT UNSIGNED NOT NULL DEFAULT 0,
            `uid`      INT UNSIGNED NOT NULL DEFAULT 0,
            `dateline` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `gid_uid` (`gid`, `uid`),
            KEY `uid` (`uid`)
        ) ENGINE=InnoDB{$collation};");

        // Persistent per-group event log for the "newest events" feed. Only
        // events that cannot be re-derived (tagged posts, foreign-plugin pushes)
        // are stored here; plot events are pulled live from plotcp on render.
        $db->query("CREATE TABLE IF NOT EXISTS `" . TABLE_PREFIX . "plottinggroups_events` (
            `eid`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `gid`      INT UNSIGNED NOT NULL DEFAULT 0,
            `type`     VARCHAR(40) NOT NULL DEFAULT '',
            `title`    VARCHAR(255) NOT NULL DEFAULT '',
            `link`     VARCHAR(255) NOT NULL DEFAULT '',
            `uid`      INT UNSIGNED NOT NULL DEFAULT 0,
            `dateline` INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (`eid`),
            KEY `gid_dateline` (`gid`, `dateline`)
        ) ENGINE=InnoDB{$collation};");
    }

    /**
     * One-time adoption of the plotting groups that used to live in the Plot
     * Control Panel (plotcp). Copies rows PRESERVING gid/uid, so plotcp's
     * plotcp_plot_groups links keep resolving to the same groups. Runs on
     * activation; idempotent – only copies when our own tables are still empty
     * and the plotcp tables exist.
     */
    public static function migrateData(): void
    {
        global $db;

        if (!$db->table_exists('plottinggroups_groups')) {
            return;
        }
        $count = (int)$db->fetch_field(
            $db->simple_select('plottinggroups_groups', 'COUNT(*) AS c'), 'c');
        if ($count > 0) {
            return;
        }

        if ($db->table_exists('plotcp_groups')) {
            $db->write_query('INSERT INTO ' . TABLE_PREFIX . 'plottinggroups_groups
                (gid, name, description, approved, submitted_uid, display_order, dateline)
                SELECT gid, name, description, approved, submitted_uid, display_order, dateline
                FROM ' . TABLE_PREFIX . 'plotcp_groups');
        }
        if ($db->table_exists('plotcp_group_members')) {
            $db->write_query('INSERT INTO ' . TABLE_PREFIX . 'plottinggroups_members
                (gid, uid, dateline)
                SELECT gid, uid, dateline
                FROM ' . TABLE_PREFIX . 'plotcp_group_members');
        }
    }

    public static function dropTables(): void
    {
        global $db;

        $db->drop_table('plottinggroups_groups');
        $db->drop_table('plottinggroups_members');
        $db->drop_table('plottinggroups_events');
    }

    /**
     * Setting definitions: name (without prefix) => [title, description, optionscode, default].
     */
    private static function settingDefinitions(): array
    {
        return [
            'team_groups' => [
                'Team groups',
                'User groups that may approve plotting-group proposals and manage groups in the Moderator CP. Members of these groups also receive proposal alerts.',
                'groupselect', '',
            ],
            'allow_submissions' => [
                'Allow group proposals',
                'Registered users may propose new plotting groups via the frontend form. Proposals require team approval.',
                'onoff', '1',
            ],
            'tag_forums' => [
                'Tag forums',
                'Forums in which tagging a group with #"Group name" inside a post alerts the group members.',
                'forumselect', '',
            ],
            'new_group_main_only' => [
                'New-group alert: main accounts only',
                'On: the alert about a newly approved plotting group only goes to main accounts. Off: every account receives it, sub accounts included. Requires the EnhancedAccountSwitcher plugin – without it every account counts as a main account and this setting has no effect.',
                'onoff', '1',
            ],
            'autocomplete' => [
                'Tag autocomplete',
                'Show a suggestion dropdown in the post editor when a user types # so they can pick an existing plotting group. Inserts the #"Group name" tag automatically.',
                'onoff', '1',
            ],
        ];
    }

    /**
     * Idempotent: creates the setting group if missing, otherwise adds only the
     * individual settings that do not exist yet (upgrade path).
     */
    public static function installSettings(): void
    {
        global $db;

        $definitions = self::settingDefinitions();

        $existing = $db->fetch_array($db->simple_select('settinggroups', 'gid', "name = 'plottinggroups'"));
        if ($existing) {
            $gid = (int)$existing['gid'];
        } else {
            $gid = (int)$db->insert_query('settinggroups', [
                'name'        => 'plottinggroups',
                'title'       => 'Plotting Groups',
                'description' => 'Settings for the Plotting Groups plugin.',
                'disporder'   => 1,
                'isdefault'   => 0,
            ]);
        }

        $disporder = 1;
        foreach ($definitions as $suffix => $def) {
            $name = 'plottinggroups_' . $suffix;
            $row = $db->fetch_array($db->simple_select('settings', 'sid',
                "name = '" . $db->escape_string($name) . "'"));
            if (!$row) {
                $db->insert_query('settings', [
                    'name'        => $name,
                    'title'       => $db->escape_string($def[0]),
                    'description' => $db->escape_string($def[1]),
                    'optionscode' => $def[2],
                    'value'       => $def[3],
                    'disporder'   => $disporder,
                    'gid'         => $gid,
                ]);
            }
            $disporder++;
        }

        rebuild_settings();
    }

    public static function removeSettings(): void
    {
        global $db;

        $db->delete_query('settings', "name LIKE 'plottinggroups\_%'");
        $db->delete_query('settinggroups', "name = 'plottinggroups'");
        rebuild_settings();
    }

    public static function installTemplates(): void
    {
        global $db;

        $template_dir = MYBB_ROOT . 'inc/plugins/plottinggroups/templates/';
        if (!is_dir($template_dir)) {
            return;
        }

        self::removeTemplates();

        $handle = opendir($template_dir);
        if (!$handle) {
            return;
        }
        while (($file = readdir($handle)) !== false) {
            if (substr($file, -4) !== '.tpl') {
                continue;
            }
            $title   = substr($file, 0, -4);
            $content = (string)file_get_contents($template_dir . $file);
            $db->insert_query('templates', [
                'title'    => $db->escape_string($title),
                'template' => $db->escape_string($content),
                'sid'      => -1,
                'version'  => '',
                'dateline' => TIME_NOW,
            ]);
        }
        closedir($handle);
    }

    public static function removeTemplates(): void
    {
        global $db;

        $db->delete_query('templates', "title LIKE 'plottinggroups%'");
    }

    /**
     * Registers the plugin stylesheet in every theme, attached to index.php
     * (group notice), plottinggroups.php and showthread.php. Attachment is
     * refreshed on re-activation for existing rows too.
     */
    public static function installStylesheet(): void
    {
        global $db;

        self::loadThemeFunctions();

        $css_path = MYBB_ROOT . 'inc/plugins/plottinggroups/assets/plottinggroups.css';
        if (!file_exists($css_path)) {
            return;
        }

        $attachedto = 'index.php|plottinggroups.php|showthread.php|newthread.php|newreply.php|editpost.php';
        $css = $db->escape_string((string)file_get_contents($css_path));
        $themes = $db->simple_select('themes', 'tid');
        while ($theme = $db->fetch_array($themes)) {
            $tid = (int)$theme['tid'];
            $existing = $db->fetch_array($db->simple_select(
                'themestylesheets', 'sid',
                "name = 'plottinggroups.css' AND tid = '" . $tid . "'"
            ));
            if ($existing) {
                $db->update_query('themestylesheets', [
                    'attachedto'   => $attachedto,
                    'stylesheet'   => $css,
                    'lastmodified' => TIME_NOW,
                ], "sid = '" . (int)$existing['sid'] . "'", 1);
            } else {
                $sid = $db->insert_query('themestylesheets', [
                    'name'         => 'plottinggroups.css',
                    'tid'          => $tid,
                    'attachedto'   => $attachedto,
                    'stylesheet'   => $css,
                    'lastmodified' => TIME_NOW,
                    'cachefile'    => 'plottinggroups.css',
                ]);
                $db->update_query('themestylesheets',
                    ['cachefile' => "css.php?stylesheet={$sid}"], "sid = '{$sid}'", 1);
            }
            update_theme_stylesheet_list($tid);
        }
    }

    public static function removeStylesheet(): void
    {
        global $db;

        self::loadThemeFunctions();

        $db->delete_query('themestylesheets', "name = 'plottinggroups.css'");
        $themes = $db->simple_select('themes', 'tid');
        while ($theme = $db->fetch_array($themes)) {
            update_theme_stylesheet_list((int)$theme['tid']);
        }
    }

    /**
     * Injects {$plottinggroups_group_notice} after {$header} in the board index
     * so team members see pending group proposals on the front page. Removal
     * runs first to keep repeated activation from stacking injections.
     */
    public static function injectIndexNotice(): void
    {
        self::loadTemplateFunctions();

        self::removeIndexNoticeInjection();
        find_replace_templatesets(
            'index',
            '#' . preg_quote('{$header}') . '#',
            "{\$header}\n{\$plottinggroups_group_notice}"
        );
    }

    public static function removeIndexNoticeInjection(): void
    {
        self::loadTemplateFunctions();

        find_replace_templatesets(
            'index',
            '#' . preg_quote("\n{\$plottinggroups_group_notice}") . '#',
            '',
            0
        );
    }

    /**
     * Injects {$plottinggroups_modcp_nav} before the final </table> of the ModCP
     * navigation so the team gets a menu entry to the group review page.
     */
    public static function injectModcpNav(): void
    {
        self::loadTemplateFunctions();

        self::removeModcpNavInjection();
        find_replace_templatesets(
            'modcp_nav',
            '#</table>(?![\s\S]*</table>)#',
            "{\$plottinggroups_modcp_nav}</table>"
        );
    }

    public static function removeModcpNavInjection(): void
    {
        self::loadTemplateFunctions();

        find_replace_templatesets(
            'modcp_nav',
            '#' . preg_quote('{$plottinggroups_modcp_nav}') . '#',
            '',
            0
        );
    }

    /**
     * Registers the MyAlerts alert types. Best effort: silently skipped when
     * MyAlerts is not installed or incompatible.
     */
    public static function registerAlertTypes(): void
    {
        global $db, $cache;

        if (!class_exists('MybbStuff_MyAlerts_AlertTypeManager')
            || !class_exists('MybbStuff_MyAlerts_Entity_AlertType')
        ) {
            return;
        }

        try {
            $manager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();
            if (!$manager) {
                $manager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
            }
            if (!$manager) {
                return;
            }

            $types = [];
            foreach (plottinggroups_alert_codes() as $code) {
                $type = new MybbStuff_MyAlerts_Entity_AlertType();
                $type->setCode($code);
                $type->setEnabled(true);
                $type->setCanBeUserDisabled(true);
                $types[] = $type;
            }
            $manager->addTypes($types);
        } catch (Throwable $e) {
            // Incompatible MyAlerts version – plugin stays usable without alerts.
        }
    }

    public static function unregisterAlertTypes(): void
    {
        global $db, $cache;

        if (!class_exists('MybbStuff_MyAlerts_AlertTypeManager')) {
            return;
        }

        try {
            $manager = MybbStuff_MyAlerts_AlertTypeManager::getInstance();
            if (!$manager) {
                $manager = MybbStuff_MyAlerts_AlertTypeManager::createInstance($db, $cache);
            }
            if (!$manager) {
                return;
            }
            foreach (plottinggroups_alert_codes() as $code) {
                $type = $manager->getByCode($code);
                if ($type === null) {
                    continue;
                }
                // Drop the alerts first – MyAlerts leaves them behind otherwise.
                $db->delete_query('alerts', 'alert_type_id = ' . (int)$type->getId());
                $manager->deleteById((int)$type->getId());
            }
        } catch (Throwable $e) {
            // Best effort – see registerAlertTypes().
        }
    }
}
