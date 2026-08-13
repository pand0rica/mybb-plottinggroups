<html>
<head>
<title>{$mybb->settings['bbname']} - {$events_title}</title>
{$headerinclude}
</head>
<body>
{$header}
<table border="0" cellspacing="{$theme['borderwidth']}" cellpadding="{$theme['tablespace']}" class="tborder">
<tr>
<td class="thead" colspan="3"><strong>{$events_title}</strong></td>
</tr>
<tr>
<td class="tcat" width="22%"><span class="smalltext"><strong>{$lang->plottinggroups_event_when_col}</strong></span></td>
<td class="tcat" width="53%"><span class="smalltext"><strong>{$lang->plottinggroups_event_what_col}</strong></span></td>
<td class="tcat" width="25%"><span class="smalltext"><strong>{$lang->plottinggroups_event_who_col}</strong></span></td>
</tr>
{$event_rows}
</table>
<p><a href="plottinggroups.php">{$lang->plottinggroups_events_back}</a></p>
{$footer}
</body>
</html>
