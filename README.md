# Plotting Groups (plottinggroups)

A MyBB 1.8 plugin for RPG forums that manages **plotting groups** on their own:
members create, join and leave groups, propose new ones for team approval, and
tag a group inside a post to alert its members. Other plugins – such as the
[Plot Control Panel](https://github.com/pand0rica/plot-cp) – can bind these
groups through a small public API.

## Features

- **Self-service groups**: users join and leave approved groups with one click.
- **Group proposals**: users propose new groups; the team approves, edits or
  rejects them in the **Moderator CP** (with a board-index reminder while
  proposals are waiting).
- **Post tagging**: writing `#"Group name"` in a post alerts every member of that
  group (MyAlerts). Quotes are ignored and edits do not re-alert.
- **Member list**: click a group to see its members; the team can remove a
  member.
- **Public API** (`Plottinggroups_Api`) so plot/other plugins can list groups,
  resolve names, collect member uids and validate group ids.
- Migrates existing plotting groups from the Plot Control Panel automatically
  (preserving IDs) on first activation.

## Requirements

- MyBB 1.8.x
- PHP 7.4 or newer (tested up to PHP 8.1)
- **Optional:** [MyAlerts](https://community.mybb.com/mods.php?action=view&pid=avau1249) –
  without it the plugin works normally, just without notifications
- **Optional:** EnhancedAccountSwitcher – "new group" alerts then go to main
  accounts only

No other dependencies.

## Installation

1. Upload all files to your MyBB root directory (the folder structure matches the
   forum structure): `plottinggroups.php` → forum root; `inc/plugins/...`,
   `admin/modules/tools/plottinggroups.php`, `inc/languages/...` to the matching
   folders.
2. **ACP → Configuration → Plugins** → **Activate** "Plotting Groups". If the
   Plot Control Panel already has groups, they are copied over automatically.
3. **ACP → Configuration → Settings → Plotting Groups** → set the team groups and
   options.

> **Migrating from the Plot Control Panel:** activate **Plotting Groups first**
> (it adopts the existing groups, preserving IDs), then update/reactivate the
> Plot Control Panel – it drops its old group tables and starts using this
> plugin via the API.

## Configuration

| Setting | Meaning |
|---|---|
| **Team groups** | User groups that may approve proposals and manage groups in the ModCP. They also receive proposal alerts. |
| **Allow group proposals** | Users may propose new groups via the frontend form. |
| **Tag forums** | Forums where `#"Group name"` tagging alerts members. Empty = all forums. |

## Usage

### Groups page (`plottinggroups.php`)

Every approved group is listed with its description and member count. **Join**
and **Leave** are single clicks. Click a group name to see its members (with join
date); team members get a **Remove** button per member. The propose form below
the list submits a new group for approval.

### Reviewing proposals (team)

**Moderator CP → Plotting Groups**, or via the "new group proposed" alert / the
board-index notice. Per proposal: **Approve** (announces the group to all main
accounts), **Edit**, or **Reject** (deletes it). Approved groups can also be
edited or deleted here. The ACP (**Tools → Plotting Groups**) offers the same
management for admins; groups created there are approved immediately.

### Tagging a group in a post

Write `#"Group name"` anywhere in a reply or a new thread's first post. When the
post is saved, every member of that (approved) group – except you – receives a
MyAlerts notification linking to the post. You can tag several groups at once.
Tags inside `[quote]` blocks are ignored, and editing a post does not send the
alert again.

### Alerts (MyAlerts)

Each user can toggle these under **User CP → Edit Alert Settings**:

- *A new plotting group is created*
- *A user proposes a new plotting group* (team)
- *One of my plotting groups is tagged in a post*

## For plugin developers

Guard every call so it degrades when the plugin is absent:

```php
if (function_exists('plottinggroups_load_core')) {
    plottinggroups_load_core();
}
if (class_exists('Plottinggroups_Api')) {
    $groups  = Plottinggroups_Api::approvedGroups();       // gid => name
    $names   = Plottinggroups_Api::groupNames($gids);      // gid => name
    $uids    = Plottinggroups_Api::memberUids($gids);      // recipient uids
    $valid   = Plottinggroups_Api::filterApprovedGids($g); // approved gids
    $url     = Plottinggroups_Api::groupsUrl();
}
```

## Uninstallation

1. ACP → Configuration → Plugins → **Deactivate** "Plotting Groups" (removes
   templates, settings, stylesheet, alert types and the template injections).
2. Click **Uninstall** to drop the two database tables. All group data is deleted
   permanently.
3. Delete the uploaded files.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
