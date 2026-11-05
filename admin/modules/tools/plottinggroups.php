<?php
/**
 * plottinggroups – ACP module (tools).
 *
 * Group management: list with approve/delete, add/edit form. ACP-created groups
 * are approved immediately (and announced via alert).
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

// ACP language file (inc/languages/<lang>/admin/plottinggroups.lang.php).
$lang->load('plottinggroups');

if (function_exists('plottinggroups_load_core')) {
    plottinggroups_load_core();
} else {
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/database.php';
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/permissions.php';
    require_once MYBB_ROOT . 'inc/plugins/plottinggroups/core/alerts.php';
}

$page->add_breadcrumb_item($lang->plottinggroups, 'index.php?module=tools-plottinggroups');

$base_url = 'index.php?module=tools-plottinggroups';

if ($mybb->request_method === 'post') {

    $sub_action = $mybb->get_input('sub_action');
    $gid        = (int)$mybb->get_input('gid', MyBB::INPUT_INT);

    if ($sub_action === 'approve') {
        $group = Plottinggroups_Database::getGroup($gid);
        if (!$group || (int)$group['approved'] === 1) {
            flash_message($lang->plottinggroups_error_invalid_group, 'error');
            admin_redirect($base_url);
        }
        Plottinggroups_Database::approveGroup($gid);
        Plottinggroups_Alerts::sendNewGroup($group);
        flash_message($lang->plottinggroups_admin_group_approved, 'success');
        admin_redirect($base_url);
    }

    if ($sub_action === 'delete') {
        Plottinggroups_Database::deleteGroup($gid);
        flash_message($lang->plottinggroups_admin_group_deleted, 'success');
        admin_redirect($base_url);
    }

    // Member management – must be handled before the group-name validation below.
    if ($sub_action === 'add_member' || $sub_action === 'remove_member') {
        // raw & – this goes into a Location header, not into HTML
        $edit_url = $base_url . '&do=edit&gid=' . $gid;

        if (!Plottinggroups_Database::getGroup($gid)) {
            flash_message($lang->plottinggroups_error_invalid_group, 'error');
            admin_redirect($base_url);
        }

        if ($sub_action === 'add_member') {
            $user = Plottinggroups_Database::getUserByUsername((string)$mybb->get_input('username'));
            if (!$user) {
                flash_message($lang->plottinggroups_admin_error_user_not_found, 'error');
                admin_redirect($edit_url);
            }
            if (!Plottinggroups_Database::addMember($gid, (int)$user['uid'])) {
                flash_message($lang->plottinggroups_admin_error_already_member, 'error');
                admin_redirect($edit_url);
            }
            flash_message($lang->plottinggroups_admin_member_added, 'success');
            admin_redirect($edit_url);
        }

        Plottinggroups_Database::removeMember($gid, (int)$mybb->get_input('uid', MyBB::INPUT_INT));
        flash_message($lang->plottinggroups_admin_member_removed, 'success');
        admin_redirect($edit_url);
    }

    $name = trim((string)$mybb->get_input('name'));
    if ($name === '') {
        flash_message($lang->plottinggroups_error_group_name_missing, 'error');
        admin_redirect($base_url);
    }
    if (Plottinggroups_Database::groupNameExists($name, $sub_action === 'edit' ? $gid : 0)) {
        flash_message($lang->plottinggroups_error_group_name_exists, 'error');
        admin_redirect($base_url);
    }

    $data = [
        'name'          => $db->escape_string($name),
        'description'   => $db->escape_string(trim((string)$mybb->get_input('description'))),
        'display_order' => (int)$mybb->get_input('display_order', MyBB::INPUT_INT),
    ];

    if ($gid > 0 && $sub_action === 'edit') {
        $db->update_query('plottinggroups_groups', $data, 'gid = ' . $gid);
    } else {
        // Groups created by the team in the ACP are approved immediately.
        $data['approved']      = 1;
        $data['submitted_uid'] = (int)$mybb->user['uid'];
        $data['dateline']      = TIME_NOW;
        $gid = (int)$db->insert_query('plottinggroups_groups', $data);
        Plottinggroups_Alerts::sendNewGroup(['gid' => $gid, 'name' => $name]);
    }
    flash_message($lang->plottinggroups_admin_group_saved, 'success');
    admin_redirect($base_url);
}

$is_edit  = $mybb->get_input('do') === 'edit';
$edit_id  = $is_edit ? (int)$mybb->get_input('gid', MyBB::INPUT_INT) : 0;
$edit_row = $edit_id > 0 ? Plottinggroups_Database::getGroup($edit_id) : null;

$page->output_header($lang->plottinggroups);

$member_counts = Plottinggroups_Database::getGroupMemberCounts();

$groups = Plottinggroups_Database::getGroups(false);
$submitter_uids = [];
foreach ($groups as $group) {
    if ((int)$group['submitted_uid'] > 0) {
        $submitter_uids[] = (int)$group['submitted_uid'];
    }
}
$submitters = Plottinggroups_Database::getUsersByUids($submitter_uids);

$table = new Table;
$table->construct_header($lang->plottinggroups_admin_col_name);
$table->construct_header($lang->plottinggroups_admin_col_description);
$table->construct_header($lang->plottinggroups_admin_col_members, ['class' => 'align_center', 'width' => '10%']);
$table->construct_header($lang->plottinggroups_admin_col_status, ['class' => 'align_center', 'width' => '15%']);
$table->construct_header($lang->plottinggroups_admin_col_controls, ['class' => 'align_center', 'width' => '20%']);

foreach ($groups as $gid => $group) {
    $pending = (int)$group['approved'] === 0;

    $status = $pending ? $lang->plottinggroups_admin_pending : $lang->plottinggroups_admin_approved_label;
    if ($pending && isset($submitters[(int)$group['submitted_uid']])) {
        $status .= '<br /><small>' . htmlspecialchars_uni($lang->plottinggroups_admin_submitted_by) . ': '
            . htmlspecialchars_uni($submitters[(int)$group['submitted_uid']]['username']) . '</small>';
    }

    $table->construct_cell(htmlspecialchars_uni((string)$group['name']));
    $table->construct_cell(htmlspecialchars_uni((string)$group['description']));
    $table->construct_cell(isset($member_counts[$gid]) ? $member_counts[$gid] : 0, ['class' => 'align_center']);
    $table->construct_cell($status, ['class' => 'align_center']);

    $popup = new PopupMenu('group_' . $gid, $lang->plottinggroups_admin_options);
    if ($pending) {
        $popup->add_item($lang->plottinggroups_admin_approve,
            "javascript:document.getElementById('approve_group_{$gid}').submit();");
    }
    $popup->add_item($lang->plottinggroups_admin_edit, $base_url . '&amp;do=edit&amp;gid=' . $gid);
    $popup->add_item($lang->plottinggroups_admin_manage_members,
        $base_url . '&amp;do=edit&amp;gid=' . $gid . '#plottinggroups_members');
    $popup->add_item($lang->plottinggroups_admin_delete,
        "javascript:document.getElementById('delete_group_{$gid}').submit();",
        'return confirm(' . htmlspecialchars_uni((string)json_encode($lang->plottinggroups_admin_group_delete_confirm)) . ');');

    $forms = '';
    foreach (['approve', 'delete'] as $act) {
        $forms .= '<form id="' . $act . '_group_' . $gid . '" method="post" action="'
            . htmlspecialchars_uni($base_url) . '" style="display:none;">'
            . '<input type="hidden" name="sub_action" value="' . $act . '" />'
            . '<input type="hidden" name="gid" value="' . $gid . '" />'
            . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
            . '</form>';
    }

    $table->construct_cell($popup->fetch() . $forms, ['class' => 'align_center']);
    $table->construct_row();
}

if ($table->num_rows() === 0) {
    $table->construct_cell($lang->plottinggroups_admin_no_groups, ['colspan' => 5]);
    $table->construct_row();
}

$table->output($lang->plottinggroups_admin_groups_title);

$form_title = $edit_row ? $lang->plottinggroups_admin_edit_group : $lang->plottinggroups_admin_add_group;

$form = new Form($base_url, 'post');
echo $form->generate_hidden_field('sub_action', $edit_row ? 'edit' : 'add');
if ($edit_row) {
    echo $form->generate_hidden_field('gid', $edit_id);
}

$container = new FormContainer($form_title);
$container->output_row($lang->plottinggroups_admin_col_name, '',
    $form->generate_text_box('name', (string)($edit_row ? $edit_row['name'] : '')), 'name');
$container->output_row($lang->plottinggroups_admin_col_description, '',
    $form->generate_text_area('description', (string)($edit_row ? $edit_row['description'] : '')), 'description');
$container->output_row($lang->plottinggroups_admin_col_order, '',
    $form->generate_numeric_field('display_order',
        (int)($edit_row ? $edit_row['display_order'] : 0), ['min' => 0]), 'display_order');
$container->end();

$buttons   = [];
$buttons[] = $form->generate_submit_button($lang->plottinggroups_admin_save);
if ($edit_row) {
    $buttons[] = '<a class="button" href="' . htmlspecialchars_uni($base_url) . '">'
        . htmlspecialchars_uni($lang->plottinggroups_admin_cancel) . '</a>';
}
$form->output_submit_wrapper($buttons);
$form->end();

/* ---------------------------------------------------------------------------
 * Member management – only while editing a specific group.
 * ------------------------------------------------------------------------ */
