<?php
/**
 * Created by PhpStorm.
 * User: jael
 * Date: 2019-02-26
 * Time: 12:11
 */

namespace Stanford\RepeatingSurveyPortal;

use REDCap;
use DateTime;
use Exception;
use Message;
use Piping;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */
/** @var \Stanford\RepeatingSurveyPortal\Portal $Portal */
/** @var  Stanford\RepeatingSurveyPortal\PortalConfig $portalConfig */

/**
 * Class called by InvitationCron job. Evaluates date and participants and then sends day invitations by email/text
 * Class InvitationManager
 * @package Stanford\RepeatingSurveyPortal
 */
class InvitationManager {

    public $portalConfig;

    public $configID;

    public $project_id;

    public function __construct($project_id, $sub) {
        global $module;

        $this->project_id = $project_id;

        //get the config id from the passed in hash
        $this->configID = $module->getConfigIDFromSubID($sub);

        if ($this->configID != null) {

            $this->portalConfig = new PortalConfig($this->configID);
        } else {
            $module->emError("Cron job to send invitations attempted for a non-existent configId: ". $this->configID .
                " in this subsetting :  ". $sub);
        }

    }

    /**
     * @param $sub
     * @throws Exception
     */
    public function sendInvitations($sub) {
        global $module;

        //sanity check that the subsetting matches the stored portalConfig;
        if ($sub != $this->portalConfig->subSettingID) {
            $module->emError("Wrong subsetting received while sending Invitations from cron");
        }

        $candidates = $this->getInviteReminderCandidates();

        if (empty($candidates)) {
            $module->emLog("No candidates to send invitations for project: ". $this->project_id . " today: ". date('Y-m-d'));
            return;
        }

        foreach ($candidates as $candidate) {

            //check if today is a valid day for invitation:
            $valid_day = $this->checkIfDateValid($candidate[$this->portalConfig->startDateField], $this->portalConfig->inviteValidDayArray);
            //$module->emDebug("ID: " .$candidate[REDCap::getRecordIdField()] .  " / VALID DAY NUMBER: ".$valid_day);
            //,($valid_day == null), ($valid_day == ''), isset($valid_day) ); exit;
            //$module->emDebug($this->portalConfig->inviteValidDayArray, "IN ARRAY");

            //Need repeat_instance for piping
            $repeat_instance = $candidate['redcap_repeat_instance'];

            //$module->emDebug($valid_day, isset($valid_day)); exit;

            //NULL is returned if the date is not valid
            //0 is evaluating to null?
            //if ($valid_day != null)  {
            if (isset($valid_day)) {
                //check that the valid_day is in the original valid_day_array
                if (!in_array($valid_day, $this->portalConfig->validDayArray)) {
                    $module->emError("Attempting to send invitation on a day not set for Valid Day Number. Day: $valid_day / Valid Day Numbers : ".
                                     $this->portalConfig->validDayNumber);
                    continue;
                }

                //check if valid (multiple allowed, window )

                //set up the new record and prefill it with survey metadata
                //create participant object. we need it to know the next instance.
                try {
                    $participant = new Participant($this->portalConfig, $candidate[$this->portalConfig->personalHashField]);
                    $module->emDebug("Checking invitations for ". $participant->getParticipantID());
                } catch (Exception $e) {
                    $module->emError($e);
                    continue;
                }

                //check that the portal is not disabled
                if ( $participant->getParticipantPortalDisabled()) {
                    $module->emDebug("Participant portal disabled for ". $participant->getParticipantID());
                    continue;
                }

                //check that the survey already not completed for today
                if ( $participant->isSurveyComplete(new DateTime())) {
                    $module->emDebug("Participant # ".$participant->getParticipantID().": Survey for day number $valid_day is already complete. Don't send invite for today");
                    continue;
                }

                //create a new ID and prefill the new survey entry with the metadata
                $next_id = $participant->getPartialResponseInstanceID($valid_day, new DateTime());
                $participant->newSurveyEntry($valid_day, new DateTime(), $next_id);


                //create url. Nope ue the &d= version of portal (so it will check daynumber)
                //$survey_link = REDCap::getSurveyLink($participant->participant_id, $participant->surveyInstrument,
                //$participant->surveyEventName, $next_id);

                $portal_url   = $module->getUrl("src/landing.php", true,true);
                $survey_link = $candidate[$this->portalConfig->personalUrlField]."&d=" . $valid_day;
                //$module->emDebug($survey_link, $candidate[$this->portalConfig->disableParticipantEmailField."___1"],$candidate[$this->portalConfig->emailField]);

                //send invite to email OR SMS

                if (($candidate[$this->portalConfig->disableParticipantEmailField."___1"] <> '1') &&
                    ($candidate[$this->portalConfig->emailField] <> '')) {


                    $module->emDebug("Sending email invite to participant record id: ".$candidate[REDCap::getRecordIdField()]);

                    $msg = $this->formatEmailMessage(
                        $this->portalConfig->invitationEmailText,
                        $survey_link,
                        $this->portalConfig->invitationUrlLabel);

                    //send email

                    $send_status = $this->sendEmail(
                        $candidate[REDCap::getRecordIdField()],
                        $candidate[$this->portalConfig->emailField],
                        $this->portalConfig->invitationEmailFrom,
                        $this->portalConfig->invitationEmailSubject,
                        $msg,
                        $this->portalConfig->surveyEventID,
                        $repeat_instance);


                    //TODO: log send status to REDCap Logging?
                    if ($send_status === false) {
                        $send_status_msg = "Error sending email to ";
                    } else {
                        $send_status_msg = "Invite email sent to ";
                    }
                    REDCap::logEvent(
                        "Email Invitation Sent from Survey Portal EM",  //action
                        $send_status_msg . $candidate[$this->portalConfig->emailField] . " for day_number " . $valid_day . " with status " .$send_status,  //changes
                        NULL, //sql optional
                        $participant->getParticipantID(), //record optional
                        $this->portalConfig->surveyEventName, //event optional
                        $this->project_id //project ID optional
                    );

                }

                if (($candidate[$this->portalConfig->disableParticipantSMSField."___1"] <> '1') &&
                        ($candidate[$this->portalConfig->phoneField] <> '')) {
                    $module->emDebug("Sending text invite to record id: ".$candidate[REDCap::getRecordIdField()]);
                    //TODO: implement text sending of URL
                    $msg = $this->formatTextMessage($this->portalConfig->invitationSmsText,
                                                    $survey_link,
                                                    $candidate[REDCap::getRecordIdField()],
                                                    $this->portalConfig->surveyEventID,
                                                    $repeat_instance
                    );

                    //$sms_status = $this->sms_messager->sendText($candidate[$phone_field], $msg);
                    //$twilio_status = $text_manager->sendSms($candidate[$phone_field], $msg);
                    $twilio_status = $module->emText($candidate[$this->portalConfig->phoneField], $msg);

                    if ($twilio_status !== true) {
                        $module->emError("TWILIO Failed to send to ". $candidate[$this->portalConfig->phoneField] . " with status ". $twilio_status);
                        REDCap::logEvent(
                            "Text Invitation Failed to send from Survey Portal EM",  //action
                            "Text failed to send to " . $candidate[$this->portalConfig->phoneField] . " with status " .  $twilio_status . " for day_number " . $valid_day ,  //changes
                            NULL, //sql optional
                            $participant->getParticipantID(), //record optional
                            $this->portalConfig->surveyEventName, //event optional
                            $this->project_id //project ID optional
                        );
                    } else {
                        $module->emDebug($twilio_status);
                        REDCap::logEvent(
                            "Text Invitation Sent from Survey Portal EM",  //action
                            "Invite text sent to " . $candidate[$this->portalConfig->phoneField],  //changes
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

    /**
     * Do a REDCap filter search on the project where
     *    1. config-id field matches the config-id in the subsetting for this config
     *    2. emails has not been disabled for this participant and the email field is not empty
     *    3. phone has not been disabled for this participant and the phone field is not empty
     *
     * @return bool|mixed
     */
    public function getInviteReminderCandidates() {
        global $module;

        if ($this->portalConfig->configID  == null) {
            $module->emError("config ID is not set!");
            return false;
        }

        //1. Obtain all records where this 'config-id' matches the in the patient record
        //Also filter that either email or sms  is populated.
        $filter = "(".
            "([".$this->portalConfig->participantConfigIDField ."] = '{$this->portalConfig->configID}') AND ".
            "(".
            "(([".$this->portalConfig->disableParticipantEmailField."(1)] <> 1) and  ([".$this->portalConfig->emailField."] <> ''))".
            " OR ".
            "(([".$this->portalConfig->disableParticipantSMSField."(1)] <> 1) and  ([".$this->portalConfig->phoneField."] <> ''))"
            .")"
            .")";

        $module->emDebug($filter);
        $params = array(
            'return_format' => 'json',
            'fields' => array(
                REDCap::getRecordIdField(),
                $this->portalConfig->emailField,
                $this->portalConfig->phoneField,
                $this->portalConfig->personalUrlField,
                $this->portalConfig->startDateField,
                $this->portalConfig->emailField,
                $this->portalConfig->disableParticipantEmailField,
                $this->portalConfig->phoneField,
                $this->portalConfig->disableParticipantSMSField,
                $this->portalConfig->personalHashField
            ),
            'events' => $this->portalConfig->mainConfigEventName,
            'filterLogic'  => $filter
        );

        //$module->emDebug($params, "PARAMS");
        $q = REDCap::getData($params);
        $result = json_decode($q, true);

        //there is a bug since 9.1 where the filter returns an empty array for every found array.
        //iterate over the returned result and delete the ones where redcap_repeat_instance is blank
        $not_empty = array();
        foreach ($result as $k => $v) {
            if (!empty($v['redcap_repeat_instance'])) {
                $not_empty[] = $v;
            }
        }

       //$module->emDebug($result, $not_empty, "Count of invitations to be sent:  ".count($result). " not empty". count($not_empty));
       //exit;

        //return $result;
        return $not_empty;

    }


    /**
     * Given start date and valid_day_number array, check if date is a valid survey date
     *
     * @param $start
     * @param $valid_day_number
     * @param $date_str
     * @return int|null  : day number for date passed in, NULL if not valid date.
     */
    public function checkIfDateValid($start_str, $valid_day_number, $date_str = null) {
        global $module;
        //$module->emDebug("Incoming to check If this date valid:". $date_str . ' with this start date: '. $start_str);
        //$module->emDebug("valid day array: ".implode(',',$valid_day_number));

        //use today
        $date = new DateTime($date_str);
        $start = new DateTime($start_str);

        $interval = $start->diff($date);

        $diff_date = $interval->format("%r%a");
        $diff_hours = $interval->format("%r%h");
        //$module->emDebug("DATE is {$date->format('Y-m-d H:i')} and start is {$start->format('Y-m-d H:i')} DIFF in DAYS: $diff_date /  DIFF in hours: ".  $diff_hours);

        //$module->emDebug("INTERVAL: ".$diff_date, $diff_hours);
        //$module->emDebug($interval->days, $interval->invert,$diff_date, $interval->days * ( $interval->invert ? -1 : 1));

        // need at add one day since start is day 0??
        //Need to check that the diff in hours is greater than 0 as date diff is calculating against midnight today
        //and partial days > 12 hours was being considered as 1 day.
        if ( ($diff_hours >= 0) && (in_array($diff_date, $valid_day_number))) {
            //actually, don't add 1. start date should be 0.
            //return ($interval->days + 1);
            return ($diff_date);
        }
        return null;

    }

    /**
     * Replace the url tag [invitation-url] with the $survey-link passed in as parameter
     * If url tag not embedded in $msg, add link to bottom of the email
     *
     * TODO: get wording for the link at the bottom of the email.
     *
     * @param $msg
     * @param $survey_link
     * @return mixed|string
     */
    function formatEmailMessage($msg, $survey_link, $survey_link_label) {
        $target_str = "[invitation-url]";

        if (empty($survey_link_label)) {
            $survey_link_label = $survey_link;
        }

        $tagged_link = "<a href='{$survey_link}'>$survey_link_label</a>";
        //if there is the inviation-url tag included, switch it out for the actual url.  if not, then add it to the end.

        if (strpos($msg, $target_str) !== false) {
            $msg = str_replace($target_str, $tagged_link, $msg);
        } else {
            $msg = $msg . "<br>Use this link to take the survey: ".$tagged_link;
        }

        return $msg;
    }


    /**
     *
     *
     * @param $to
     * @param $from
     * @param $subject
     * @param $msg
     * @return bool
     */
    function sendEmail($record, $to, $from, $subject, $msg, $event_id, $repeat_instance) {
        global $module;

        $module->emDebug("RECORD:".$record. " / EVENTID: ".$event_id. " /REP INSTANCE: ".$repeat_instance);

        $piped_email_subject = Piping::replaceVariablesInLabel($subject, $record, $event_id, $repeat_instance,array(), false, null, false);
        $piped_email_msg = Piping::replaceVariablesInLabel($msg, $record, $event_id, $repeat_instance,array(), false, null, false);

        // Prepare message
        $email = new Message();
        $email->setTo($to);
        $email->setFrom($from);
        $email->setSubject($piped_email_subject);
        $email->setBody($piped_email_msg); //format message??

        $result = $email->send();
        //$module->emDebug($to, $from, $subject, $msg, $result);

    // Send Email
        if ($result == false) {
            $module->emLog('Error sending mail: ' . $email->getSendError() . ' with ' . json_encode($email));
            return false;
        }

        return true;
    }

    /**
     * Switches out [invitation-url] with friendly text if provided
     * Adds standard message if no text entered.
     *
     * @param $msg
     * @param $survey_link
     * @return mixed|string
     */
    function formatTextMessage($msg, $survey_link, $record, $event_id, $repeat_instance) {

        $target_str = "[invitation-url]";

        //if there is the invitation-url tag included, switch it out for the actual url.  if not, then add it to the end.
        if (strpos($msg, $target_str) !== false) {
            $msg = str_replace($target_str, $survey_link, $msg);
        } else {
            $msg = $msg . "  Use this link to take the survey:".$survey_link;
        }

        $piped_msg = Piping::replaceVariablesInLabel($msg, $record, $event_id, $repeat_instance,array(), false, null, false);

        return $piped_msg;
    }

}