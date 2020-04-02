<?php

namespace Stanford\RepeatingSurveyPortal;

require_once 'InsertInstrumentHelper.php';

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */


// HANDLE BUTTON ACTION
if (!empty($_POST['action'])) {
    $action = $_POST['action'];
    //$zip_loader = InsertInstrumentHelper::getInstance($module);
    //$module->emDebug($_POST);
    $message = $delay = $callback = null;

    switch ($action) {
        case "insert_form":
            $form = $_POST['form'];
            list($result, $message) = $module->insertForm($form);


            $module->emDebug("!INSERT FORM", $result);
            $message = $result ? "$form Created!" : $message;

            break;
        case "designate_event":
            $module->emDebug("!DESIGNATING EVENT");
            $form = $_POST['form'];
            $event = $_POST['event'];
            list($result, $message) = $module->designateEvent($form, $event);

            $module->emDebug("result",  $result);
            break;

        case "set_form_repeating":
            $module->emDebug("Make form repeating");
            $form = $_POST['form'];
            $event = $_POST['event'];
            list($result, $message) = $module->makeFormRepeat($form, $event);

            //$module->emDebug("result",  $result);
            break;
        case "set_event_repeating":
            $module->emDebug("!Make EVENT repeating");
            $event = $_POST['event'];

            list($result, $message) = $module->makeEventRepeat($event);

            break;
        case "get_status":
            $raw = $_POST['raw'];
            $data = \ExternalModules\ExternalModules::formatRawSettings($module->PREFIX, $module->getProjectId(), $raw);
            // At this point we have the settings in individual arrays for each value.  The equivalent to ->getProjectSettings();


            // For this module, we want the subsettings of 'instance' - the repeating block of config
            // $module->emDebug( $module->getSettingConfig('instance') );
            $instances = $module->parseSubsettingsFromSettings('survey-portals', $data);
            // $module->emDebug($instances);

            //does the rsp_metadata form exit
            list($result,$message) = $module->getConfigStatus($instances, true);
            //$module->emDebug("!GETSTATUS config ajax", $result, $message);

            break;

        case "test":


            // SAVE A CONFIGURATION
            //$participant_config_id = $_POST['config_field'];


            // $module->debug($raw_config,"DEBUG","Raw Config");


            //if this were working, check that the fields don't already exist in file

        /**
            $p_status = $zip_loader->insertParticipantInfoForm();

            if (!$p_status) {
                //TODO
                $zip_loader->getErrors();
            }

            $m_status = $zip_loader->insertSurveyMetadataForm(); //todo: designate to event with config id
            if (!$m_status) {
                //TODO
                $zip_loader->getErrors();
            }

            //how to deal with designating for event

            $sub_settings = $module->getSubSettings('survey-portals');
            //$module->emDebug($sub_settings);

            foreach ($sub_settings as $sub) {
                //TODO: designate for each event

            }

            $test_error = "foo bar";

            $status = true;
            if ($status) {
                // SAVE
                $result = array(
                    'result' => 'success',
                    'message' => 'Please enable this new form in the event.'
                );
            } else {
                $test_error = 'not foobar';
            }
            $result = array(
                'result' => 'success',
                'message' => $test_error
            );
         */
    }
    header('Content-Type: application/json');
    echo json_encode(
        array(
            'result' => $result,
            'message' => $message,
            'callback' => $callback,
            'delay'=> $delay
        )
    );
    exit();

}