if ($edit_row) {
    echo '<a name="plottinggroups_members" id="plottinggroups_members"></a>';

    $member_table = new Table;
    $member_table->construct_header($lang->plottinggroups_admin_col_member);
    $member_table->construct_header($lang->plottinggroups_admin_col_joined, ['width' => '30%']);
    $member_table->construct_header($lang->plottinggroups_admin_col_controls,
        ['class' => 'align_center', 'width' => '15%']);

    foreach (Plottinggroups_Database::getGroupMembers($edit_id) as $member) {
        $member_uid = (int)$member['uid'];

        $member_table->construct_cell(htmlspecialchars_uni((string)$member['username']));
        $member_table->construct_cell(
            my_date($mybb->settings['dateformat'], (int)$member['dateline']));

        // Raw form (not the ACP Form class) so it can live inside a table cell.
        $remove = '<form method="post" action="' . htmlspecialchars_uni($base_url) . '" '
            . 'style="display:inline;" onsubmit="return confirm('
            . htmlspecialchars_uni((string)json_encode($lang->plottinggroups_admin_member_remove_confirm)) . ');">'
            . '<input type="hidden" name="sub_action" value="remove_member" />'
            . '<input type="hidden" name="gid" value="' . $edit_id . '" />'
            . '<input type="hidden" name="uid" value="' . $member_uid . '" />'
            . '<input type="hidden" name="my_post_key" value="' . $mybb->post_code . '" />'
            . '<input type="submit" class="button" value="'
            . htmlspecialchars_uni($lang->plottinggroups_admin_remove_member) . '" />'
            . '</form>';
        $member_table->construct_cell($remove, ['class' => 'align_center']);
        $member_table->construct_row();
    }

    if ($member_table->num_rows() === 0) {
        $member_table->construct_cell($lang->plottinggroups_admin_no_members, ['colspan' => 3]);
        $member_table->construct_row();
    }

    $member_table->output($lang->sprintf($lang->plottinggroups_admin_members_title,
        htmlspecialchars_uni((string)$edit_row['name'])));

    $member_form = new Form($base_url, 'post');
    echo $member_form->generate_hidden_field('sub_action', 'add_member');
    echo $member_form->generate_hidden_field('gid', $edit_id);

    $member_container = new FormContainer($lang->plottinggroups_admin_add_member);
    $member_container->output_row($lang->plottinggroups_admin_username,
        $lang->plottinggroups_admin_username_desc,
        $member_form->generate_text_box('username', ''), 'username');
    $member_container->end();

    $member_form->output_submit_wrapper([
        $member_form->generate_submit_button($lang->plottinggroups_admin_add_member),
    ]);
    $member_form->end();
}

$page->output_footer();
