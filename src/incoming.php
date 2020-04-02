<?php
namespace Stanford\RepeatingSurveyPortal;

use REDCap;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */

/**
 * Webhook for Twilio to forward incoming text messages in response to text reminders
 * Forward text to email
 */

$module->emDebug('--- Incoming Text to Twilio ---');

//use this webhook to set up in Twilio.
$webhook = $module->getUrl("incoming.php", true, true);
$module->emDebug($webhook);



// Get phone number from Twilio from incoming
$from = $_POST['From'];

// lookup phonenumber in REDCap project to locate record

// Forward body of text to email set in config
$body = $_POST['Body'];

//log incoming to record in REDCap

