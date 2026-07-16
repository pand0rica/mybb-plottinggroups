<?php
/**
 * plottinggroups – CLI self test (test server only).
 *
 * Run from the MyBB root: php tests/cli-selftest.php
 * Covers the #"Name" tag extraction (pure) and the group lookups against
 * throwaway SELFTEST fixtures, which are deleted again at the end. Not part of
 * a release zip.
 */

if (PHP_SAPI !== 'cli') {
    die('CLI only.');
}

define('IN_MYBB', 1);
chdir(dirname(__DIR__));
require_once './inc/init.php';

require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/database.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $ok): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo "ok   {$name}\n";
    } else {
        $failed++;
        echo "FAIL {$name}\n";
    }
}

/* ---- tag extraction (pure) -------------------------------------------- */

check('extract single',      Plottinggroups_Database::extractTaggedNames('x #"Alpha" y') === ['Alpha']);
check('extract multiple',    Plottinggroups_Database::extractTaggedNames('#"Alpha" and #"Beta"') === ['Alpha', 'Beta']);
check('extract dedupe',      Plottinggroups_Database::extractTaggedNames('#"Alpha" #"Alpha"') === ['Alpha']);
check('extract none',        Plottinggroups_Database::extractTaggedNames('no tags here') === []);
check('extract plain hash',  Plottinggroups_Database::extractTaggedNames('issue #42 and "quote"') === []);
check('extract strips quote', Plottinggroups_Database::extractTaggedNames('[quote]#"Alpha"[/quote] hi') === []);
check('extract trims',       Plottinggroups_Database::extractTaggedNames('#"  Alpha  "') === ['Alpha']);
check('extract special chars', Plottinggroups_Database::extractTaggedNames('#"Auror*innen"') === ['Auror*innen']);

/* ---- group lookups against SELFTEST fixtures -------------------------- */

$now   = TIME_NOW;
$gid_a = (int)$db->insert_query('plottinggroups_groups', [
    'name' => 'SELFTEST Group A', 'description' => '', 'approved' => 1,
    'submitted_uid' => 0, 'display_order' => 0, 'dateline' => $now,
]);
$gid_b = (int)$db->insert_query('plottinggroups_groups', [
    'name' => 'SELFTEST Group B', 'description' => '', 'approved' => 0,
    'submitted_uid' => 0, 'display_order' => 0, 'dateline' => $now,
]);
$db->insert_query('plottinggroups_members', ['gid' => $gid_a, 'uid' => 991, 'dateline' => $now]);
$db->insert_query('plottinggroups_members', ['gid' => $gid_a, 'uid' => 992, 'dateline' => $now]);

$by_name = Plottinggroups_Database::getGroupsByNames(['selftest group a']); // lowercase
check('getGroupsByNames approved + case-insensitive', array_key_exists($gid_a, $by_name));
check('getGroupsByNames excludes pending', Plottinggroups_Database::getGroupsByNames(['SELFTEST Group B']) === []);

$approved = Plottinggroups_Database::filterApprovedGids([$gid_a, $gid_b]);
check('filterApprovedGids keeps approved', in_array($gid_a, $approved, true));
check('filterApprovedGids drops pending', !in_array($gid_b, $approved, true));

$uids = Plottinggroups_Database::getGroupMemberUids([$gid_a]);
sort($uids);
check('getGroupMemberUids', $uids === [991, 992]);

$names = Plottinggroups_Database::groupNamesForGids([$gid_a]);
check('groupNamesForGids', isset($names[$gid_a]) && $names[$gid_a] === 'SELFTEST Group A');

/* ---- cleanup ---------------------------------------------------------- */

$db->delete_query('plottinggroups_members', 'gid IN (' . $gid_a . ',' . $gid_b . ')');
$db->delete_query('plottinggroups_groups', 'gid IN (' . $gid_a . ',' . $gid_b . ')');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
