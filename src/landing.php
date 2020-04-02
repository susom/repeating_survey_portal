<?php

namespace Stanford\RepeatingSurveyPortal;

use REDCap;
use DateTime;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */

require_once 'Portal.php';



/** @var \Stanford\RepeatingSurveyPortal\Portal $portal */
/** @var \Stanford\RepeatingSurveyPortal\Participant $participant */

$module->emDebug("===== Firing up the landing page ====");

$config_ids = $module->getProjectSetting('config-id');


$p_hash = isset($_REQUEST['h']) ? $_REQUEST['h'] : "";
$p_config = isset($_REQUEST['c']) ? $_REQUEST['c'] : "";
$p_daynumber = isset($_REQUEST['d']) ? $_REQUEST['d'] : "";
$error_msg = null;


//todo bail if no hash , no config
try {
    $portal = new Portal($p_config, $p_hash);
    $participant = $portal->getParticipant(); //$portal->getParticipant();
    $portalConfig = $portal->getPortalConfig();

    $module->emDebug("Just fired up portal for ".$participant->getParticipantID());
    //$module->emDebug($_SESSION, "SESSION AT LANDING ", $p_hash, $p_config, $p_daynumber, $portalConfig, $portalConfig->getEnablePortal());
    //$module->emDebug($participant->survey_status); exit;


    //If portal is not enabled, just bail
    if (!$portalConfig->getEnablePortal()) {
    $error_msg[] = "<b>Portal '$p_config' is not enabled.</b> Please contact your administrator.";
    //die("<b>Portal '$p_config' is not enabled.</b> Please contact your administrator.");
    }

    //Check participant portal is disabled
    if ($participant->getParticipantPortalDisabled()) {
        $error_msg[] = "<b>Participant's portal '$p_config' is not enabled.</b> Please contact your administrator.";
        //die("<b>Portal '$p_config' is not enabled.</b> Please contact your administrator.");
    }

    //Need to set up a cookie so that after the survey completion, it will redirect back to the portal.
    $cookie_key = $module->PREFIX."_".$project_id."_".$participant->getParticipantID();
    $module->emDebug("Setting COOKIE KEY to ".$cookie_key);
    setcookie($cookie_key, $p_config, time()+(12*3600), "/");

} catch (\Exception $e) {
    $error_msg[] = $e->getMessage();
    $error_msg[] = "If you continue to experience difficulties, please contact your admin. ";
    echo implode("<br>", $error_msg);
    exit;
}


/**
 * How do we handle the content of hte landing page?
 *
 * What is encoded in the url for a landing page?
 *  - pid        (REQUIRED)
 *  - hash       (REQUIRED) -> used to get the record_id
 *  - config     (REQUIRED)
 *  - day number (OPTIONAL) - from invitations/reminders but not present in status participant url
 * https://redcap.stanford.edu/api/?type=module&prefix=survey_portal &page=web%20landing.php &pid=12067 &record=1234 &c=YYY &day=x
 *
 *
 * Personal URL:
 *  https://redcap.stanford.edu/api/?type=module&prefix=survey_portal &page=web%20landing.php &pid=12067 &h=XXXX &c=YYY &NOAUTH
 *
 * 1) each url generated is unique, we store it in the em_logs with parameters....
 * 1. retrieve record for this hash
 *
 *
 * //8MAR2019 ??
 * TODO question: rethinking precedence of starts
 * 1. If there is a day number ( &d=#), then auto_start
 * 2. calendar and start today  should be toggle. If calendar is ON display calendar, if OFF then autostart
 *    this is independent of the calendar redirect
 *    --> remove the auto-start config property
 *
 *
 *
 * If Autostart:
 *
 *
 *  1) if day number is not set, then calculate day number from start date nad current date
 *
 *      IF day number is present, then use that day number
 *
 * 2) Validate current time window for daynumber
 *
 * 3) Create survey for date and redirect
 *
 * If Not Autostart, then Calendar (//todo: is there another option?
 *
 * 1) Configure all teh Calendar options
 *    (a) show-missing-day-buttons
 *    (b)
 *
 * 2) if date is selected, validate time window, create survey, redirect
 */

