<?php
/**
 * Created by PhpStorm.
 * User: jael
 * Date: 2019-02-19
 * Time: 10:08
 */

namespace Stanford\RepeatingSurveyPortal;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */

use REDCap;

/**
 * Holder for Participant extension that handles multiple responses for the same date
 * Currently just acts like the parent class (only allows 1 max response)
 *
 * Class ParticipantMultipleResponse
 * @package Stanford\RepeatingSurveyPortal
 */
class ParticipantMultipleResponse extends Participant
{

    public function __construct($portalConfig, $hash) {

        global $module;

        $module->emLog("Attempt to use unsupported feature: multiple responses on single date. Treating as max is one");

        parent::__construct($portalConfig, $hash);

    }


    /**
     *
     * Overwrite parent class to handle multiple responses in a day
     *
     *
     * @param $participant_id
     * @param $min
     * @param $max
     * @return array|void
     */
    public function getAllSurveyStatus($participant_id, $min, $max) {
        global $module;
        $module->emDebug("Not implemented yet. just use parent method for now");
        return parent::getAllSurveyStatus($participant_id,$min,$max);
    }

    public function isMaxResponsePerDayValid($day_number, $survey_date) {
        global $module;
        $module->emDebug("Not implemented yet. just use parent method for now");
        $status = parent::isMaxResponsePerDayValid($day_number, $survey_date);
        $module->emDebug("STatus from parent",$status);
        return $status;
    }

}