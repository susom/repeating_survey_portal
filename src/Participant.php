<?php



namespace Stanford\RepeatingSurveyPortal;



/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */
/** @var \Stanford\RepeatingSurveyPortal\Portal $Portal */
/** @var  Stanford\RepeatingSurveyPortal\PortalConfig $portalConfig */

use DateInterval;
use DateTime;
use Exception;
use REDCap;

class Participant {


    public $portalConfig;  //config for the subsetting
    public $module;

    //This participant's survey data
    public $participant_hash;
    public $start_date;       //starting date of
    public $survey_status;    //survey from start_date to endate with date / day_number/ valid/ completed
    public $event_name;
    public $valid_day_array;
    public $config_id;      // subsetting config ID
    public $max_instance;   //last instance number

    private $participantID;   //PK of this participant
    private $participant_portal_disabled; //is this portal enabled for this participant




    public function __construct($portalConfig, $hash) {
        global $module;
        $this->portalConfig = $portalConfig;

        $this->event_name = REDCap::getEventNames(true, false, $this->surveyEventName);


        //setup the participant surveys
        //given the hash, find the participant and set id and start date in object
        $this->participantID =  $this->locateParticipantFromHash($hash);
        //$module->emDebug($this->participantID, $hash);

        if ($this->participantID == null) {
            $module->emLog("Participant not found from this hash: ".$hash);
            throw new Exception("Participant not found. Please check that you are using the link from the most recent email or text. ");
        }

        //check that this participant's portal is not disabled
        $this->participant_portal_disabled = $this->checkPortalDisabled($hash);
        //$module->emDebug("PORTAL DISABLED? :  ". $this->participant_portal_disabled, $hash); exit;


        //get all Surveys for this particpant and determine status
        $this->survey_status = $this->getAllSurveyStatus(
            $this->participantID,
            min($this->portalConfig->validDayArray),
            max($this->portalConfig->validDayArray));

        //update the survey_status to reflect day lag validity. have to do it in separate steps
        if ( isset($this->portalConfig->validDayLag)) {
            $today = new DateTime();
            $day_lag = clone $today;
            $day_lag->sub(new DateInterval('P'.$this->portalConfig->validDayLag.'D'));

            foreach ($this->survey_status as $date => $status) {
                //if date within allowed dates
                if (($date >= $day_lag->format('Y-m-d')) && ($date <= $today->format('Y-m-d'))){

                    if ($status['valid'] == true) {
                        $this->survey_status[$date]['valid_day_lag'] = true;
                    }

                }
            }
        }

        //$module->emDebug(min($this->portalConfig->validDayArray), max($this->portalConfig->validDayArray),$this->survey_status, $this->getValidDates());
        //$window_dates = $module->getValidDayNumbers($participant, $project_id, $cfg['START_DATE_FIELD'], $cfg['START_DATE_EVENT'], $valid_day_number_array);


        // Get the status' for each date in the window array
        //$window_dates = $module->getSurveyStatusArray($participant, $window_dates, $cfg);

    }


