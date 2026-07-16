# plottinggroups – Plugin Context

## Purpose

MyBB 1.8 plugin: standalone **plotting groups** for RPG forums, extracted from
the Plot Control Panel (plotcp). Users create/join/leave groups and propose new
ones for team approval (reviewed in the ModCP). A group can be tagged in a post
with `#"Group name"` → its members get a MyAlerts alert. Other plugins bind the
groups through the public `Plottinggroups_Api` facade (no hard dependency either
way).

## Architecture

Class/core split (modeled on plotcp):

- `inc/plugins/plottinggroups.php` – lifecycle + hook registration only; helpers
  `plottinggroups_load_core()` and `plottinggroups_alert_codes()`. Hooks:
  `global_start`/`xmlhttp` (formatter), `index_start` (team notice),
  `modcp_start` (ModCP nav + review), `datahandler_post_insert_post_end` **and**
  `datahandler_post_insert_thread_end` (both → `plottinggroups_handle_post`, the
  tag scan; insert-only so edits never re-alert), `admin_tools_menu` +
  `admin_tools_action_handler`.
- `core/installer.php` – tables, settings (idempotent), templates from `.tpl`,
  stylesheet in all themes (`attachedto index.php|plottinggroups.php|showthread.php`),
  alert types, two reversible core-template injections (`{$plottinggroups_group_notice}`
  after `{$header}` in index, `{$plottinggroups_modcp_nav}` before the final
  `</table>` in modcp_nav), and **`migrateData()`**: one-time gid/uid-preserving
  copy from plotcp's `plotcp_groups`/`plotcp_group_members` (only when our tables
  are empty and plotcp's exist).
- `core/database.php` – `Plottinggroups_Database`: group CRUD, membership,
  alert-recipient queries, `getGroupsByNames`/`groupNamesForGids`, and the pure
  `extractTaggedNames($message)` (strips `[quote]`, scans `#"([^"]+)"`, unique).
- `core/permissions.php` – `Plottinggroups_Permissions::isTeam` via own
  `plottinggroups_team_groups` setting (primary AND additionalgroups).
- `core/alerts.php` – `Plottinggroups_Alerts`: best-effort MyAlerts (class_exists
  guards + try/catch, creates managers itself, calls `commit()`). Senders:
  `sendNewGroup` (approval → main accounts), `sendProposalTeam` (proposal →
  team), `sendGroupTagged` (tag → members).
- `core/frontend.php` – `Plottinggroups_Frontend::renderGroups` (list / member
  panel / propose) + `membershipButton`, `removeMemberForm`.
- `core/modcp.php` – `Plottinggroups_Modcp`: team-only proposal review
  (approve/edit/reject) + the two injected fragments. `const ACTION = 'plottinggroups_groups'`.
- `core/api.php` – **`Plottinggroups_Api`** (public facade): `approvedGroups()`,
  `groupNames(gids)`, `memberUids(gids)`, `filterApprovedGids(gids)`,
  `groupsUrl()`. Consumers guard with
  `function_exists('plottinggroups_load_core')` (= installed AND active) then
  `class_exists('Plottinggroups_Api')`.
- `myalerts_formatter.php` – one formatter per code; escapes every extra-details
  value on render.
- `plottinggroups.php` (MyBB root) – frontend entry, dispatch `submit_group`,
  `join_group`, `leave_group`, `remove_group_member`, default = groups list.
- `admin/modules/tools/plottinggroups.php` – ACP module (list + approve/delete +
  add/edit; ACP-created groups auto-approved).

## Tables

`plottinggroups_groups` (`approved` 0 = pending proposal),
`plottinggroups_members` (UNIQUE gid,uid). **No** plot link table – that stays in
plotcp (`plotcp_plot_groups`).

## Alert rules

- `plottinggroups_new_group`: on approval to all main accounts (`as_uid = 0` if
  the column exists; banned/awaiting excluded).
- `plottinggroups_proposal`: on submit to team group members; link → ModCP.
- `plottinggroups_tagged`: on a post that tags `#"Name"`, to the members of the
  matched approved groups minus the poster, deduped. Extra details raw in DB,
  escaped only in the formatter.

## The tag feature

`plottinggroups_handle_post` fires on both insert hooks (reply + new-thread first
post; insert-only). It reads `$post->data['message'|'fid'|'uid']` + `$post->pid`
(+ tid from `$post->tid` or `data['tid']`), optionally filters by
`plottinggroups_tag_forums`, then `extractTaggedNames` → `getGroupsByNames`
(approved, case-insensitive) → `getGroupMemberUids` (minus poster) →
`sendGroupTagged`. `[quote]` blocks are stripped before scanning.

## What NOT to change

- Never patch core files; only the two reversible index/modcp_nav injections.
- Keep gids stable – plotcp's `plotcp_plot_groups.gid` references them
  (migrateData preserves them; new groups just continue AUTO_INCREMENT).
- Do not remove the `commit()` call in `Plottinggroups_Alerts::send()`.
- Use the insert-only datahandler hooks for tagging so edits do not re-alert.

## Testing

No local MyBB. Deploy via `tar cf - plottinggroups.php inc admin tests | ssh
<host> "cd <testforum>/httpdocs && tar xf -"`, lint with `php -l` over SSH.
`tests/cli-plugin.php install|activate|status` (lifecycle + migration counts),
`tests/cli-selftest.php` (tag extraction + group lookups against SELFTEST
fixtures, cleaned up). `tests/` is not part of a release zip.

**NOTE:** `tests/cli-plugin.php` shares its path with plotcp's on a combined test
install – deploy the right one before running it.
