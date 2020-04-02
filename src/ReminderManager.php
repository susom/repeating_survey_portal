<?php
/**
 * Created by PhpStorm.
 * User: jael
 * Date: 2019-02-27
 * Time: 09:32
 */

namespace Stanford\RepeatingSurveyPortal;


use REDCap;
use DateTime;
use DateInterval;
use Exception;
use Message;

require_once 'InvitationManager.php';

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */
/** @var \Stanford\RepeatingSurveyPortal\Portal $Portal */

/** @var  Stanford\RepeatingSurveyPortal\PortalConfig $portalConfig */

/**
 * Class called by ReminderCron job to evaluate date and participants and sends reminders by email/text
 *
 * Class ReminderManager
 * @package Stanford\RepeatingSurveyPortal
 */
class ReminderManager extends InvitationManager
{
    public $portalConfig;

    public $configID;

    public $project_id;

    public function __construct($project_id, $sub)
    {
        global $module;

        //TODO: just reuse parent constructor

        $this->project_id = $project_id;

        //get the config id from the passed in hash
        $this->configID = $module->getConfigIDFromSubID($sub);

        if ($this->configID != null) {

            $this->portalConfig = new PortalConfig($this->configID);
        } else {
            $module->emError("Cron job to send reminders attempted for a non-existent configId: " . $this->configID .
                " in this subsetting :  " . $sub);
        }

        //$module->emDebug("in construct with ". $project_id, $sub, $this->configID, $this->portalConfig); exit;
    }