    /**
     * Called by construcdtor to create survey_status
     * Construct a status array using startdate and final date
     * todo: double check that we should do it this way to collect data on surveys taken on 'invalid' days
     *
     *
     * date
     *    record_name
     *    day_number  : get all the dates from
     *    valid       : T F
     *    complete    : T / F
     *    date_taken  : might differ from assigned date because of window
     *
     *
     *
     * @param $id
     */
    public function getAllSurveyStatus($participantID, $min, $max) {
        global $module;
        $survey_status = array();

        $all_surveys = $this->getAllSurveys($participantID);
        //$this->max_instance = max(array_keys($all_surveys));
        $max_repeat_instance = max(array_column($all_surveys, 'redcap_repeat_instance'));
        $this->max_instance = $max_repeat_instance;

        //$module->emDebug($all_surveys, $this->max_instance, $max_repeat_instance, $min, $max); exit;
        //$module->emDebug($this->valid_day_array, $this->start_date);

        $start_date = DateTime::createFromFormat('Y-m-d', $this->start_date);
        $date = $start_date;

        $today = new DateTime();



        //offset the start date from the min
        //if start_date is 0, what date is $min?

        //$date->add(new DateInterval('P'.$min. 'D'));
        $date = $start_date->modify('+ '.$min.' days');
        //echo $date->format('Y-m-d H:i:s');

        //$module->emDebug($this->start_date, $start_date, $date);

        //if date is in the future then break out of loop
        if ($date > $today) {
            return $survey_status; //return empty array
        }

        //$module->emDebug($all_surveys, $min, $max); exit;
        for ($i = $min; $i <= $max; $i++) {

            //$module->emDebug("** date is ". $date->format('Y-m-d') . " DAY_NUMBER is ".$i);

            //search existing record for matching day number field
            $found_survey_key = array_search($i, array_column($all_surveys, $this->portalConfig->surveyDayNumberField));

            $date_str = $date->format('Y-m-d');
            $survey_status[$date_str]['day_number']  = $i;
            $survey_status[$date_str]['valid']        = in_array($i, $this->portalConfig->validDayArray);

            //if valid day lag is not set, set all to 'valid', otherwise set all to false
            if (isset($this->portalConfig->validDayLag)) {
                $survey_status[$date_str]['valid_day_lag'] = false;
            } else {
                $survey_status[$date_str]['valid_day_lag'] = $survey_status[$date_str]['valid'];


            }


            if (!($found_survey_key === false)) { //because one of the found keys is 0 which reads as false.
                $survey_status[$date_str]['completed'] = $all_surveys[$found_survey_key][$this->portalConfig->surveyInstrument . '_complete'];
                $survey_status[$date_str]['survey_date'] = $all_surveys[$found_survey_key][$this->portalConfig->surveyDateField];
                $survey_status[$date_str]['date_taken'] = $all_surveys[$found_survey_key][$this->portalConfig->surveyLaunchTSField];
            }
            $date = $start_date->modify('+ 1 days');

            //if date is less than tomorrow then break out of loop
            if ($date > $today) {
                break;
            }
        }

        //$module->emDebug($survey_status);  exit;

        return $survey_status;


    }

    /**
     * Given hash, return the record Id of the participant
     *
     * @param $hash   : hash of portal
     * @return string|null  : participant ID or null if not found
     */
    public function locateParticipantFromHash($hash) {
        global $module;

        //limit surveys to this
        $filter = "[" . $this->portalConfig->mainConfigEventName . "][" . $this->portalConfig->personalHashField . "] = '$hash'";

        // Use alternative passing of parameters as an associate array
        $params = array(
            'return_format' => 'json',
            'events'        =>  $this->portalConfig->mainConfigEventName,
            'fields'        => array( REDCap::getRecordIdField(), $this->portalConfig->personalHashField, $this->portalConfig->startDateField),
            'filterLogic'   => $filter
        );

        $q = REDCap::getData($params);
        $records = json_decode($q, true);

        //$module->emDebug($records);

        // return record_id or false
        //$main = current($records);  //can't assume that this gives the correct array. 0 seems to have blanks...
        $array_num = $module->findRepeatingInstance($records, $this->portalConfig->mainConfigFormName);
       //$module->emDebug("xFOUND ARRAY NUMBER: ".$array_num . " EMPTY?: ". (empty($array_num) === true) . " ISSET: ". isset($array_num));

        //if (empty($array_num)) {  //?? this returns true if array_num = 0???
        if (!isset($array_num)) {
            return null;
        }

        $this->participantID = $records[$array_num][REDCap::getRecordIdField()];
        $this->start_date    = $records[$array_num][$this->portalConfig->startDateField];

        //$module->emDebug($this->getParticipantID(),$records,$main, $this->portalConfig->startDateField,$this->start_date);
        return ($this->participantID);
    }

    /**
     * Check that the participant level disabled field has not been checked.
     *
     * @param $hash
     * @return bool
     */
    public function checkPortalDisabled($hash) {
        global $module;

        //repeating form, so limit to portal config with the right hash
        $filter = "[" . $this->portalConfig->mainConfigEventName . "][" . $this->portalConfig->personalHashField . "] = '$hash'";

        $params = array(
            'return_format' => 'json',
            'events'        => $this->portalConfig->mainConfigEventName,
            'records'       => $this->participantID,
            'fields'        => array(REDCap::getRecordIdField(),$this->portalConfig->participantDisabled,$this->portalConfig->personalHashField),
            'filterLogic'   => $filter
        );

        //$module->emDebug($params);
        $q = REDCap::getData($params);
        $records = json_decode($q, true);


        $array_num = $module->findRepeatingInstance($records, $this->portalConfig->mainConfigFormName);
        //$module->emDebug("FOUND ARRAY NUMBER: ".$array_num);


        //if (empty($array_num)) {  //?? this returns true if array_num = 0???
        if (!isset($array_num)) {
            $module->emError("could not locate this instance in the repeating instance array");
            return true;  //count it as disabled
        }

        //$module->emDebug($records,$main, "MAIN");
        return $records[$array_num][$this->portalConfig->participantDisabled];

    }

