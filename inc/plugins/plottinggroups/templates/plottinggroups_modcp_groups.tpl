<html>
<head>
<title>{$mybb->settings['bbname']} - {$lang->plottinggroups_modcp_title}</title>
{$headerinclude}
</head>
<body>
{$header}
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder">
<tr>
<td class="thead" colspan="5"><strong>{$lang->plottinggroups_modcp_title}</strong></td>
</tr>
<tr>
<td class="tcat" width="22%"><span class="smalltext"><strong>{$lang->plottinggroups_group_name}</strong></span></td>
<td class="tcat"><span class="smalltext"><strong>{$lang->plottinggroups_group_description}</strong></span></td>
<td class="tcat" width="10%" align="center"><span class="smalltext"><strong>{$lang->plottinggroups_modcp_col_members}</strong></span></td>
<td class="tcat" width="18%" align="center"><span class="smalltext"><strong>{$lang->plottinggroups_modcp_col_status}</strong></span></td>
<td class="tcat" width="22%" align="center">&nbsp;</td>
</tr>
{$group_rows}
</table>
{$footer}
</body>
</html>
