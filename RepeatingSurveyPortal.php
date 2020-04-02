<?php

namespace Stanford\RepeatingSurveyPortal;



use ExternalModules\ExternalModules;
use \REDCap;
use \DateTime;
use \Message;
use Exception;
use Piping;

require_once("src/ConfigInstance.php");

require_once 'emLoggerTrait.php';
require_once 'src/Participant.php';
require_once 'src/PortalConfig.php';
require_once 'src/InsertInstrumentHelper.php';

/**
 * Class RepeatingSurveyPortal
 * @package Stanford\RepeatingSurveyPortal
 *
 *
 * WEB
 *
 * Portal Landing Page
 *
 * src/landing.php   NOAUTH
 * src/forecast.php (tries to show what will happen based on certain dates)
 * src/cron.php     NOAUTH (landing page to instantiate cron)
 *  - load the project config, for each config, it will execute check to see if each record needs notification...
 *  -
 *
 *
 *
 */
class RepeatingSurveyPortal extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    const PARTICIPANT_INFO_FORM   = "rsp_participant_info";
    const SURVEY_METADATA_FORM   = "rsp_survey_metadata";

    public $iih;

    /*******************************************************************************************************************/
    /* HOOK METHODS                                                                                                    */
    /***************************************************************************************************************** */


    function redcap_data_entry_form_top($project_id, $record, $instrument, $event_id, $group_id, $repeat_instance)
    {
        //$this->emDebug($instrument, current($this->getProjectSetting('main-config-form-name')));
        if ($instrument == current($this->getProjectSetting('main-config-form-name'))) {
            $config_id_field_name = $this->getProjectSetting('participant-config-id-field');

            //if there is a value for this field, display it here
            $params = array(
                'return_format'    => 'json',
                'records'          => array($record),
                'events'           => $event_id,
                'repeat_instance'  => $repeat_instance,
                'fields'           => array(REDCap::getRecordIdField(), $config_id_field_name)
            );

            $q = REDCap::getData($params);

            $records = json_decode($q, true);

            $key = array_search($repeat_instance, array_column($records, 'redcap_repeat_instance'));

            if (trim($key) !== '') {

                $selected = $records[$key][$config_id_field_name];
            }


            //if the are settings for the the config id, convert from a text field to a  dropdown
            //todo: hardcoding the field for 'rsp_prt_config_id'. do i need to handle cases where they veered off our forms?
            $config_fields = $this->getProjectSetting('config-id');
            $option_str = '<option value></option>';
            foreach ($config_fields as $option) {
                $option_str .= '<option value="'.$option.'">'.$option.'</option>';
            }

            ?>
            <script type="text/javascript">
                $(document).ready(function () {
                    var field_name = <?php echo  "'".$config_id_field_name."'"; ?>;
                    var options = <?php echo "'".$option_str."'";?>;
                    var selected = <?php echo "'".$selected."'";?>;


                    $('input[name="rsp_prt_config_id"]')
                        .replaceWith('<span><select role="listbox" aria-labelledby="label-rsp_prt_config_id" class="x-form-text x-form-field   " id="rsp_prt_config_id"  name="rsp_prt_config_id" tabindex="0">' + options + '</select></span>');
                    $('#rsp_prt_config_id').val(selected);
                });
            </script>
            <?php
        }
    }

    public function redcap_module_system_enable() {
        // SET THE
        // Do Nothing
        //create instrument participant info. upload zip for instrument
        REDCap::getInstrumentNames(); //to get instrument name

        //upload instrument zip

        //verify that default fields aren't already existing. if exists, then abort
        //if pi_ already exists, notify admin that the field already exists
         \ExternalModules\ExternalModules::sendAdminEmail('subject', 'message');

         //make sure its in dev mode
        //status > 0
        //$current_forms = ($status > 0) ? $Proj->forms_temp : $Proj->forms;

         //make sure form name doesn't already exist.

        //insert the form
    }


    public function redcap_module_link_check_display($project_id, $link) {
        // TODO: Loop through each portal config that is enabled and see if they are all valid.
        //TODO: ask andy123; i'm not sure what KEY_VALID_CONFIGURATION is for...
        //if ($this->getSystemSetting(self::KEY_VALID_CONFIGURATION) == 1) {
        list($result, $message)  = $this->getConfigStatus();
        if ($result === true) {
                    // Do nothing - no need to show the link
        } else {
            $link['icon'] = "exclamation";
        }
        return $link;
    }

    // SAVE CONFIG HOOK
    // if config-id is null, then generate a config id for that the configs...
    //todo: HOLD ON THIS. saving works, but delete ignores this setting we add. punt for now.
    /**
     * Save config hook:
     *    1. validate configuratio on save
     * @param $project_id
     */
    public function redcap_module_save_configuration($project_id) {

        list($result, $message)  = $this->getConfigStatus();

    }


    /**
     * Need to handle case where after the survey completes, it redirects back to the portal for the user.
     * There is a cooke stored at creation of the portal. Lookup key and reconstitute hash for the participant
     *
     *
     * @param $project_id
     * @param $record
     * @param $instrument
     * @param $event_id
     * @param $group_id
     * @param $survey_hash
     * @param $response_id
     * @param $repeat_instance
     */
    public function redcap_survey_complete($project_id, $record, $instrument, $event_id, $group_id,  $survey_hash,  $response_id,  $repeat_instance) {

        //retrieve the config (ex: parent or child config)  from the cookie that was stored when started from the landing page
        //TODO: Ask Andy: If there multiple protocols (ex: parent and child) on machine at same time, we cannot distinguish which survey this is related to
        $cookie_key = $this->PREFIX."_".$project_id."_".$record;  //this won't work if mother/child are on same machine at same time.
        $cookie_config = $_COOKIE[$cookie_key];

        $this->emDebug("COOKIE KEY ". $cookie_key);

        //if redirect has been turned on redirect to the landing page

        $sub = $this->getSubIDFromConfigID($cookie_config);
        $this->emDebug("COOKIE CONFIG <". $cookie_config . "> found SUB: ".$sub);

        $redirect = $this->getProjectSetting('survey-complete-redirect')[$sub];

        if (isset($redirect) && ($redirect == $instrument) ) {
            $this->emDebug("Redirecting to landing page after this survey completed: " . $redirect);

            if (empty($cookie_config) || $cookie_config == '') {
                $this->emError("Unable to redirect to landing page since unable to retrieve config info from cookie: " . $cookie_key);
                $this->exitAfterHook();  //this doesn't really exit!  it continues on to attempt redirect so will put redirect in else block
                //die("Unable to return to portal page. Please use the link from your email.");
            } else {

                $config_event_id = $this->getProjectSetting('main-config-event-name')[$sub];
                $config_event_name = REDCap::getEventNames(true, false, $config_event_id);
                $config_field = $this->getProjectSetting('participant-config-id-field');
                $hash_field = $this->getProjectSetting('personal-hash-field')[$sub];
                $hash = $this->retrieveParticipantFieldWithFilter($record, $config_event_name, $config_field, $cookie_config, $hash_field);

                //$this->emDebug("HASH:  ". $hash);

                $portal_url = $this->getUrl("src/landing.php", true, true);
                $return_hash_url = $portal_url . "&h=" . $hash . "&c=" . $cookie_config;

                $this->emDebug("this is new hash url: " . $return_hash_url);

                //now redirect back to the landing page
                header("Location: " . $return_hash_url);
            }
        }


    }

    // SAVE_RECORD HOOK
    // make portal objects and verify that current record has hash and personal url saved

    /**
     * @param $project_id
     * @param null $record
     * @param $instrument
     */
    public function redcap_save_record($project_id, $record,  $instrument,  $event_id,  $group_id = NULL,  $survey_hash = NULL,  $response_id = NULL, $repeat_instance) {
        //If instrument is the right one, create the portal url and save it to the designated field

        //iterate through all of the sub_settings
        $target_forms        = $this->getProjectSetting('main-config-form-name');

        foreach ($target_forms as $sub => $target_form) {


            if ($instrument == $target_form) {

                $config_field = $this->getProjectSetting('participant-config-id-field');
                $config_event = $this->getProjectSetting('main-config-event-name')[$sub];
                $start_date_field = $this->getProjectSetting('start-date-field')[$sub];

                //CHECK that the config event set for rsp_participant_event is the same as this current event
                if ($config_event != $event_id) {
                    $this->emError("Event id $event_id is not not what is designated in the config: $config_event.");
                    return;
                    //$this->exitAfterHook();
                }

                //check that the start date for the portal is set, if not set then return
                $start_date = $this->getFieldValue($record, $event_id, $start_date_field, $instrument, $repeat_instance);
                if ($start_date == null) {
                    $this->emError("Start Date for record $record is not set. Will not create portal url for this record.");
                    return;
                    //$this->exitAfterHook();
                }

                //get the config_id for this participant
                $config_id = $this->getFieldValue($record, $event_id, $config_field, $instrument, $repeat_instance);

                //CHECK that the config ID for this record is set, if not set then return
                if ($config_id == null) {
                    $this->emError("Config ID for record $record is not set.  Will not create portal url for this record.");
                    return;
                    //$this->exitAfterHook();
                }

                $sub = $this->getSubIDFromConfigID($config_id);
                //if sub is empty, then the participant is using a config_id that doesn't exist.
                if ($sub === false) {
                    $this->emError("This $config_id entered in participant $record is not found the EM config settings.");
                    return;
                    //$this->exitAfterHook();
                }


                /***********************************/

                $personal_hash_field = $this->getProjectSetting('personal-hash-field')[$sub];
                $personal_url_field = $this->getProjectSetting('personal-url-field')[$sub];
                $portal_invite_checkbox = $this->getProjectSetting('send-portal-invite')[$sub];

                /***********************************/

                // First check if hashed portal already has been created
                $f_value = $this->getFieldValue($record, $config_event, $personal_hash_field, $instrument, $repeat_instance);

                if ($f_value == null) {
                    //generate a new URL
                    $new_hash = $this->generateUniquePersonalHash($project_id, $personal_hash_field, $config_event);
                    $portal_url = $this->getUrl("src/landing.php", true, true);
                    $new_hash_url = $portal_url . "&h=" . $new_hash . "&c=" . $config_id;

                    // Save it to the record (both as hash and hash_url for piping)
                    $event_name = REDCap::getEventNames(true, false, $config_event);

                    $data = array(
                        REDCap::getRecordIdField() => $record,
                        'redcap_event_name' => $event_name,
                        'redcap_repeat_instrument' => $instrument,
                        'redcap_repeat_instance' => $repeat_instance,
                        $personal_url_field => $new_hash_url,
                        $personal_hash_field => $new_hash
                    );
                    $response = REDCap::saveData('json', json_encode(array($data)));
                    //$this->emDebug($sub, data, $response,  "Save Response for count"); exit;

                    if (!empty($response['errors'])) {
                        $msg = "Error creating record - ask administrator to review logs: " . json_encode($response);
                        $this->emError($msg, $response['errors']);
                    }


                    //checkbox to send portal invite has been checked so send invite
                    if ($portal_invite_checkbox) {
                        //$this->emDebug("PORTAL CHECKBOX: ". $portal_invite_checkbox,$this->getProjectSetting('send-portal-invite'));//exit;
                        $this->handlePortalInvite($sub, $record, $instrument, $repeat_instance,$new_hash_url);
                    }

                    //$this->emDebug($record . ": Set unique Hash Url to $new_hash_url with result " . json_encode($response));
                }
            }
        }
    }


    /*******************************************************************************************************************/
    /* CRON METHODS                                                                                                    */
    /***************************************************************************************************************** */

    /**
     * Current settings to run every hour
     *
     * TODO: Add cron to config.json
     *
     * 1) Determine projects that are using this EM
     * 2) Instantiate instance of EM for each project
     * 3)
     */
    public function inviteCron() {

        $this->emDebug("Starting invite cron for ".$this->PREFIX);
        //* 1) Determine projects that are using this EM
        //get all projects that are enabled for this module
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);

        //get the noAuth api endpoint for Cron job.
        $url = $this->getUrl('src/InviteCron.php', true, true);

        //while ($proj = db_fetch_assoc($enabled)) {
        while($proj = $enabled->fetch_assoc()){

            $pid = $proj['project_id'];
            $this->emDebug("STARTING INVITE CRON for pid ". $pid);

            //check scheduled hour of send
            $scheduled_hour = $this->getProjectSetting('invitation-time', $pid);
            $current_hour = date('H');

            //iterate through all the sub settings
            foreach ($scheduled_hour as $sub => $invite_time) {

                //TODO: check that the 'enable-invitations' is not set. test this
                $enabled_invite = $this->getProjectSetting('enable-invitations', $pid)[$sub];
                if ($enabled_invite == '1') {

                    //$this->emDebug("PROJECT $pid : SUB $sub scheduled at this hour $invite_time vs current hour: $current_hour");

                    //if not hour, continue
                    if ($invite_time != $current_hour) continue;

                    $this_url = $url . '&pid=' . $pid . "&s=" . $sub;
                    $this->emDebug("INVITE CRON URL IS " . $this_url);

                    $resp = http_get($this_url);
                    //$this->cronAttendanceReport($pid);
                    $this->emDebug("cron for invitations: " . $resp);
                }
            }
        }

    }


    /**
     * Cron method to initiate reminder emails/texts
     *
     * Cron job for project is only initiated for a project's subsetting in the config if
     * the checkbox to Enable Reminders have been checked.
     *
     * The Cron job is started by triggeringg the ReminderCron.php page PLUS the additional parameters:
     *    project id
     *    subsetting
     */
    public function reminderCron() {

        //* 1) Determine projects that are using this EM
        //get all projects that are enabled for this module
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);

        //get the noAuth api endpoint for Cron job.
        $url = $this->getUrl('src/ReminderCron.php', true, true);

        //while ($proj = db_fetch_assoc($enabled)) {
        while($proj = $enabled->fetch_assoc()){
            $pid = $proj['project_id'];
            $this->emDebug("STARTING REMINDER CRON for pid ". $pid);

            //check scheduled hour of send
            $scheduled_hour = $this->getProjectSetting('reminder-time', $pid);
            $current_hour = date('H');

            //iterate through all the sub settings
            foreach ($scheduled_hour as $sub => $reminder_time) {
                //TODO: check that the 'enable-reminders' is not set. test this
                $enabled_reminder = $this->getProjectSetting('enable-reminders', $pid)[$sub];
                if ($enabled_reminder == '1') {

                    //$this->emDebug("project $pid - $sub scheduled at this hour $reminder_time vs current hour: $current_hour");

                    //if not hour, continue
                    if ($reminder_time != $current_hour) continue;

                    $this_url = $url . '&pid=' . $pid . "&s=" . $sub;
                    $this->emDebug("REMINDER CRON URL IS " . $this_url);

                    $resp = http_get($this_url);
                    //$this->cronAttendanceReport($pid);
                    $this->emDebug("cron for reminder: " . $resp);
                }
            }
        }
    }


    /*******************************************************************************************************************/
    /*  METHODS                                                                                                    */
    /***************************************************************************************************************** */

    /**
     * @param $sub
     * @param $record
     * @param $instrument
     * @param $repeat_instance
     * @param $new_hash_url
     */
    function handlePortalInvite($sub, $record,$instrument, $repeat_instance, $new_hash_url) {

        //prep for the initial invite email
        $config_event = $this->getProjectSetting('main-config-event-name')[$sub];
        $email_to_field         = $this->getProjectSetting('email-field')[$sub];

        $portal_url_label       = $this->getProjectSetting('portal-url-label')[$sub];
        $initial_invite_msg     = $this->getProjectSetting('portal-invite-email')[$sub];
        $initial_invite_subject = $this->getProjectSetting('portal-invite-subject')[$sub];
        $email_from             = $this->getProjectSetting('portal-invite-from')[$sub];


        //the URL has been updated so send out an email
        //get the email field. if email is set, then send out invite
        $email_to = $this->getFieldValue($record, $config_event, $email_to_field, $instrument, $repeat_instance);
        if (!empty($email_to)) {

            //convert all to piped values
            //$this->emDebug("RECORD:".$record. " / SUB: ".$sub. " / EVENTID: ".$event_id. " /REP INSTANCE: ".$repeat_instance);
            $piped_email_subject = Piping::replaceVariablesInLabel($initial_invite_subject, $record, $config_event,$repeat_instance, array(), false, null, false);
            $piped_email_msg = Piping::replaceVariablesInLabel($initial_invite_msg, $record, $config_event,$repeat_instance, array(), false, null, false);
            //$this->emDebug($record. "piped subject: ". $piped_email_subject);
            //$this->emDebug($record. "piped msg: ". $piped_email_msg);

            $this->sendInitialPortalUrl($record, $new_hash_url, $portal_url_label, $piped_email_msg, $email_to, $email_from, $piped_email_subject);
        } else {

        //if both the text and email fields are empty, log so that admin know that record never got the initial invite
            $this->emLog("Portal invite was not sent for record $record because the email field is empty.");
            REDCap::logEvent(
                "Unable to send portal invite by Survey Portal EM", //action
                "Portal invite was not sent because the email field is empty.",
                NULL, //sql optional
                $record, //record optional
                null
                //$project_id //project ID optional
            );
        }

    }

    /**
     * Method to send out the initial portal invitation by email
     *
     * @param $record
     * @param $portal_url
     * @param $portal_url_label
     * @param $msg
     * @param $email_to
     * @param $from
     * @param $subject
     */
    public function sendInitialPortalUrl($record, $portal_url,$portal_url_label, $msg, $email_to, $from, $subject) {

        //replace $portal_url the tag [portal-url]
        $target_str = "[portal-url]";

        if (empty($portal_url_label)) {
            $portal_url_label = $portal_url;
        }

        $tagged_link = "<a href='{$portal_url}'>$portal_url_label</a>";

        //$this->emDebug($portal_url, $portal_url_label, $tagged_link);

        //if there is a portal-url tag included, switch it out for the actual url.  if not, then add it to the end.

        if (strpos($msg, $target_str) !== false) {
            $msg = str_replace($target_str, $tagged_link, $msg);
        } else {
            $msg = $msg . "<br>Use this link to take the survey: ".$tagged_link;
        }

        //$this->emDebug( $email_to, $from, $subject, $msg);

        if (!isset($from)) $from = 'no-reply@stanford.edu';

        //send email
        $email = new Message();
        $email->setTo($email_to);
        $email->setFrom($from);
        $email->setSubject($subject);
        $email->setBody($msg); //format message??

        $result = $email->send();
        if ($result == false) {
            $action_status = "Error sending invite form Survey Portal EM";
            $send_status = 'Error sending mail to '.$email_to .
                " with status: " . $email->getSendError() . ' with ' . json_encode($email);
        } else {
            $action_status = "Portal Link Sent from Survey Portal EM";
            $send_status = 'Email with portal url was sent to '. $email_to;
        }

        REDCap::logEvent(
            $action_status, //action
            $send_status,
            NULL, //sql optional
            $record, //record optional
            null
            //project_id //project ID optional
        );

    }

    /**
     * Method to send out initial portal invitation by text
     * Design change: no longer sending out portal url by text
     * Method unused - delete?
     *
     * @param $project_id
     * @param $record
     * @param $portal_url
     * @param $msg
     * @param $text_to
     */
    public function textInitialPortalUrl($project_id, $record, $portal_url, $msg, $text_to) {

        //replace $portal_url the tag [portal-url]
        $target_str = "[portal-url]";

        //no taggged link for texts
        //$tagged_link = "<a href='{$portal_url}'>$portal_url_label</a>";
        //if there is a portal-url tag included, switch it out for the actual url.  if not, then add it to the end.

        if (strpos($msg, $target_str) !== false) {
            $msg = str_replace($target_str, $portal_url, $msg);
        } else {
            $msg = $msg . "<br>Here is the link to your portal".$portal_url;
        }

        $twilio_status = $this->emText($text_to, $msg);

        if ($twilio_status !== true) {
            $this->emError("TWILIO Failed to send to ". $text_to. " with status ". $twilio_status);
            $action_status = "Initial Text Invite Failed to send from Survey Portal EM";
            $send_status   = "Text for portal invite failed to send to " .$text_to . " with status " .  $twilio_status;
        } else {
            $this->emDebug($twilio_status);
            $action_status =   "Text Portal Invitation Sent from Survey Portal EM";
            $send_status  =    "Portal Invitation texted to " .$text_to;
        }

        REDCap::logEvent(
            $action_status, //action
            $send_status,
            NULL, //sql optional
            $record, //record optional
            null,
            $project_id //project ID optional
        );
    }


    /**
     * This function takes the settings for each configuration and rearranges them into arrays of subsettings
     * instead of arrays of key/value pairs. This is called from javascript so each configuration
     * can be verified in real-time.
     *
     * @param $key - JSON key where the subsettings are stored
     * @param $settings - retrieved list of subsettings from the html modal
     * @return array - the array of subsettings for each configuration
     */
    public function parseSubsettingsFromSettings($key, $settings) {
        $config = $this->getSettingConfig($key);
        if ($config['type'] !== "sub_settings") return false;

        // Get the keys that are part of this subsetting
        $keys = [];
        foreach ($config['sub_settings'] as $subSetting) {
            $keys[] = $subSetting['key'];
        }

        // Loop through the keys to pull values from $settings
        $subSettings = [];
        foreach ($keys as $key) {
            $values = $settings[$key];
            foreach ($values as $i => $value) {
                $subSettings[$i][$key] = $value;
            }
        }
        return $subSettings;
    }







    /*******************************************************************************************************************/
    /* PORTAL CONFIGURATION METHODS                                                                                    */
    /*******************************************************************************************************************/


    /**
     * Check the EM configuration for validity
     * 1. Make sure form, rsp_participant_info, exist
     * 2. rsp_participant_info designated in main event
     * 3. rsp_participant_info is repeating form
     * 4. Form, rsp_survey_metadata, exists
     * 5. rsp_survey_metadata designated in survey event
     * 6. Survey event is repeating event
     * 7. If exists, invitation-days are a subset of valid-day-number
     * 8. If exists, reminder-days are a subset of valid-day-number
     *
     *
     * @return array
     */
    public function getConfigStatus($configs = null, $fix = true) {

        $iih = new InsertInstrumentHelper($this);

        $alerts = array();
        $result = false;


        //check that default forms exist in project
        //     * 1. Make sure form, rsp_participant_info, exist
        if (!$iih->formExists(self::PARTICIPANT_INFO_FORM)) {
            $p = "<b>Participant Info form has not yet been created. </b> 
              <div class='btn btn-xs btn-primary float-right' data-action='insert_form' data-form='" . self::PARTICIPANT_INFO_FORM ."'>Create Form</div>";
            $alerts[] = $p;
        }

        //make sure that metadata form exists
        if (!$iih->formExists(self::SURVEY_METADATA_FORM)) {
            $s=  "<b>Survey Info form has not yet been created. </b> 
              <div class='btn btn-xs btn-primary float-right' data-action='insert_form' data-form='" . self::SURVEY_METADATA_FORM . "'>Create Form</div>";
            $alerts[] = $s;
        }


        //This is the event that holds the main config form: rsp_participant_info
        //Check that rsp_participant_info is a repeating form
        //TODO: should this EM just create the  event and set it?

        //check that the forms exist
        if (empty($configs)) {
            //create config_instance
            $configs = $this->getSubSettings('survey-portals');
        }

        foreach ($configs as $i => $config) {
            $c_instance = new ConfigInstance($this, $config, $i);
            list($c_result, $c_alerts) = $c_instance->validateConfig();

            if ($c_result == false) {
                $alerts = array_merge($alerts, $c_alerts);
                $alerts2[] = $c_alerts;

            }

        }

        //$this->emDebug('!CONFIG STATUS', $alerts);

        if (empty($alerts) && !empty($configs)) {
            $result = true;
            $alerts[] = "Your configuration appears valid!";
        }

        return array( $result, $alerts );
    }


    public function insertForm($form) {
        $this->emDebug("!INSERT FORM: ". $form );
        $iih = new InsertInstrumentHelper($this);

        $result = $iih->insertForm($form);
        $message = $iih->getErrors();

//        $this->emDebug("INSERT FORM". $form);
//        switch ($form) {
//            case "pi" :
//                $status = $iih->insertParticipantInfoForm();
//                break;
//            case "md" :
//                $status = $iih->insertSurveyMetadataForm();
//                break;
//            default:
//                $status  = false;
//        }
//
//
//        $errors = $status ? null :$iih->getErrors();
//
//        //$status = $this->getConfigStatus();

        return array($result, $message);

    }


    public function designateEvent($form, $event) {
        $iih = new InsertInstrumentHelper($this);

        $this->emDebug("DESIGNATING EVENT: ". $form . $event);
        $result = $iih->designateFormInEvent($form, $event);

        if ($result) {
            $event_name = REDCap::getEventNames(true, false, $event);
            $message = "Form ($form) has been designated in the event $event_name.";
        } else {
            $message = $iih->getErrors();
        }


        $this->emDebug("RETURN STATUS", $result, $message);

        return array($result, $message);

    }


    public function makeFormRepeat($form, $event) {
        $iih = new InsertInstrumentHelper($this);

        $this->emDebug("MAKE FORM REPEATING: ". $form . $event);
        $result = $iih->makeFormRepeating($form, $event);

        if ($result) {
            $event_name = REDCap::getEventNames(true, false, $event);
            $message = "Form ($form) has been made repeating in event $event_name.";
        } else {
            $message = $iih->getErrors();
        }

        //$this->emDebug("RETURN STATUS", $result, $message);
        return array($result, $message);

    }


    public function makeEventRepeat($event) {
        $iih = new InsertInstrumentHelper($this);

        $this->emDebug("!MAKE EVENT REPEATING: ". $event );
        $result = $iih->makeEventRepeating($event);

        if ($result) {
            $event_name = REDCap::getEventNames(true, false, $event);
            $message = "Event ($event_name) has been made repeating";
        } else {
            $message = $iih->getErrors();
        }
        //$this->emDebug("RETURN STATUS", $result, $message);

        return array($result, $message);

    }

    /*******************************************************************************************************************/
    /* HELPER METHODS                                                                                                    */
    /***************************************************************************************************************** */




    /**
     *
     * @param $record
     * @param $filter_event : event NAME not id
     * @param $filter_field
     * @param $filter_value
     * @param null $retrieve_array
     */
    public function retrieveParticipantFieldWithFilter($record, $filter_event,  $filter_field, $filter_value, $hash_field) {

        $filter = "[" . $filter_event . "][" . $filter_field . "] = '$filter_value'";

        $params = array(
            'return_format'    => 'json',
            'records'          => $record,
            'events'           => $filter_event,
            'fields'           => array($hash_field),
            'filterLogic'      => $filter
        );

        $q = REDCap::getData($params);
        $records = json_decode($q, true);

        //since 9.1, repeating instance return an empty array plus filtered array.
        foreach ($records as $k => $v) {
            //$this->emDebug($v);
            if (!empty($v[$hash_field])) {
                return $v[$hash_field];
            }
        }

        $this->emDebug("COULD NOT FIND HASH FIELD", $filter, $records);

        return null;


    }


    /**
     * Given the config_id (text entered to name the configuration subsetting, return the subsetting number (subid)
     *
     * @param $config_id
     * @return false|int|string
     */
    public function getSubIDFromConfigID($config_id) {
        $config_ids = $this->getProjectSetting('config-id');
        return array_search($config_id, $config_ids);

    }

    /**
     * Given the subId (subsetting number 0,1,...) returned the text field used to 'name' configuration subsetting.
     *
     * @param $sub
     * @return mixed
     */
    public function getConfigIDFromSubID($sub) {
        $config_ids = $this->getProjectSetting('config-id');
        return $config_ids[$sub];
    }

    /**
     * @param $project_id
     * @param $url_field
     * @param $event
     * @return string
     */
    public function generateUniqueConfigID($hash_field) {
        $config_ids = $this->getProjectSetting($hash_field);
        $max = max($config_ids);

        if ($max == null) {
            return 1;
        }
        return $max + 1;

    }


    /**
     *
     *
     * @param $project_id
     * @param $url_field
     * @param $event
     * @return string
     */
    public function generateUniquePersonalHash($project_id, $hash_field, $event) {
        //$url_field   = $this->getProjectSetting('personal-url-fields');  // won't work with sub_settings

        $i = 0;
        do {
            $new_hash = generateRandomHash(8, false, TRUE, false);

            $this->emDebug("NEW HASH ($i):" .$new_hash);
            $params = array(
                'return_format' => 'array',
                'fields' => array($hash_field),
                'events' => $event,
                'filterLogic'  => "[".$hash_field."] = '$new_hash'"
            );
            $q = REDCap::getData($params);
//                'array', NULL, array($cfg['MAIN_SURVEY_HASH_FIELD']), $config_event[$sub],
//                NULL,FALSE,FALSE,FALSE,$filter);
            //$this->emDebug($params, "COUNT IS ".count($q));
            $i++;
        } while ( count($q) > 0 AND $i < 10 ); //keep generating until nothing returns from get

        //$new_hash_url = $portal_url. "&h=" . $new_hash . "&sp=" . $project_id;

        return $new_hash;
    }

    /**
     * Convenience method to see if the REDCap field passed for this event and record is already set
     * Return value or null if not set
     *
     * @param $record
     * @param $event
     * @param $target_field
     * @return |null
     */
    public function getFieldValue($record, $event, $target_field, $instrument, $repeat_instance = 1) {


        //Right instrument, carry on
        // First check if hashed portal already has been created
        $params = array(
            'return_format'       => 'json',
            'records'             => $record,
            //'fields'              => array($target_field, 'redcap_repeat_instance'), //include this doesn't return repeat_instance, repeat_instrument
            'events'              => $event,
            'redcap_repeat_instrument' => $instrument,       //this doesn't restrict
            'redcap_repeat_instance'   => $repeat_instance   //this doesn't seem to do anything!
        );

        $q = REDCap::getData($params);
        $results = json_decode($q, true);


        //get the key for this repeat_instance
        //this won't work for project with multiple repeating forms
        //$key = array_search($repeat_instance, array_column($results, 'redcap_repeat_instance'));
        //$key_2 = array_search($repeat_instance, array_column($results, 'redcap_repeat_instance'));
        //$key = array_keys($results, [ 'redcap_repeat_instance' => $repeat_instance,'redcap_repeat_instrument' => $instrument]);

        //giving up! just search for it.
        $key =  $this->findRepeatingInstance($results,$instrument,  $repeat_instance, $instrument);

        //$this->emDebug($results);

        if ($key == null) {
            $this->emDebug("Check that the instrument $instrument is repeating.");
        }
        //$this->emDebug($repeat_instance, $instrument, $key);

        $target = $results[$key][$target_field];

        return $target;
    }

    /**
     * Returns the specified instance from the array of repeating instrument instances
     *
     * Repeating Forms getData returns a blank instance for array slot 0.
     * Cannot assume that slot 1 is the desired array (this has changed in the last few upgrades)
     *
     * @param $result                :
     * @param $repeat_instrument     : repeat instrument name
     * @param null $repeat_instance
     * @return int|string|null
     */
    function findRepeatingInstance($result, $repeat_instrument, $repeat_instance = null) {
        //$this->emDebug($result, $repeat_instance, $repeat_instance == null);
        foreach ($result as $array_num => $cand) {
            //$this->emDebug("looking for $repeat_instrument at array number: " . $array_num );
            if ($repeat_instance != null) {
                if ($cand['redcap_repeat_instance'] != $repeat_instance) {
                    //wrong instance, carry on
                    continue;
                }
            }
            if ($cand['redcap_repeat_instrument'] == $repeat_instrument) {
                return $array_num;
            }
        }

        //if got here, none was found
        return null;
    }


    /**
     * Returns all surveys for a given record id
     *
     * @param $id  participant_id (if null, return all)
     * @param $cfg
     * @return mixed
     */
    public function  getAllSurveys($id = null) {

        //get fields of each hash - separately? can't assume that they will be in the same event.
        $survey_config_field= $this->getProjectSetting('survey-config-field');
        $enable_portal = $this->getProjectSetting('enable-portal');
        $config_ids = $this->getProjectSetting('config-id');

        //run these separately by $sub (subsetting)
        $all_surveys = array();
        foreach ($enable_portal as $sub => $enabled) {

            //only execute if enabled
            if ($enabled) {
                $config_id = $config_ids[$sub];
                //$this->emDebug($config_id . " for " . $sub);
                $all_surveys[$sub] = $this->getSurveysForConfig($sub);
            }
        }

        //$this->emDebug($all_surveys); exit;
        return $all_surveys;
    }

    public function getSurveysForConfig($sub) {
        //get the config id and filter surveys on the config
        $config_id = ($this->getProjectSetting('config-id'))[$sub];
        $survey_config_field = ($this->getProjectSetting('survey-config-field'))[$sub];
        $survey_event_id = ($this->getProjectSetting('survey-event-name'))[$sub];
        $survey_event_arm_name = REDCap::getEventNames(true, false, $survey_event_id);
        $survey_event_prefix = empty($survey_event_arm_name) ? "" : "[" . $survey_event_arm_name . "]";


        if ($config_id == null) {
            $filter = null; //get all ids
        } else {
            $filter = $survey_event_prefix . "[$survey_config_field]='$config_id'";
        }


        $get_data = array(
            REDCap::getRecordIdField(),
            ($this->getProjectSetting('survey-config-field'))[$sub],
            ($this->getProjectSetting('survey-day-number-field'))[$sub],
            ($this->getProjectSetting('survey-date-field'))[$sub],
            ($this->getProjectSetting('survey-launch-ts-field'))[$sub],
            ($this->getProjectSetting('valid-day-number'))[$sub],
            ($this->getProjectSetting('survey-instrument'))[$sub] . '_complete'
        );

        $params = array(
            'return_format' => 'json',
            'fields'        => $get_data,
            'events'        => $survey_event_id,
            'filterLogic'   => $filter
        );


        $q = REDCap::getData($params);
        $results = json_decode($q,true);

        $arranged = $this->arrangeSurveyByID($sub, $results);
        return $arranged;

    }


    /**
     * Returns the portal related data for each participant by sub
     *
     * @return array
     */
    public function getPortalData() {
        $enable_portal = $this->getProjectSetting('enable-portal');

        //run these separately by $sub (subsetting)
        $portal_data = array();
        foreach ($enable_portal as $sub => $enabled) {

            //only execute if enabled
            if ($enabled) {
                $portal_data[$sub] = $this->getPortalDataForConfig($sub);
            }

        }
        return $portal_data;

    }


    /**
     * Return portal data for the participants which is assigned to this sub setting
     *
     * @param $sub
     * @return array|mixed
     */
    public function getPortalDataForConfig($sub) {

        //get the config id associated with this subsetting id
        $config_id = $this->getConfigIDFromSubID($sub);

        $this->emDebug("SUB IS " . $sub . " and config id is ". $config_id);

        //filter out participant for which this config id is assigned
        $main_event_id = ($this->getProjectSetting('main-config-event-name'))[$sub];
        $survey_event_id = ($this->getProjectSetting('survey-event-name'))[$sub];
        $main_event_name = REDCap::getEventNames(true, false, $main_event_id);
        $config_field = ($this->getProjectSetting('participant-config-id-field'));


        $filter = "[" . $main_event_name . "][" . $config_field . "] = '{$config_id}'";

        $portal_fields = array(
            REDCap::getRecordIdField(),
            ($this->getProjectSetting('start-date-field'))[$sub],
            ($this->getProjectSetting('participant-config-id-field'))
        );

        $portal_params = array(
            'return_format' => 'json',
            'fields'        => $portal_fields,
            'filterLogic'   => $filter,
            'events'        => $main_event_id
        );
        $q = REDCap::getData($portal_params);
        $portal_data = json_decode($q, true);


        //rearrange so that the id is the key
        $portal_data = $this->makeFieldArrayKey($portal_data, REDCap::getRecordIdField());

        return $portal_data;

    }



    public function arrangeSurveyByID($sub, $surveys ) {

         $survey_date_field = $this->getProjectSetting('survey-date-field')[$sub];
         $survey_day_number_field = $this->getProjectSetting('survey-day-number-field')[$sub];
         $survey_form_name_complete= $this->getProjectSetting('survey-instrument')[$sub] . '_complete';

        $arranged = array();

        foreach ($surveys as $k => $v) {
            $id = $v[REDCap::getRecordIdField()];
            $day_number = $v[$survey_day_number_field];

            //$this->emDebug($k, $v, $id, $day_number, $survey_day_number_field, $survey_form_name_complete); exit;

            $arranged[$id][$day_number] = array(
                "SURVEY_DATE"  => $v[$survey_date_field],
                "STATUS"       => $v[$survey_form_name_complete]
            );
        }

        return $arranged;

    }

    public function dumpResource($name) {
        $file =  $this->getModulePath() . $name;
        if (file_exists($file)) {
            $contents = file_get_contents($file);
            echo $contents;
        } else {
            $this->emError("Unable to find $file");
        }
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
     * Make key_field as key
     *
     * @param $data
     * @param $key_field
     * @return array
     */
    static function makeFieldArrayKey($data, $key_field) {
        $r = array();
        foreach ($data as $d) {
            $r[$d[$key_field]] = $d;
        }
        return $r;

    }


    /*******************************************************************************************************************/
    /* EXTERNAL MODULES METHODS                                                                                                    */
    /***************************************************************************************************************** */

    function emText($number, $text) {
        global $module;

        $emTexter = ExternalModules::getModuleInstance('twilio_utility');
        //$this->emDebug($emTexter);
        $text_status = $emTexter->emSendSms($number, $text);
        return $text_status;
    }
}