    /**
     * Given date, return the day number
     *
     * @param $date
     */
    public function getDayNumberFromDate($date) {
        global $module;

        $date_str = $date->format('Y-m-d');

        $day_number = $this->survey_status[$date_str]['day_number'];

        return $day_number;

    }

    /**
     * Given day_number, return the survey date
     *
     * @param $day_number
     * @return DateTime|null
     * @throws Exception
     */
    public function getSurveyDateFromDayNumber($day_number) {
        global $module;

        //$survey_date_3 = array_search($day_number, array_column( $this->survey_status, "[day_number]"));

        foreach ($this->survey_status as $survey_date => $val) {

            if ($val['day_number'] == $day_number) {

                return new DateTime($survey_date);
            }
        }
        return null;

    }

     /**
     * Returns all surveys for a given record id
     *
     * @param $id
     *
     * @return mixed
     */
    public function getAllSurveys($id) {
        global $module;

        //restrict the getAllSurveys to the config for this participant

        //$filter = "[" . $this->event_name . "][" . $this->surveyFKField . "] = '$id'";
        $filter = "[" . $this->portalConfig->surveyEventName . "][" .$this->portalConfig->surveyConfigField . "] = '{$this->portalConfig->configID}'";

        $get_array = array(
            REDCap::getRecordIdField(),
            $this->portalConfig->configID,
            $this->portalConfig->surveyDayNumberField,
            $this->portalConfig->surveyDateField,
            $this->portalConfig->surveyLaunchTSField,
            $this->portalConfig->surveyInstrument . '_complete');

        $params = array(
            'return_format' => 'json',
            'records'       => $id,
            'events'        => $this->portalConfig->surveyEventName,
            'fields'        => $get_array,
            'filterLogic'   => $filter
            //how about surveyTimestampField, surveyDateField
        );

        $q = REDCap::getData($params);

        $results = json_decode($q,true);

        //$module->emDebug($filter, $params,$results, "ALL SURVEY GET");

        return $results;

    }


    /**
     * Given survey_date, check that today's date is within the allowed day lag
     *
     * @param $survey_date
     * @return bool
     * @throws Exception
     */
    public function isDayLagValid($survey_date) {
        global $module;
        if (!isset($this->portalConfig->validDayLag)) {
            $module->emDebug("Day lag is not set");
            return true;
        } else {
            $today = new DateTime();

            $date_diff = $today->diff($survey_date)->days;
            if ($date_diff <= $this->portalConfig->validDayLag ) {
                //$module->emDebug("valid day lag".  $date_diff, $today, $survey_date);
                return true;
            }
        }
        $module->emDebug("FAILED in valid day lag. Date diff : ". $date_diff, $today, $survey_date);
        return false;
    }

    /**
     * @param $survey_date
     * @return bool
     * @throws Exception
     */
    public function isStartTimeValid($survey_date) {
        global $module;
        if (!isset($this->portalConfig->earliestTimeAllowed)) {
            return true;
        } else {
            //treat 24 a little differently. use 23:59 as 24 shifts to 00:00 the next day by php
            if ($this->portalConfig->earliestTimeAllowed == '24') {
                //set it to 23:59 as it ends up as next day
                $allowed_earliest = $survey_date->setTime(23 , 59);
            } else {
                $allowed_earliest = $survey_date->setTime($this->portalConfig->earliestTimeAllowed, 0);
            }

            $now = new DateTime();

            if ($now >= $allowed_earliest ) {
                $module->emDebug("valid time ".  $allowed_earliest->format('Y-m-d H:i:s'), $now->format('Y-m-d  H:i:s'));
                return true;
            }
        }
        $module->emDebug("FAILED with invalid start time. Date DIff : ". $now->format('Y-m-d H:i:s') . " vs " . $allowed_earliest->format('Y-m-d H:i:s'));
        return false;
    }




    /**
     * This version only allows one
     *
     * @param $day_number
     * @param $survey_date
     * @return bool
     */
    public function isMaxResponsePerDayValid($day_number, $survey_date) {
        global $module;
        $survey_date_str = $survey_date->format('Y-m-d');
        $survey_complete = $this->survey_status[$survey_date_str]['completed'];

        //$module->emDebug($this->survey_status[$survey_date_str], $survey_complete,  ($survey_complete) == 2, "SURVEY COMPLETED?");

        if (($survey_complete) == 2) {
            return false;
        }
        return true;

    }

