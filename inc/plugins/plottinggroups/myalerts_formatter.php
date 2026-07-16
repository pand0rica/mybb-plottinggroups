<?php
/**
 * plottinggroups – MyAlerts formatter
 *
 * One formatter instance per alert type code. Every value taken from
 * ExtraDetails is escaped with htmlspecialchars_uni() before it is placed in
 * the alert message – ExtraDetails are stored raw (stored XSS otherwise).
 */

if (!defined('IN_MYBB')) {
    die('Direct initialization of this file is not allowed.');
}

class Plottinggroups_MyAlerts_Formatter extends MybbStuff_MyAlerts_Formatter_AbstractFormatter
{
    /** @var string alert type code this instance formats (plottinggroups_*) */
    private $plottinggroups_code = '';

    public function __construct($mybb, $lang, $alertTypeName = '')
    {
        parent::__construct($mybb, $lang, $alertTypeName);
        $this->plottinggroups_code = (string)$alertTypeName;
    }

    public function init()
    {
        if (!isset($this->lang->myalerts_setting_plottinggroups_new_group)) {
            $this->lang->load('plottinggroups_myalerts');
        }
    }

    /**
     * @param MybbStuff_MyAlerts_Entity_Alert $alert
     * @param array                           $outputAlert
     * @return string formatted alert message
     */
    public function formatAlert(MybbStuff_MyAlerts_Entity_Alert $alert, array $outputAlert)
    {
        $details = (array)$alert->getExtraDetails();

        $lang_key = 'myalerts_' . $this->plottinggroups_code;

        if (!isset($this->lang->{$lang_key}) || $this->lang->{$lang_key} === '') {
            $this->lang->load('plottinggroups_myalerts');
        }
        if (!isset($this->lang->{$lang_key}) || $this->lang->{$lang_key} === '') {
            return '[' . htmlspecialchars_uni($this->plottinggroups_code) . ']';
        }

        $message = (string)$this->lang->{$lang_key};

        // {from_user} is pre-formatted by MyAlerts (profile link), no escaping here.
        if (isset($outputAlert['from_user']) && $outputAlert['from_user'] !== '') {
            $message = str_replace('{from_user}', $outputAlert['from_user'], $message);
        } else {
            $message = str_replace('{from_user}', '', $message);
        }

        foreach (['group_name', 'link'] as $key) {
            $value = isset($details[$key]) ? (string)$details[$key] : '';
            $message = str_replace('{' . $key . '}', htmlspecialchars_uni($value), $message);
        }

        return $message;
    }

    /**
     * Click target of the alert row (bell dropdown / alerts page).
     *
     * @param MybbStuff_MyAlerts_Entity_Alert $alert
     * @return string
     */
    public function buildShowLink(MybbStuff_MyAlerts_Entity_Alert $alert)
    {
        $details = (array)$alert->getExtraDetails();
        $link = isset($details['link']) ? (string)$details['link'] : 'plottinggroups.php';

        return $this->mybb->settings['bburl'] . '/' . htmlspecialchars_uni($link);
    }
}
