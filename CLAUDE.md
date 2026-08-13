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
  alert-recipient queries, `getGroupsByNames`/`groupNamesForGids`, the pure
  `extractTaggedNames($message)` (strips `[quote]`, scans `#"([^"]+)"`, unique),
  and the event feed: `logEvent`, `getStoredEvents(gid)`, and the guarded
  read-only plotcp bridge `getPlotcpEvents(gid)` (joins `plotcp_plot_groups` ⋈
  `plotcp_plots`, `visible=1`, `published_dateline`; `[]` when plotcp absent).
- `core/permissions.php` – `Plottinggroups_Permissions::isTeam` via own
  `plottinggroups_team_groups` setting (primary AND additionalgroups).
- `core/alerts.php` – `Plottinggroups_Alerts`: best-effort MyAlerts (class_exists
  guards + try/catch, creates managers itself, calls `commit()`). Senders:
  `sendNewGroup` (approval → main accounts), `sendProposalTeam` (proposal →
  team), `sendGroupTagged` (tag → members).
- `core/frontend.php` – `Plottinggroups_Frontend::renderGroups` (list / member
  panel / propose) + `membershipButton`, `removeMemberForm`, and
  `renderEvents(gid)` (the "newest events" feed: merges stored + plotcp + hook
  events via `sanitizeEvent`, sorts newest first). The member-panel header carries
  a `plottinggroups_btn_events` button → `plottinggroups.php?action=events&gid=`.
- `core/modcp.php` – `Plottinggroups_Modcp`: team-only proposal review
  (approve/edit/reject) + the two injected fragments. `const ACTION = 'plottinggroups_groups'`.
- `core/api.php` – **`Plottinggroups_Api`** (public facade): `approvedGroups()`,
  `groupNames(gids)`, `memberUids(gids)`, `filterApprovedGids(gids)`,
  `groupsUrl()`, and `logEvent(gid,type,title,link,uid,dateline)` (push a
  persistent event into a group's feed; approved-gid-guarded). Consumers guard with
  `function_exists('plottinggroups_load_core')` (= installed AND active) then
  `class_exists('Plottinggroups_Api')`.
- `myalerts_formatter.php` – one formatter per code; escapes every extra-details
  value on render.
- `plottinggroups.php` (MyBB root) – frontend entry, dispatch `submit_group`,
  `join_group`, `leave_group`, `remove_group_member`, `events` (read-only GET →
  `renderEvents`), default = groups list.
- `admin/modules/tools/plottinggroups.php` – ACP module (list + approve/delete +
  add/edit; ACP-created groups auto-approved).

## Tables

`plottinggroups_groups` (`approved` 0 = pending proposal),
`plottinggroups_members` (UNIQUE gid,uid),
`plottinggroups_events` (`gid,type,title,link,uid,dateline`; KEY `gid,dateline`;
title/link stored RAW, escaped on render). **No** plot link table – that stays in
plotcp (`plotcp_plot_groups`). `createTables()` is called in `install()` **and**
`activate()` (idempotent) so upgrades get `plottinggroups_events`.

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
`sendGroupTagged`. `[quote]` blocks are stripped before scanning. After alerting,
it logs one `plottinggroups_events` row **per matched gid** (`type='tagged'`,
title = thread subject via `plottinggroups_thread_subject`, link = the showthread
target) – insert-only, so edits neither re-alert nor re-log.

## The events feed

`renderEvents(gid)` builds a group's chronological feed from three sources, then
`sanitizeEvent`s each, sorts newest-first and caps at 100:

1. stored events (`getStoredEvents` – tagged posts + `Api::logEvent` pushes),
2. live plotcp plots (`getPlotcpEvents` – retroactive, guarded, no plotcp change),
3. the `plottinggroups_collect_events` hook (`$args = ['gid'=>…, 'events'=>[]]`;
   providers append raw rows `type/title/link/uid/dateline`).

Sanitizing contract (consumer-enforced): cast types, `link` must be relative or
`http(s)` (blocks `javascript:` etc.), malformed rows silently dropped; **escaping
happens only on render**. Type label = lang key `plottinggroups_event_type_<type>`
(fallback: the raw slug). Visible to everyone who can see the groups page; the
target links still obey the core's own forum permissions.

## What NOT to change

- Never patch core files; only the two reversible index/modcp_nav injections.
- Keep gids stable – plotcp's `plotcp_plot_groups.gid` references them
  (migrateData preserves them; new groups just continue AUTO_INCREMENT).
- Do not remove the `commit()` call in `Plottinggroups_Alerts::send()`.
- Use the insert-only datahandler hooks for tagging so edits do not re-alert (and
  do not re-log events).
- Keep the plotcp event source a **guarded read-only** bridge (`table_exists`); do
  not add a hard plotcp dependency or write to plotcp tables.
- Feed sources deliver RAW `title`/`link`; only `renderEvents` escapes. Keep
  `sanitizeEvent`'s scheme allow-list (relative or `http(s)` only).

## Testing

No local MyBB. Deploy via `tar cf - plottinggroups.php inc admin tests | ssh
<host> "cd <testforum>/httpdocs && tar xf -"`, lint with `php -l` over SSH.
`tests/cli-plugin.php install|activate|status` (lifecycle + migration counts;
`plottinggroups_events` must exist after install and be gone after uninstall),
`tests/cli-selftest.php` (tag extraction + group lookups + a `logEvent` →
`getStoredEvents` roundtrip and the `getPlotcpEvents` guard, against SELFTEST
fixtures, cleaned up). `tests/` is not part of a release zip.

**NOTE:** `tests/cli-plugin.php` shares its path with plotcp's on a combined test
install – deploy the right one before running it.