    /**
     * Return the next instance id for this survey instrument
     *
     * @return int|mixed
     */
    public function getNextInstanceID() {
        global $module;
        $record = $this->participantID;
        $event = $this->portalConfig->surveyEventID;
        $instrument = $this->portalConfig->surveyInstrument;

        //$module->emDebug("MAX ID 2", $record, $event, $instrument);
        //getData for all surveys for this reocrd
         //get the survey for this day_number and survey_data
        //TODO: return_format of 'array' returns nothing if using repeatint events???
        //$get_data = array('redcap_repeat_instance');
        $params = array(
            'return_format'       => 'json',
            //'fields'              => $get_data, //we need to leave this open in order to get the instance id
            'records'             => $this->participantID,
            'events'              => $this->portalConfig->surveyEventID
        );
        $q = REDCap::getData($params);
        $results = json_decode($q, true);

        $max_id = max(array_column($results, 'redcap_repeat_instance'));

        return $max_id + 1;
    }

    /**
     * @param $day_number
     * @param $survey_date  //currently not using survey_date to retrieve ID, just day_number
     * @return int|mixed
     */
    public function getPartialResponseInstanceID($day_number, $survey_date) {
        global $module;
        $survey_date_str = $survey_date->format('Y-m-d');
        $survey_complete = $this->survey_status[$survey_date_str]['completed'];
        $filter  =  "[" . $this->portalConfig->surveyEventName . "][".$this->portalConfig->surveyDayNumberField."] = '$day_number'";  // day number is passed in number
        $filter .= " and [" . $this->portalConfig->surveyEventName . "][" .$this->portalConfig->surveyConfigField . "] = '{$this->portalConfig->configID}'"; // and config_id is config

        //can only get redcap_repeat_instance if all the fields are retrieved!!
        $get_fields = array(
            'redcap_repeat_instance',
            $this->portalConfig->surveyDayNumber,
            $this->portalConfig->surveyDateNumber,
            $this->portalConfig->surveyInstrument . '_complete'
        );

        //get the survey for this day_number and survey_data
        $params = array(
            'return_format'       => 'json',
            //'fields'              => $get_fields,
            'records'             => $this->participantID, //$this->portalConfig->participantID,
            'events'              => $this->portalConfig->surveyEventID,
            'filterLogic'         => $filter
        );

        //$module->emDebug($params);
        $q = REDCap::getData($params);
        $results = json_decode($q, true);

        //just in case there are more than one (shouldn't happen), get the key by the largest timestamp
        $latest_key = array_keys($results, max($results))[0];

        //if 0 or 1, return the redcap_repeat_instance, otherwise
        $survey_complete = $results[$latest_key][$this->portalConfig->surveyInstrument . '_complete'];
        $timestamp       = $results[$latest_key][$this->portalConfig->surveyLaunchTSField];

        //$module->emDebug("Latest Key". $latest_key,array_keys($results, max($results)), $timestamp); // $results, max($results), array_keys($results,


        //$module->emDebug($results, $q, $results[0]['rsp_survey_day_number'],count($results), $this->portalConfig->surveyEventID, $this->participantID);
        $module->emDebug($this->portalConfig->participantID . ' Participant ID: ' . $this->participantID.' : Looking for Day number: '.$day_number.
                         ' and found day number '.$results[0]['rsp_survey_day_number'] . ' in instance number: '. $results[0]['redcap_repeat_instance'].
                         ' in event '.  $this->portalConfig->surveyEventID);
        //$module->emDebug($this->portalConfig->surveyInstrument . '_complete',            $survey_complete, $survey_complete == '0', $survey_complete == '1'); exit;

        $max_repeat_instance = 0;  //reset to 0
        //if (($survey_complete == '0') || ($survey_complete == '1')) {
        if (isset($timestamp)) {
            $max_repeat_instance =  $results[$latest_key]['redcap_repeat_instance'];
            $module->emDebug("EXISTING Instance: $max_repeat_instance surveycomplete: $survey_complete "); //,$results[$latest_key],
        } else {
            //it's new, so just get the next instance id to create new one.
            //can't return this->max_instance because instance IDs are shared between parent and child.
            //so need to get max instance ID for this RECORD, instance ids are sequential by record.
            //$max_repeat_instance = $this->max_instance +1;

            $max_repeat_instance  = $this->getNextInstanceID();

            $module->emDebug("USING NEW Instance: $max_repeat_instance surveycomplete: $survey_complete "); //,$results[$latest_key],

        }

        return $max_repeat_instance;

    }