/**
 * if auto_start,
 *    if daynumber set, confirm valid day, and start
 *    if daynumber NOT set, find day number, confirm valid day, and start
 *
 * if calendar, display calendar
 * if NOT calendar, display try later message
 */
$today = new DateTime();
//$error_msg = null;

//Get survey_date and daynumber from the various entry points

if(isset($_POST['cal_submit'])) {
    $survey_date = DateTime::createFromFormat('Y-m-d', $_POST['cal_date']);

    //if (isset($survey_date)) {
    if (!empty($survey_date)) {
        $module->emDebug("From Calendar launch: Starting with date: ", $survey_date);
        $day_number = $participant->getDayNumberFromDate($survey_date);
        $module->emDebug("From Calendar launch: Starting with date: " . $survey_date->format('Y-m-d'). ' and daynumber: '. $day_number);
    } else {
        $error_msg[] = "No survey date was received from the calendar submission.";
    }
} else {

    //if daynumber passed in parameter, just start
    if ($p_daynumber != "") {
        $day_number = intval($p_daynumber);
        $survey_date = $participant->getSurveyDateFromDayNumber($day_number);
        $module->emDebug($survey_date, "Day number is set, so confirm in allowed window and start: " . $day_number);
    } else {

        //if no calendar option, then assume auto_start and derive survey_date as today
        if ($portal->showCalendar) {

            $module->emDebug("No day number is set, so find day number, confirm valid day and start");

            //Given today's date, get daynumber
            $day_number = $participant->getDayNumberFromDate($today);
            $survey_date = $today;
            $module->emDebug("Day number for " . $today->format('Y-m-d') . " is " . $day_number);


            if (!isset($survey_date)) {
                $error_msg[] = "Survey date could not be derived from the day number.";
            }
        }
    }
}

//if (isset($survey_date)) {  //blanks are counting as set??
if (!empty($survey_date)) {
    //$module->emDebug(get_class($participant));

    if ($day_number === null) {
        $error_msg[] = $survey_date->format('Y-m-d'). " does not correspond to a valid day number. It is not in the range.";
    }

    if (!$participant->isDayLagValid($survey_date)) {
        $error_msg[] = "The survey is past the allowed day lag: ". $participant->portalConfig->validDayLag . ' days';
    }

    if (!$participant->isStartTimeValid($survey_date)) {
        $error_msg[] = "The earliest allowed start time to take the survey today is " . $participant->portalConfig->earliestTimeAllowed . ':00';
    }

    if (!$participant->isMaxResponsePerDayValid($day_number, $survey_date)) {
        $error_msg[] = "The number of survey entries for this date has exceeded the allowed count of  " . $participant->portalConfig->maxResponsePerDay;
    }

}

