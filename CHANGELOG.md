# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [1.1.0] – Unreleased

### New

- **Newest events feed per group**: a "Newest events" button in a group's member
  panel opens a chronological page (newest first) of everything that would alert
  the group's members. Clicking an entry jumps to its source (the tagged post,
  the plot, …). Visible to everyone who can see the groups page; the links still
  obey the forum's own permissions.
  - Tagged posts are logged going forward into the new `plottinggroups_events`
    table (they cannot be reconstructed retroactively).
  - New published plots are pulled **live** from the Plot Control Panel through a
    read-only, guarded bridge – retroactive and requiring no change to plotcp.
- **Extensibility for other plugins**:
  - `Plottinggroups_Api::logEvent($gid, $type, $title, $link, $uid, $dateline)`
    lets other plugins push persistent events into a group's feed.
  - The `plottinggroups_collect_events` hook lets plugins contribute live-derived
    events on render (`$args = ['gid' => …, 'events' => []]`, append raw rows).
    Every supplied row is sanitised by the consumer (types cast, link limited to
    relative or `http(s)`, malformed rows silently dropped); escaping happens only
    on render.

### Fixed

- The ModCP navigation entry never appeared: `modcp.php` renders the navigation
  before the `modcp_start` hook. The entry is now set in `global_intermediate`.

## [1.0.0] – Unreleased

### New

- Standalone plotting groups extracted from the Plot Control Panel: create,
  join and leave groups, and propose new ones for team approval reviewed in the
  Moderator CP (with a board-index reminder while proposals are waiting).
- Post tagging: writing `#"Group name"` in a reply or a new thread's first post
  alerts every member of that approved group via MyAlerts. Fires on insert only
  (edits do not re-alert); `[quote]` blocks are ignored; the poster is excluded
  and recipients are deduplicated. Optional per-forum restriction via the **Tag
  forums** setting.
- Tag autocomplete in the post editor: typing `#` opens a suggestion dropdown
  (AJAX via `xmlhttp.php`) and inserts the correct `#"Group name"` tag. Works in
  the SCEditor WYSIWYG view and the plain-text/source view; toggled by the **Tag
  autocomplete** setting.
- Tag rendering: `#"Group name"` in a parsed post is shown as a coloured pill
  linking to the group's member list. Only approved, existing groups become a
  pill; unknown names stay as plain text.
- Group member list on the groups page (with join date).
- Team management in the frontend: approve, edit or reject waiting proposals
  (approving sends the same "new group" alert as the ModCP), edit and delete
  approved groups straight from the groups page, and add/remove members in a
  group's member list (add by exact username). All actions are POST-only,
  `verify_post_check()`-guarded and gated by
  `Plottinggroups_Permissions::isTeam()`.
- Member management in the ACP: a group's edit view lists its members with a
  **Remove** button and an **Add member** form; reachable via **Manage members**
  in the group's options menu.
- Public `Plottinggroups_Api` facade (`approvedGroups`, `groupNames`,
  `memberUids`, `filterApprovedGids`, `groupsUrl`) so other plugins can bind the
  groups without a hard dependency.
- Automatic one-time migration from the Plot Control Panel on first activation:
  copies existing `plotcp_groups` / `plotcp_group_members` **preserving the group
  IDs**, so plot↔group links keep resolving.
- Three MyAlerts alert types (all user-disableable): new group, proposal (team),
  group tagged. English and German (du) language files for frontend, ACP and
  MyAlerts.
- **New-group alert: main accounts only** setting controls whether the alert
  about a newly approved group reaches only main accounts or every account.
  Requires EnhancedAccountSwitcher's `users.as_uid`; without it every account
  counts as a main account and the setting has no effect.

### Security

- All state-changing actions are POST-only with `verify_post_check()`.
- Alert extra details are stored raw and escaped in the formatter
  (`htmlspecialchars_uni()`) to prevent stored XSS in the alert dropdown.
- Group approval/management is gated by the **Team groups** setting (checked
  against primary AND additional usergroups) in addition to MyBB's `canmodcp`.
