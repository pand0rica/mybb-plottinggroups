<html>
<head>
<title>{$mybb->settings['bbname']} - {$lang->plottinggroups_title}</title>
{$headerinclude}
</head>
<body>
{$header}
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder plottinggroups_info">
<tr>
<td class="thead"><strong>{$lang->plottinggroups_info_title}</strong></td>
</tr>
<tr>
<td class="trow1">{$lang->plottinggroups_info_text}</td>
</tr>
</table>
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder">
<tr>
<td class="thead" colspan="4"><strong>{$lang->plottinggroups_title}</strong></td>
</tr>
<tr>
<td class="tcat" width="25%"><span class="smalltext"><strong>{$lang->plottinggroups_group_name}</strong></span></td>
<td class="tcat" width="45%"><span class="smalltext"><strong>{$lang->plottinggroups_group_description}</strong></span></td>
<td class="tcat" width="12%" align="center"><span class="smalltext"><strong>{$lang->plottinggroups_members}</strong></span></td>
<td class="tcat" width="18%" align="center">&nbsp;</td>
</tr>
{$group_rows}
</table>
{$members_panel}
{$propose_form}
{$footer}
</body>
</html>