    /**
     *
     * TODO: use the reminder valid day array
     * @param $sub
     */
    public function sendReminders($sub) {
        global $module;

        //sanity check that the subsetting matches the stored portalConfig;
        if ($sub != $this->portalConfig->subSettingID) {
            $module->emError("Wrong subsetting received while sending Reminders from cron");
        }

        $candidates = $this->getInviteReminderCandidates();

        if (empty($candidates)) {
            $module->emLog("No candidates to send reminders for project: " . $this->project_id . " today: " . date('Y-m-d'));
            return;
        }

        //check that reminderLag is set
        if (!isset($this->portalConfig->reminderLag)) {
            $module->emError('Attempting to send reminders, but reminderLag is not set in the config');
            return null;
        }

        //calculate the target day
        $lagged_day = new DateTime();
        $lagged_day->sub(new DateInterval('P' . $this->portalConfig->reminderLag . 'D'));
        $lagged_str = $lagged_day->format('Y-m-d');


        foreach ($candidates as $candidate) {

            //check that today is a valid reminder day
            $valid_day = $this->checkIfDateValid($candidate[$this->portalConfig->startDateField], $this->portalConfig->reminderValidDayArray, $lagged_str);
            $module->emDebug("ID: " .$candidate[REDCap::getRecordIdField()] .  " / VALID DAY NUMBER: ".$valid_day);

            //Need repeat_instance for piping
            $repeat_instance = $candidate['redcap_repeat_instance'];

            //$module->emDebug($valid_day, $valid_day == null, isset($valid_day)); exit;
            //$module->emDebug($candidate[$this->portalConfig->personalHashField], $this->portalConfig->personalHashField);

            if (isset($valid_day)) {
            //if ($valid_day != null) {
                //check that the valid_day is in the original valid_day_array
                if (!in_array($valid_day, $this->portalConfig->validDayArray)) {
                    $module->emError("Attempting to send reminder on a day not set as a Valid Day Number. Day: $valid_day / Valid Day Numbers : ".
                                     $this->portalConfig->validDayNumber);
                    continue;
                }

                //create a Participant object for the candidate and get the survey_status array
                try {
                    $participant = new Participant($this->portalConfig, $candidate[$this->portalConfig->personalHashField]);
                } catch (Exception $e) {
                    $module->emError($e);
                    continue;
                }

                //check that the portal is not disabled
                if ( $participant->getParticipantPortalDisabled()) {
                    $module->emDebug("Participant portal disabled for ". $participant->getParticipantID());
                    continue;
                }

                //check that the survey has not already been completed
                if ($participant->isSurveyComplete($lagged_day)) {
                    $module->emDebug("Participant # ".$participant->getParticipantID().": Survey for $valid_day is already complete. Don't send the reminder for today");
                    continue;
                }

                //send a reminder email
                $survey_link = $candidate[$this->portalConfig->personalUrlField] . "&d=" . $valid_day;
                //$module->emDebug($survey_link, $candidate[$this->portalConfig->disableParticipantEmailField . "___1"], $candidate[$this->portalConfig->emailField]);

                //send invite to email OR SMS

                if (($candidate[$this->portalConfig->disableParticipantEmailField . "___1"] <> '1') &&
                    ($candidate[$this->portalConfig->emailField] <> '')) {

                    $module->emDebug("Sending email reminder to " . $candidate[REDCap::getRecordIdField()]);
                    //$module->emDebug();

                    $msg = $this->formatEmailMessage(
                        $this->portalConfig->reminderEmailText,
                        $survey_link,
                        $this->portalConfig->reminderUrlLabel);

                    //send email

                    $send_status = $this->sendEmail(
                        $candidate[REDCap::getRecordIdField()],
                        $candidate[$this->portalConfig->emailField],
                        $this->portalConfig->reminderEmailFrom,
                        $this->portalConfig->reminderEmailSubject,
                        $msg,
                        $this->portalConfig->surveyEventID,
                        $repeat_instance);

                    REDCap::logEvent(
                        "Email Reminder Sent from Survey Portal EM",  //action
                        "Reminder email sent to " . $candidate[$this->portalConfig->emailField] . " for day_number " . $valid_day . " with status " .$send_status,  //changes
                        NULL, //sql optional
                        $participant->getParticipantID(), //record optional
                        $this->portalConfig->surveyEventName, //event optional
                        $this->project_id //project ID optional
                    );


                }

                if (($candidate[$this->portalConfig->disableParticipantSMSField . "___1"] <> '1') &&
                    ($candidate[$this->portalConfig->phoneField] <> '')) {
                    $module->emDebug("Sending text reminder to " . $candidate[REDCap::getRecordIdField()]);
                    //TODO: implement text sending of URL
                    $msg = $this->formatTextMessage($this->portalConfig->reminderSMSText,
                                                    $survey_link,
                                                    $candidate[REDCap::getRecordIdField()],
                                                    $this->portalConfig->surveyEventID,
                                                    $repeat_instance
                    );

                    //$sms_status = $this->sms_messager->sendText($candidate[$phone_field], $msg);
                    //$twilio_status = $text_manager->sendSms($candidate[$phone_field], $msg);
                    $twilio_status = $module->emText($candidate[$this->portalConfig->phoneField], $msg);

                    if ($twilio_status !== true) {
                        $module->emError("TWILIO Failed to send to " . $candidate[$this->portalConfig->phoneField] . " with status " . $twilio_status);
                        REDCap::logEvent(
                            "Text Reminder Failed to send from Survey Portal EM",  //action
                            "Text failed to send to " . $candidate[$this->portalConfig->phoneField] . " with status " .  $twilio_status . " for day_number " . $valid_day ,  //changes
                            NULL, //sql optional
                            $participant->getParticipantID(), //record optional
                            $this->portalConfig->surveyEventName, //event optional
                            $this->project_id //project ID optional
                        );
                    } else {
                        REDCap::logEvent(
                            "Text Reminder Sent from Survey Portal EM",  //action
                            "Reminder text sent to " . $candidate[$this->portalConfig->phoneField],  //changes
                            NULL, //sql optional
                            $participant->getParticipantID(), //record optional
                            $this->portalConfig->surveyEventName, //event optional
                            $this->project_id //project ID optional
                        );
                    }
                }
            }
        }
    }

}

