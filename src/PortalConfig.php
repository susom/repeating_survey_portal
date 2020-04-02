<?php

namespace Stanford\RepeatingSurveyPortal;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */


use REDCap;

class PortalConfig {

    public $configID;

    public $enablePortal;

    /**Participant Level fields **/
    public $mainConfigEventName;
    public $mainConfigEventID;
    public $mainConfigFormName;
    public $participantDisabled; // calculated field
    public $startDateField;
    public $personalUrlField;
    public $personalHashField;
    public $participantConfigIDField;
    public $emailField;
    public $disableParticipantEmailField;
    public $phoneField;
    public $disableParticipantSMSField;

    /**Survey Level fields **/
    public $surveyEventName;
    public $surveyEventID;
    public $surveyInstrument;
    public $surveyConfigField;
    public $surveyDayNumberField;
    public $surveyDateField;
    public $surveyLaunchTSField;
    public $validDayNumber;
    public $maxResponsePerDay;
    public $validDayLag;
    public $earliestTimeAllowed;

    public $landingPageHeader;
    public $showCalendar;
    public $showMissingDayButtons;
    public $autoStartSurvey;
    public $surveyCompleteRedirect;
    public $surveyInviteEmail;
    public $surveyUrlLabel;
    public $surveyInviteSubject;
    public $surveyInviteFrom;

    public $enableInvitations;
    public $invitationDays;
    public $invitationTime;
    public $invitationEmailText;
    public $invitationUrlLabel;
    public $invitationEmailSubject;
    public $invitationEmailFrom;
    public $invitationSmsText;

    public $enableReminders;
    public $reminderDays;
    public $reminderTime;
    public $reminderLag;
    public $reminderEmailText;
    public $reminderUrlLabel;
    public $reminderEmailSubject;
    public $reminderEmailFrom;
    public $reminderSMSText;

    private $map = array(
        'participant-config-id-field'     => 'participantConfigIDField'
    );

    /**
     * key value (left) is the key name in the config.json
     * value (right) is the variable name in this object
     *
     * @var array
     */
    private $sub_map = array(
        'config-id'                       => 'configID',
        'enable-portal'                   => 'enablePortal',
        'main-config-event-name'          => 'mainConfigEventID',
        'main-config-form-name'           => 'mainConfigFormName',
        'participant-disabled'            => 'participantDisabled',
        'start-date-field'                => 'startDateField',
        'personal-hash-field'             => 'personalHashField',
        'personal-url-field'              => 'personalUrlField',
        'email-field'                     => 'emailField',
        'disable-participant-email-field' => 'disableParticipantEmailField',
        'phone-field'                     => 'phoneField',
        'disable-participant-sms-field'   => 'disableParticipantSMSField',
        'survey-event-name'               => 'surveyEventID',
        'survey-instrument'               => 'surveyInstrument',
        'survey-config-field'             => 'surveyConfigField',
        'survey-day-number-field'         => 'surveyDayNumberField',
        'survey-date-field'               => 'surveyDateField',
        'survey-launch-ts-field'          => 'surveyLaunchTSField',
        'valid-day-number'                => 'validDayNumber',
//        'max-response-per-day'            => 'maxResponsePerDay',    // multiple responses per day not yet implemented
        'valid-day-lag'                   => 'validDayLag',
        'earliest-time-allowed'           => 'earliestTimeAllowed',
        'landing-page-header'             => 'landingPageHeader',
        'show-calendar'                   => 'showCalendar',
        'show-missing-day-buttons '       => 'showMissingDayButtons',
        'auto-start-survey'               => 'autoStartSurvey',
        'survey-complete-redirect'        => 'surveyCompleteRedirect',
        'portal-invite-email'             => 'surveyInviteEmail',
        'portal-url-label'                => 'surveyUrlLabel',
        'portal-invite-subject'           => 'surveyInviteSubject',
        'portal-invite-from'              => 'surveyInviteFrom',
        'enable-invitations'              => 'enableInvitations',
        'invitation-days'                 => 'invitationDays',
        'invitation-time'                 => 'invitationTime',
        'invitation-email-text'           => 'invitationEmailText',
        'invitation-url-label'            => 'invitationUrlLabel',
        'invitation-email-subject'        => 'invitationEmailSubject',
        'invitation-email-from'           => 'invitationEmailFrom',
        'invitation-sms-text'             => 'invitationSmsText',
        'enable-reminders'                => 'enableReminders',
        'reminder-time'                   => 'reminderTime',
        'reminder-lag'                    => 'reminderLag',
        'reminder-days'                   => 'reminderDays',
        'reminder-email-text'             => 'reminderEmailText',
        'reminder-url-label'              => 'reminderUrlLabel',
        'reminder-email-subject'          => 'reminderEmailSubject',
        'reminder-email-from'             => 'reminderEmailFrom',
        'reminder-sms-text'               => 'reminderSMSText'
    );


    //derived fields

    public $subSettingID;
    public $validDayArray;
    public $inviteValidDayArray;
    public $reminderValidDayArray;
    public $config;

    public function __construct($configID) {
        global $module;

        $sub = $module->getSubIDFromConfigID($configID);
        $this->subSettingID = $sub;

        $config = $module->getProjectSettings();
        //$config_subsettings = $module->getSubSettings('survey-portals'); //TODO: there is a getSubSettings method in EM

        //$module->emDebug("Using SUB: ". $sub . ' for CONFIG_ID: '. $configID, $config, $config_subsettings); exit;

        //setup the  parameters from the config
        foreach ($this->map as $k => $v) {
            $this->{$v} =  $config[$k]['value'];
        }

        //setup the subsetting parameters from the config
        foreach ($this->sub_map as $k => $v) {
            $this->{$v} =  $config[$k]['value'][$sub];
        }

        //Multiple response not implemented. Until then, just hardcode as 1.
        $this->maxResponsePerDay = 1;

        //setup the valid day arrays
        $this->validDayArray = PortalConfig::parseRangeString($this->validDayNumber);
        $this->inviteValidDayArray = PortalConfig::parseRangeString($this->invitationDays);
        $this->reminderValidDayArray = PortalConfig::parseRangeString($this->reminderDays);

        //$module->emDebug( $this->validDayArray);

        //set event_name to the participant and survey event from id
        $this->mainConfigEventName = REDCap::getEventNames(true, false, $this->mainConfigEventID);
        $this->surveyEventName = REDCap::getEventNames(true, false, $this->surveyEventID);

    }


    /**
     * @param $input    A string like 1,2,3-55,44,67
     * @return mixed    An array with each number enumerated out [1,2,3,4,5,...]
     */
    static function parseRangeString($input) {
        $input = preg_replace('/\s+/', '', $input);
        $string = preg_replace_callback('/(\d+)-(\d+)/', function ($m) {
            return implode(',', range($m[1], $m[2]));
        }, $input);
        $array = explode(",",$string);
        return empty($array) ? false : $array;
    }



    /*******************************************************************************************************************/
    /* GETTER METHODS                                                                                                    */
    /***************************************************************************************************************** */

    public function getConfigID() {
        return $this->configID;
    }

    public function getSubsettingID() {
        return $this->subSettingID;
    }

    public function getValidDayArray() {
        return $this->validDayArray;
    }

    public function getEnablePortal() {
        return $this->enablePortal;
    }


}