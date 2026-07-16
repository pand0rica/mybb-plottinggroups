# Changelog

All notable changes to this project will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

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
- Group member list on the groups page (with join date); team members can remove
  a member.
- Public `Plottinggroups_Api` facade (`approvedGroups`, `groupNames`,
  `memberUids`, `filterApprovedGids`, `groupsUrl`) so other plugins can bind the
  groups without a hard dependency.
- Automatic one-time migration from the Plot Control Panel on first activation:
  copies existing `plotcp_groups` / `plotcp_group_members` **preserving the group
  IDs**, so plot↔group links keep resolving.
- Three MyAlerts alert types (all user-disableable): new group, proposal (team),
  group tagged. English and German (du) language files for frontend, ACP and
  MyAlerts.

### Security

- All state-changing actions are POST-only with `verify_post_check()`.
- Alert extra details are stored raw and escaped in the formatter
  (`htmlspecialchars_uni()`) to prevent stored XSS in the alert dropdown.
- Group approval/management is gated by the **Team groups** setting (checked
  against primary AND additional usergroups) in addition to MyBB's `canmodcp`.