if (($error_msg == null) &&  (isset($day_number)) && (isset($survey_date))) {

    $module->emDebug("ParticipantID: " .$participant->getParticipantID().  " Valid DAY NUMBER : " . $survey_date->format('Y-m-d'). ' and daynumber: '.  $day_number);

    //check for partial survey today
    $next_id = $participant->getPartialResponseInstanceID($day_number, $survey_date);

    //setup survey link for the correct survey
    //prefill new survey with day_number / date/
    $status = $participant->newSurveyEntry($day_number, $survey_date,$next_id);

    if ($status === false ) {
        $error_msg[] = "There was an error trying to create the new survey entry. Please contact your administrator.";

    } else {


        // surveyDayNumberField: Day number
        // surveyDateField : this should be day of day number not actual day
        // REDCap::getSurveyLink ( string $record, string $instrument [, int $event_id = NULL [, int $repeat_instance = 1 ]] )
        $survey_link = REDCap::getSurveyLink(
            $participant->getParticipantID(),
            $participant->portalConfig->surveyInstrument,
            $participant->portalConfig->surveyEventID,
            $next_id);

        //start
        header("Location: " . $survey_link);
        exit;
    }

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
 *
 *
 */


?>

    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta http-equiv="X-UA-Compatible" content="IE=edge">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Survey Portal
        </title>
        <link rel="stylesheet" href="<?php echo $module->getUrl('css/bootstrap.min.css', true, true) ?>"
              type="text/css"/>
        <link rel="stylesheet" href="<?php echo $module->getUrl('css/SurveyPortal.css', true, true) ?>"
              type="text/css"/>

        <link href='https://fonts.googleapis.com/css?family=Oxygen:400,300,700' rel='stylesheet' type='text/css'>
        <link href='https://fonts.googleapis.com/css?family=Lora' rel='stylesheet' type='text/css'>

        <!-- date picker  for calendar
        <script src='https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.6.0/js/bootstrap-datepicker.min.js'></script>
        <link href='https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.6.0/css/bootstrap-datepicker.css' rel='stylesheet' media='screen'></link>";
        -->

        <!--  jQuery -->
<script type="text/javascript" src="https://code.jquery.com/jquery-1.11.3.min.js"></script>

<!-- Isolated Version of Bootstrap, not needed if your site already uses Bootstrap -->
<link rel="stylesheet" href="https://formden.com/static/cdn/bootstrap-iso.css" />



    <link rel="stylesheet" type="text/css" media="screen" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">


    <!-- Bootstrap Date-Picker Plugin -->

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.1/css/bootstrap-datepicker3.css"/>


    </head>
    <body>

    <div id="project_title" alt="Project Title"></div>

    <div id="calendar_widget" alt="Calendar Widget">


<div class='container'>
    <div class='jumbotron text-center'>
        <?php if (!empty( $error_msg )) { ?>
            <div class="alert alert-danger" role="alert">
                <span class="glyphicon glyphicon-exclamation-sign" aria-hidden="true"></span>
                <span class="sr-only">Error:</span>
                <?php

                echo implode("<br>", $error_msg) ?>
            </div>
        <?php } ?>
        <p><?php echo $portalConfig->landingPageHeader; ?></p>
        <p><?php echo "Participant: ".$participant->getParticipantID(); ?></p>
        <div class="container">
            <div class="col-sm-8 col-sm-offset-2 col-xs-12">
                <form method="POST">
                    <div class="form-group text-center">
                        <div class="input-group date">
    <!--                        <div class="input-group-addon">-->
    <!--                            <span class="glyphicon glyphicon-th"></span>-->
    <!--                        </div>-->
                            <input name="cal_date" id="cal_date" type="text" class="form-control text-center"
                                   value="<?php
                                        $module->emDebug("Setting default date to ".$today->format('Y-m-d'));
                                        echo $today->format('Y-m-d');
                                        //$dt = new DateTime(); print $dt->format("m/d/Y");
                                   ?>" placeholder="Select A Date">
                            <span class="input-group-btn">
                                <button id="cal_submit" name="cal_submit" type="submit" class="btn btn-primary">Launch Survey</button>
                            </span>
                        </div>
                    </div>
    <!--                <div class="input-group date">-->
    <!--                    <input type="text" class="form-control" value="12-02-2012">-->
    <!--                    <div class="input-group-addon">-->
    <!--                        <span class="glyphicon glyphicon-th"></span>-->
    <!--                    </div>-->
    <!--                </div>-->
    <!--                <div id="date_picker" data-date="--><?php //$dt = new DateTime(); print $dt->format("m/d/Y"); ?><!--"></div>-->
                    <div id="legend" class="legend" style="display:none;">
                        <div><b>Legend:</b></div>
                        <div><span class="active block"></span><span>Selected</span></div>
                        <div><span class="today block"></span><span>Today</span></div>
                        <div><span class="survey-complete block"></span><span>Complete</span></div>
    <!--                    <div><span class="survey-partial block"></span><span>Partial</span></div>-->
                        <div><span class="survey-not-started block"></span><span>Incomplete</span></div>
                        <div><span class="disabled block"></span><span>Disabled</span></div>
                    </div>
                </form>
            </div>
        </div>
        <div class='container text-center'>
        <p>You may bookmark this page to your home screen for faster access in the future.</p>
        <div class='text-center'>
            <span><a href='#Instructions' class='btn btn-default' data-toggle='collapse'>Show Instructions</a></span>
            <div id='Instructions' class='collapse'>
                <br/>
                <div class='panel panel-default'>
                    <div class='panel-body text-left'>
                        <p><strong>On an iOS phone</strong></p>
                         <ol>
                             <li>If you do not see the toolbar at the bottom of your Safari window, tap once at the bottom of your screen</li>
                             <li>Click on the Action button in the center of the toolbar (a square box with an upward arrow)</li>
                             <li>Scroll the lower row of options to the right until you see 'Add to Home Screen' (a box with a plus sign).</li>
                         </ol>
                         <p><strong>On an Android phone</strong></p>
                         <ol>
                             <li>Open the Chrome menu <img src="//lh3.googleusercontent.com/vOgJaWNbkf_Y0kOEQXe4wSlufkMuTb8NqGMIXSP-mRm72oR4ABGkR1L4sXyMmb7lBHnz=h18" width="auto" height="18" alt="More" title="More">.</li>
                             <li>Tap the star icon <img src="//lh3.ggpht.com/SEdDjoaQ-qufNcDGhJh5KXW0q3-tABnuWjM5fpqE9kbOyJaXN3co5MEcQu7kqoCIqHA5O84=w20" width="20" height="18" alt="Bookmark" title="Bookmark">.</li>
                             <li>Optional: If you want to edit the bookmark's name and URL or change the folder, go to the bottom bar and tap&nbsp;<strong>Edit</strong>.</li>
                             <li>When you're done, tap the checkmark .</li>
                             <li>Optional:  If you want to make the bookmark appear on your home screen (like an app) open the bookmarks folder and <strong>press and hold</strong> your finger on the bookmark.  A new menu will appear with an option to Add to Home screen</li>
                         </ol>
                         <p><strong>On an Android tablet</strong></p>
                         <ol>
                             <li>In the address bar at the top, tap the star icon&nbsp;<img src="//lh3.ggpht.com/SEdDjoaQ-qufNcDGhJh5KXW0q3-tABnuWjM5fpqE9kbOyJaXN3co5MEcQu7kqoCIqHA5O84=w20" width="20" height="18" alt="Bookmark" title="Bookmark">.</li>
                             <li>Optional: If you want to edit the bookmark's name and URL or change the folder, go to the bottom bar and tap&nbsp;<strong>Edit</strong>.</li>
                             <li>When you're done, tap the checkmark .</li>
                             <li>Optional:  If you want to make the bookmark appear on your home screen (like an app) open the bookmarks folder and <strong>press and hold</strong> your finger on the bookmark.  A new menu will appear with an option to Add to Home screen</li>
                         </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>

    </div>
</div>



    </div>

    <footer class="panel-footer">

    </footer>
    <script src="<?php echo $module->getUrl('js/jquery-3.2.1.min.js', true, true) ?>"></script>
    <script src="<?php echo $module->getUrl('js/bootstrap.min.js', true, true) ?>"></script>

    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.1/js/bootstrap-datepicker.min.js"></script>
    <!--  i am having a lot of trouble getting 1.6.0 datapicker to work.
    <script src='https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.6.0/js/bootstrap-datepicker.min.js'></script>
    -->
    </body>
    </html>
    <script type="text/javascript">

        // Used in setting calendar day highlighting
        function formatDate(date) {
            var d = new Date(date),
                month = '' + (d.getMonth() + 1),
                day = '' + d.getDate(),
                year = d.getFullYear();
            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;
            return [year, month, day].join('-');
        }

        // Example format:  var survey_dates = { '2016-02-04': 'some description', '2016-02-25': 'some other description' };
        var survey_dates = <?php echo json_encode($participant->getValidDates()) ?>;
        var invalid_dates = <?php echo json_encode($participant->getInvalidDates()) ?>;
        var first_date = '<?php echo $participant->getFirstDate(); ?>';
        var end_date = '<?php echo $participant->getLastDate(); ?>';

        // Convenience function for inserting innerHTML for 'select'
        var insertHtml = function (selector, html) {
            var targetElem = document.querySelector(selector);
            targetElem.innerHTML = html;
        };

        $(document).ready(function () {
            <?php if ($error_msg != null) { ?>
            $('#error_msg').show();

            <?php }?>

            // Initialize datepicker
            var d = $('#cal_date').datepicker({
                format: 'yyyy-mm-dd',
                startDate: first_date,
                endDate: end_date,
                datesDisabled: invalid_dates,
                autoclose: true,
                todayBtn: 'linked',
                todayHighlight: true,
                orientation: "top",
                //title: 'Select Survey Date',
                beforeShowDay: function (date) {
                    var formattedDate = formatDate(date);
                    if (formattedDate in survey_dates) {
                        var status = survey_dates[formattedDate]['STATUS'];
                        console.log(formattedDate, status);
                        console.log(survey_dates[formattedDate], status);
                        switch (status) {
                            case "0":
                                return {classes: "survey-not-started", tooltip: "Survey Not Started"};
                            case "1":
                                return {classes: "survey-partial", tooltip: "Survey Partially Complete"};
                            case "2":
                                return {classes: "survey-complete", tooltip: "Survey Completed"};
                        }
                        return {classes: 'survey-exists', tooltip: 'Available Survey'};
                    }
                    return {enabled: true};
                }
            });



            // Append the legend each time the calendar is displayed
            $('#cal_date').datepicker().on('show', function (e) {
                // Append Legend
                //var legend = $('#legend').clone().show().appendTo($('div.datepicker'));
                var legend = $('#legend').show().appendTo($('div.datepicker'));
            });

            <?php if ($portalConfig->showCalendar) { ?>
            $('#cal_date').datepicker('show');

            <?php } ?>


                    //bind button for create new user
            $('#cal_submit').on('click', function() {
                console.log("clicked search participant");
                console.log("this", $(this));
            });
        });

    </script>
<style>
    .jumbotron {overflow:hidden;}
    .jumbotron div.alert {font-size:larger;}
    /*.datepicker-title {font-size:larger;}*/
    div.datepicker {margin:auto; border:1px solid #ccc; margin-bottom:20px; background: white;}
    .datepicker-switch {font-size:larger;}
    .survey-complete {background-color: #86DE68;}
    .survey-complete:hover {background-color: #8FEB71 !important;}
    .survey-partial {background-color: #E6ED1F;}
    .survey-partial:hover {background-color: #EEF51D !important;}
    .survey-not-started {background-color: #E67D5A;}
    .survey-not-started:hover {background-color: #F0835D !important;}
    .datepicker table tr td.disabled, .datepicker table tr td.disabled:hover {background: #ddd; border-radius:0; opacity: 0.3}
    .datepicker .today {
        color: white;
        background-color: #337ab7;
        /*border: 1px solid #999; border-radius: 4px;*/
    }
    .datepicker .today:hover { background-color: #2E6DA3 }

    .legend {
        display: none;
        padding: 10px 20px;
        background: white;
        width: 200px;
        margin:auto;
        text-align:left;
        /*border: 1px solid #ccc; border-radius: 4px;*/
    }
    .legend div:nth-child(n+2) {margin-top: 5px;}
    .legend span {vertical-align:middle;}
    .legend .block {width:20px; height:20px; border-radius: 4px; display:inline-block; margin:0 10px 0 10px; border: 1px solid #999;}
    .legend .active {background-color: #0044cc;}
    .legend .disabled {background-color: #ddd;}
    .legend .today {
        background-color: #fde19a;
        background-image: -moz-linear-gradient(to bottom, #fdd49a, #fdf59a);
        background-image: -ms-linear-gradient(to bottom, #fdd49a, #fdf59a);
        background-image: -webkit-gradient(linear, 0 0, 0 100%, from(#fdd49a), to(#fdf59a));
        background-image: -webkit-linear-gradient(to bottom, #fdd49a, #fdf59a);
        background-image: -o-linear-gradient(to bottom, #fdd49a, #fdf59a);
        background-image: linear-gradient(to bottom, #fdd49a, #fdf59a);
        background-repeat: repeat-x;
    }
    /*#cal_date {float:right; max-width: 150px;}*/

</style>
<?php

