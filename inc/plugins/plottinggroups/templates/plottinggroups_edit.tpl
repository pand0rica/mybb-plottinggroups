<html>
<head>
<title>{$mybb->settings['bbname']} - {$lang->plottinggroups_edit_group}</title>
{$headerinclude}
</head>
<body>
{$header}
<form action="plottinggroups.php" method="post">
<input type="hidden" name="action" value="save_group" />
<input type="hidden" name="gid" value="{$group_id}" />
<input type="hidden" name="my_post_key" value="{$mybb->post_code}" />
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder">
<tr>
<td class="thead" colspan="2"><strong>{$lang->plottinggroups_edit_group}</strong></td>
</tr>
<tr>
<td class="trow1" width="22%"><strong>{$lang->plottinggroups_group_name}</strong></td>
<td class="trow1"><input type="text" class="textbox" name="name" value="{$form_name}" maxlength="120" size="40" /></td>
</tr>
<tr>
<td class="trow2" valign="top"><strong>{$lang->plottinggroups_group_description}</strong></td>
<td class="trow2"><textarea name="description" rows="3" cols="60">{$form_description}</textarea></td>
</tr>
<tr>
<td class="trow1"><strong>{$lang->plottinggroups_display_order}</strong></td>
<td class="trow1"><input type="text" class="textbox" name="display_order" value="{$form_order}" size="5" /></td>
</tr>
<tr>
<td class="tfoot" colspan="2" align="center">
<input type="submit" class="button" value="{$lang->plottinggroups_btn_save}" />
<a href="plottinggroups.php" class="button">{$lang->plottinggroups_btn_cancel}</a>
</td>
</tr>
</table>
</form>
{$footer}
</body>
</html>