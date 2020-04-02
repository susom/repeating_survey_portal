<?php

namespace Stanford\RepeatingSurveyPortal;

use REDCap;

require_once 'src/InsertInstrumentHelper.php';


class ConfigInstance {
    /** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */

    private $module;

    //event names
    private $main_config_event_name;
    private $survey_event_name;

    //needed to verify day numbers
    private $valid_day_number;

    private $required = array(
        'enable-portal'          => 'Enable Portal (Portal is currently DISABLED)',
        'config-id'              => 'Unique Config ID',
        'main-config-event-name' => 'Main Config Event Name',
        'survey-event-name'      => 'Survey Event Name',
        'survey-instrument'      => 'Repeating Survey Instrument',
        'valid-day-number'       => 'Valid Days for Response');
    private $iih;
    private $sub;
    private $instance;

    const PARTICIPANT_INFO_FORM   = "rsp_participant_info";
    const SURVEY_METADATA_FORM   = "rsp_survey_metadata";

    public function __construct($module, $instance, $sub) {
        $this->sub    = $sub + 1;  //only used for reporting purposes. The UI displays number from 1, but counts from 0
        $this->module = $module;
        $this->instance = $instance;

        $this->iih = new InsertInstrumentHelper($module);

        //$module->emDebug($instance);

        //These are the  fields
        $this->main_config_event_name = $instance['main-config-event-name'];
        $this->survey_event_name      = $instance['survey-event-name'];


        //optional fields for invitation and text reminders
        $this->valid_day_number       = $instance['valid-day-number'];
        $this->invitation_days = $instance['invitation-days'];
        $this->reminder_days = $instance['reminder-days'];


    }

    /**
     *
     * Validate that the configuration in the EM is valid for this SubSeetting
     *
     * @return array
     */
    function validateConfig() {

        $alerts = null;

        // check that the required field are populated
        $missing = array();
        foreach ($this->required as $required_field => $label) {
            //$this->module->emDebug("checking $required_field with label:  $label");
            if (empty($this->instance[$required_field])) {
                $missing[] = $label;
            }
        }

        if (!empty($missing)) {
            $str = "<b>Configuration $this->sub: These fields need an entry: </b><br>";
            $str .= implode("<br>\n", $missing);
            $alerts[] = $str;
        }

        //check main_config_event_name
        if ($this->iih->formExists(self::PARTICIPANT_INFO_FORM)) {
            if (isset($this->main_config_event_name)) {
                if (!$this->iih->formDesignatedInEvent(self::PARTICIPANT_INFO_FORM, $this->main_config_event_name)) {
                    $event_name = REDCap::getEventNames(false, true, $this->main_config_event_name);
                    $pe = "<b>Configuration $this->sub: Participant Info form has not been designated to the event selected <br>for the main event: " . $event_name .
                        " </b><div class='btn btn-xs btn-primary float-right' data-action='designate_event' data-event='" . $this->main_config_event_name .
                        "' data-form='" . self::PARTICIPANT_INFO_FORM . "'>Designate Form</div>";
                    $alerts[] = $pe;
                }

                //check if form is repeating
                if (!$this->iih->isFormRepeating(self::PARTICIPANT_INFO_FORM, $this->main_config_event_name)) {
                    $this->module->emDebug($this->main_config_event_name);
                    $event_name = REDCap::getEventNames(false, true, $this->main_config_event_name);
                    $pe = "<b>Configuration $this->sub: Participant Info form has not been designated as a repeating form <br>in the main event: " . $event_name .
                        " </b><div class='btn btn-xs btn-primary float-right' data-action='set_form_repeating' data-event='" . $this->main_config_event_name .
                        "' data-form='" . self::PARTICIPANT_INFO_FORM . "'>Make Form Repeating</div>";
                    $alerts[] = $pe;

                }
            }
        }

        //     * 4. Form, rsp_survey_metadata, exists
        //     * 5. rsp_survey_metadata designated in survey event
        //     * 6. Survey event is repeating event
        if ($this->iih->formExists(self::SURVEY_METADATA_FORM)) {

            if (isset($this->survey_event_name)) {

                if (!$this->iih->formDesignatedInEvent(self::SURVEY_METADATA_FORM, $this->survey_event_name)) {
                    $event_name = REDCap::getEventNames(false, true, $this->survey_event_name);
                    $se = "<b>Configuration $this->sub: Survey Metadata form has not been designated to the event selected <br>for the survey event: ".$event_name.
                        " </b><div class='btn btn-xs btn-primary float-right' data-action='designate_event' data-event='".$this->survey_event_name.
                        "' data-form='".self::SURVEY_METADATA_FORM."'>Designate Form</div>";
                    $alerts[] = $se;
                }

                //make sure that the survey event is repeating
                if (!$this->iih->isEventRepeating($this->survey_event_name)) {

                    $event_name = REDCap::getEventNames(false, true, $this->survey_event_name);

                    $pe = "<b>Configuration $this->sub: The survey event, {$event_name}, has not been designated as a <br>repeating event: " .
                        " </b><div class='btn btn-xs btn-primary float-right' data-action='set_event_repeating' data-event='" . $this->survey_event_name .
                        "' data-label='survey_repeat_label'>Make Event Repeating</div>";
                    $alerts[] = $pe;
                }
            }

        }



        //* 7. If exists, invitation-days are a subset of valid-day-number
        //* 8. If exists, reminder-days are a subset of valid-day-number


        //$this->emDebug($invitation_days_settings,$reminder_days_settings);
        $valid_day_array = RepeatingSurveyPortal::parseRangeString($this->valid_day_number);
        if (!empty($this->invitation_days)) {
            $invite_array = RepeatingSurveyPortal::parseRangeString($this->invitation_days);

            if (!empty(array_diff($invite_array, $valid_day_array))) {
                $se = "<b>Configuration $this->sub: Invitation days is not a subset of Valid Day of Responses. Please check the values for Invitation Days under Invitations Settings.: ".
                    $this->invitation_days . " vs " . $this->valid_day_number." </div>";
                $alerts[] = $se;
            }
        }

        $valid_day_array = RepeatingSurveyPortal::parseRangeString($this->valid_day_number);
        if (!empty($this->reminder_days)) {
            $reminder_array = RepeatingSurveyPortal::parseRangeString($this->reminder_days);
            //$this->emDebug("DIFF:",$reminder_array, $valid_day_array, array_diff($invite_array,$valid_day_array), empty(array_diff($invite_array,$valid_day_array)));
            if (!empty(array_diff($reminder_array, $valid_day_array))) {
                $se = "<b>Configuration $this->sub: Reminder days is not a subset of Valid Day of Responses. Please check the values for Reminder Days under Reminder Settings.: ".
                    $this->reminder_days . " vs " . $this->valid_day_number." </div>";
                $alerts[] = $se;
            }
        }

        //$this->module->emDebug($alerts);
        if (empty($alerts)) {
            return array(true, null);
        } else {
            return array(false, $alerts);
        }
    }
}