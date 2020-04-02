<?php

namespace Stanford\RepeatingSurveyPortal;

use REDCap;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */

require_once 'InvitationManager.php';

$sub = isset($_GET['s']) ? $_GET['s'] : "";

$module->emLog("------- Starting Repeating Survey Portal:  Invitation Cron for  $project_id with config sub-setting $sub-------");
echo "------- Starting Repeating Survey Portal:  Invitation Cron for $project_id with config sub-setting $sub-------";

//check if this $sub is enabled
$enabled = $module->getSubSettings('survey-portals')[$sub]['enable-portal'];
if (! $enabled ) {
    $module->emLog("Subsetting $sub is not enabled. Not sending invitations.");
    exit;
}



$inviteMgr = new InvitationManager($project_id, $sub);

if (isset($inviteMgr)) {
    $inviteMgr->sendInvitations($sub);
}