<?php

namespace Stanford\RepeatingSurveyPortal;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */
/** @var \Stanford\RepeatingSurveyPortal\Participant $participant */
/** @var \Stanford\RepeatingSurveyPortal\PortalConfig  $portalConfig */

require_once 'Participant.php';
require_once 'ParticipantMultipleResponse.php';
require_once 'PortalConfig.php';



use REDCap;

/**
 * Class Portal
 * @package Stanford\RepeatingSurveyPortal
 */
class Portal
{
    public $participant_hash;
    public $participant_id;
    public $survey_statuses;

    public $valid_day_array;


    public $portalSubSettingID;

    public $portalConfigID;

    private $participantID;
    private $participant;  // participant object
    private $portalConfig; // EM configuration settings for this participant (subsetting)

    public function __construct($config_id, $hash) {
        global $module;

        $this->portalConfig = new PortalConfig($config_id);

        $sub = $this->portalConfig->getSubsettingID();
        //$module->emDebug("Using SUB:  ". $sub . ' for CONFIG_ID: '. $config_id);

        //$module->emDebug($valid_day_array, $this->validDayNumber, $config['valid-day-number']['value'][$sub],"VALID DAY"); exit;
        //setup the participant
        //TODO: if multiple response per day is allowed, then use different class, ParticipantMultipleResponse
        //MultipleResponse not yet implmented. Until then, just use the single Participant
        $this->participant = new Participant($this->portalConfig, $hash);
        /**
        if ($this->portalConfig->maxResponsePerDay != 1) {
            $this->participant = new ParticipantMultipleResponse($this->portalConfig, $hash);
        } else {
        $this->participant = new Participant($this->portalConfig, $hash);
        }
         */

        $this->participantID = $this->participant->getParticipantID();


    }

    public function setPortalConfigs() {
        global $module;

        //$filter = "[" . $this->event_name . "][" . $this->personalHashField . "] = '$hash'";

        // Use alternative passing of parameters as an associate array
        $params = array(
            'return_format' => 'array',
            'events'        => REDCap::getEventNames(true, false, $this->mainConfigEventName),
            'fields'        => array( REDCap::getRecordIdField(), $this->validDayNumber, $this->validDayLag)
        );

        $records = REDCap::getData($params);

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

    /**
     * UNUSED??
     *
     * @param $pk
     * @param $project_id
     * @param $start_field
     * @param $start_field_event
     * @param $valid_day_number_array
     * @return array
     */
    static function getValidDayNumbers($pk, $project_id, $start_field, $start_field_event, $valid_day_number_array) {
        $start_date = StaticUtils::getFieldValue($pk, $project_id, $start_field, $start_field_event);
        $window_dates = array();

        foreach ($valid_day_number_array as $day) {
            $date = self::getDateFromDayNumber($start_date,$day);
            $window_dates[$date] = array(
                "START_DATE" => $start_date,
                "RECORD_NAME" => $pk . "-" . "D" . $day,
                "DAY_NUMBER" => $day
            );
        }
        return $window_dates;
    }



    /**
     * // METHOD:   x()
     * verify hash and personal url for record (called by save_record hook)
     *
     *

     * // METHOD:  xx ($hash)
     * retrieve record based on hash or record+config
     *

     * //METHOD:    xxx ($record, $config)
     * calculate day number by retrieving start date and calculating against current date
     *

     * //METHOD:   xxxx($record, $config, $daynumber)
     * validate window (time) for current time
     *
     */

    /*******************************************************************************************************************/
    /* GETTER METHODS                                                                                                    */
    /***************************************************************************************************************** */


    public function getParticipant() {
        return $this->participant;
    }

    public function getPortalConfig() {
        return $this->portalConfig;
    }

}