    /**
     *
     *
     * @param $day_number
     * @param $survey_date
     * @param $instance
     * @return bool
     */
    public function newSurveyEntry($day_number, $survey_date, $instance) {
        global $module;

        /**
        //check max-response-per-day / base case is 1
        if ($this->portalConfig->maxResponsePerDay == 1) {

            //see how many responses already exist for this day_number

            //TODO: refresh survey_status?? is this overkill?  should refresh happen at end of survey hook?
            $this->survey_status = $this->getAllSurveyStatus(
                $this->participantID,
                min($this->portalConfig->validDayArray),
                max($this->portalConfig->validDayArray));

            //get the status for the survey_date
            $this->survey_status[$survey_date->format('Y-m-d')]['completed'];
        }
         */

        $params = array(
            REDCap::getRecordIdField()                => $this->participantID,
            "redcap_event_name"                       => $this->portalConfig->surveyEventName,
            //"redcap_repeat_instrument"                => $this->portalConfig->surveyInstrument,  //no repeat_instrument for repeat event
            "redcap_repeat_instance"                  => $instance,
            $this->portalConfig->surveyConfigField    => $this->portalConfig->configID,
            $this->portalConfig->surveyDayNumberField => $day_number,
            $this->portalConfig->surveyDateField      => $survey_date->format('Y-m-d'),
            $this->portalConfig->surveyLaunchTSField  => date("Y-m-d H:i:s")
        );

        //$module->emDebug($params); exit;
        $result = REDCap::saveData('json', json_encode(array($params)));
        if ($result['errors']) {
            $module->emError($result['errors'], $params);
            return false;
        }

    }

    /**
     * @return mixed
     */
    public function getFirstDate() {
        global $module;

        $dates = array_keys($this->survey_status);

        //$module->emDebug("MIN", min($dates));

        //no mindate (empty survey list no valid dates)
        //utter hack to force calendar to display all grey: return tomorrow as first date
        if ( !min($dates)) {
            $datetime = new DateTime('tomorrow');
            return $datetime->format('Y-m-d');

        }

        return min($dates);
    }

    /**
     * @return mixed
     */
    public function getLastDate() {
        global $module;
        $dates = array_keys($this->survey_status);

        //$module->emDebug("MAX", max($dates));
        //no maxdate (empty survey list no valid dates)
        //utter hack to force calendar to display all grey: return yesterday as last date
        if ( !max($dates)) {
           // return  '2019-07-30';
            $datetime = new DateTime('yesterday');
            return $datetime->format('Y-m-d');
        }

        return max($dates);
    }

    /**
     * Return array of 'valid' survey dates (folding in valid_day_lag)
     * Current guess is that the desired format is
     *   [date]['STATUS'] = 1/2/0 - REDCap completion status?
     *
     * @return array
     */
    public function getValidDates() {
        global $module;
        //$module->emDebug("VALID DATES:",$this->survey_status);
        $valid_dates = array();
        foreach ($this->survey_status as $date => $status) {

            if ($status['valid_day_lag']) {
                $valid_dates[$date]['STATUS'] = $status['completed'] ? $status['completed'] : 0;
                $valid_dates[$date]['DAY_NUMBER'] = $status['day_number'];
            }
        }
        //$module->emDebug($valid_dates);
        return $valid_dates;

    }


    /**
     * Return array of 'invalid' survey dates
     *  - not in valid days list described in config
     *  - todo: also exclude time window considerations??
     *  - todo: also exclude if completed?  I think no; already handeled by completed
     * @return array
     */
    public function getInvalidDates() {
        global $module;
        //$module->emDebug($this->survey_status);
        $invalid_dates = array();
        foreach ($this->survey_status as $date => $status) {
            if (!$status['valid_day_lag']) {
                $invalid_dates[$date] = '1';
            }
        }
        //$module->emDebug($invalid_dates);
        return array_keys($invalid_dates);

    }

    /**
     * Given the date, check if the repeating survey has been completed for this participant
     *
     * @param $date : date to check
     * @return bool : true if completed, false if not completed
     */
    public function isSurveyComplete($date) {
        global $module;
        $date_str = $date->format('Y-m-d');

        $status = $this->survey_status[$date_str];

        if ($status['completed'] == '2') {
            return true;
        }
        return false;
    }


    public function getParticipantPortalDisabled() {
        return $this->participant_portal_disabled;
    }

    public function getParticipantID() {
        return $this->participantID;
    }
}