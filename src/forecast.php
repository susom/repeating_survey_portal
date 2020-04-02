<?php

namespace Stanford\RepeatingSurveyPortal;

use DateTime;
use DateInterval;
use DatePeriod;

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */

$begin = '';
$end = '';

//check if in context of record. if not report error
//Plugin::log($project_id, "DEBUG", "PROJECT ID");


if(isset($_POST['submit']))
{

    $begin = new DateTime($_POST["start_date"]);
    $end = new DateTime($_POST["end_date"]);
    $today = new DateTime();
    if ($end > $today) {
        $end = $today;
    }
    $begin_str = $begin->format('Y-m-d');
    $end_str = $end->format('Y-m-d');

}


if ($end != '') {

    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($begin, $interval, $end);

    foreach ($period as $dt) {
        $dow = $dt->format("l");
        $dates[]= $dt->format("Y-m-d");
        $dates_day[] = $dt->format("Y-m-d") . " - " . $dow;
    }
    //$module->emDebug($dates, $dates_day);exit;


    ///////////// NEW WAY SINCE TAKING TOO LONG  /////////////////////
    //1. Get all survey data from survey project
    $surveys = $module->getAllSurveys();
    //$module->emDebug($surveys); exit;

    //2. Get survey portal data from main project
    $portal_data = $module->getPortalData();

    //3. For each sub, spool out from start date according to the valid-day_number array and set status
    $survey_status = array();
    foreach ($portal_data as $sub => $participants) {
        $start_date_field = ($module->getProjectSetting('start-date-field'))[$sub];
        $valid_day_str =($module->getProjectSetting('valid-day-number'))[$sub];

        $valid_day_array = RepeatingSurveyPortal::parseRangeString($valid_day_str);


        foreach ($participants as $record_id => $participant) {
            $start_date = new DateTime($participant[$start_date_field]);
            //$module->emDebug($sub,$record_id,  $start_date); exit;
            $sub_survey_status = getValidDayStatus($sub, $record_id, $start_date, $surveys[$sub], ($valid_day_array));

            $survey_status = array_merge($survey_status, $sub_survey_status);
        }

    }

    $table_header = array_merge(array("Participant"), $dates);


}

function getValidDayStatus($sub, $participant_id, $start_date,  $surveys, $valid_day_array) {
    global $module;
    $config_ids = $module->getProjectSetting('config-id');


    $survey_status = array();
    $date = clone $start_date;

    foreach ($valid_day_array as $day_number) {
        //$module->emDEbug($start_date, $date, $day_number);

        $config_id = $config_ids[$sub];
        $date_str = $date->format('Y-m-d');
        //$module->emDebug($day_number, $date, $start_date );
        $survey_status[$participant_id . '_'.$config_id][$date_str]['DAY_NUMBER'] = $day_number;
        $survey_status[$participant_id . '_'.$config_id][$date_str]['STATUS'] =
            isset($surveys[$participant_id][$day_number]['STATUS']) ? $surveys[$participant_id][$day_number]['STATUS'] : "-1";

        //set the next day
        $date = clone $start_date;

        //get date for current $day_number
        $date = $date->modify('+ '. $day_number . ' days');

    }

    return $survey_status;

}



/**
 * Renders straight table without attempting to decode
 * @param  $id
 * @param array $header
 * @param  $data
 * @return string
 */
function renderParticipantTable($id, $header = array(), $data, $date_window) {
    // Render table
    $grid = '<table id="' . $id . '" class="table table-striped table-bordered table-condensed" cellspacing="0" width="95%">';
    $grid .= renderHeaderRow($header, 'thead');
    $grid .= renderSummaryTableRows($data, $date_window);
    $grid .= '</table>';

    return $grid;
}

function renderHeaderRow($header = array(), $tag) {
    $row = '<' . $tag . '><tr>';
    foreach ($header as $col_key => $this_col) {
        $row .= '<th>' . $this_col . '</th>';
    }
    $row .= '</tr></' . $tag . '>';
    return $row;
}

/**
 * @param $row_data
 * @param $date_window  window specified in UI
 * @return string
 */
function renderSummaryTableRows($row_data, $date_window) {
    global $module;
    $rows = '';

    foreach ($row_data as $participant => $dates) {
        $rows .= '<tr><td>' . $participant. '</td>';

        foreach ($date_window as $display_date) {

            $status = $dates[$display_date]['STATUS'];
            $day_num = $dates[$display_date]['DAY_NUMBER'];
            //$module->emDebug($display_date, $status, $day_num, $dates); exit;

            $status_unscheduled = '';
            $status_blue = '<button type="button" class="btn btn-info btn-circle"><i class="glyphicon"></i><b>'.$day_num.'</b></button>';
            $status_yellow = '<button type="button" class="btn btn-warning btn-circle"><i class="glyphicon"></i><b>'.$day_num.'</b></button>';
            $status_green = '<button type="button" class="btn btn-success btn-circle"><i class="glyphicon"></i><b>'.$day_num.'</b></button>';
            $status_red = '<button type="button" class="btn btn-danger btn-circle"><i class="glyphicon"></i><b>'.$day_num.'</b></button>';

            switch ($status) {
                    case "-1":
                        $rows .= '<td>' . $status_red .  '</td>';
                        break;
                    case '0':
                        $rows .= '<td>' . $status_yellow . '</td>';
                        break;
                    case '1':
                        $rows .= '<td>' . $status_blue . '</td>';
                        break;
                    case '2':
                        $rows .= '<td>' . $status_green . '</td>';
                        break;
                    default:
                        $rows .= '<td>' . $status_unscheduled . '</td>';
                }


        }
        $rows .= '</tr>';
    }
    return $rows;
}

//display the table
//include "pages/report_page.php";

?>

<!DOCTYPE html>
<html>
<head>
    <title><?php echo $module->getModuleName()?></title>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?php print $module->getUrl("img/favicon/stanford_favicon.ico",false,true) ?>">

    <script type="text/javascript" src="https://code.jquery.com/jquery-3.3.1.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.10.18/js/jquery.dataTables.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/1.10.18/js/dataTables.bootstrap4.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.4/js/dataTables.buttons.min.js"></script>
    <!--script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.4/js/buttons.bootstrap4.min.js"></script-->
    <script type="text/javascript" src="https://cdn.datatables.net/buttons/1.5.4/js/buttons.html5.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/fixedcolumns/3.2.5/js/dataTables.fixedColumns.min.js"></script>
    <script type="text/javascript" src="https://cdn.datatables.net/fixedheader/3.1.4/js/dataTables.fixedHeader.min.js"></script>


    <!-- Bootstrap core CSS -->
    <link rel="stylesheet" type="text/css" media="screen" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
    <!--
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/1.10.18/css/dataTables.bootstrap4.min.css"/>
    -->
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/buttons/1.5.4/css/buttons.bootstrap4.min.css"/>

    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/fixedcolumns/3.2.5/css/fixedColumns.bootstrap4.min.css"/>
    <link rel="stylesheet" type="text/css" href="https://cdn.datatables.net/fixedheader/3.1.4/css/fixedHeader.bootstrap4.min.css"/>



    <!-- Bootstrap Date-Picker Plugin -->
    <script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.1/js/bootstrap-datepicker.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.4.1/css/bootstrap-datepicker3.css"/>

    <style><?php echo $module->dumpResource('css/Forecast.css'); ?></style>



    <!-- Add local css and js for module -->
</head>
<body>
<div class="container">
    <div class="jumbotron">
        <h3>Survey Schedule Report</h3>
    </div>
    <form method="post">
    <div class="well">
        <div class="container">
            <div class='col-md-4'>
                <div class="form-group">
                    <label>START</label>
                    <div class='input-group date' id='datetimepicker6'>
                        <input name="start_date" type='text' placeholder='YYYY-MM-DD' class="form-control" />
                        <span class="input-group-addon">
                    <span class="glyphicon glyphicon-calendar"></span>
                </span>
                    </div>
                </div>
            </div>
            <div class='col-md-4'>
                <div class="form-group">
                    <label>END</label>
                    <div class='input-group date' id='datetimepicker7'>
                        <input name="end_date" type='text'  placeholder='YYYY-MM-DD' class="form-control" />
                        <span class="input-group-addon">
                    <span class="glyphicon glyphicon-calendar"></span>
                </span>
                    </div>
                </div>
            </div>
        </div>
        <input class="btn btn-primary" type="submit" value="START" name="submit">
    </div>
    </form>

</div>

<div class="container">
    <?php print renderParticipantTable("summary", $table_header, $survey_status, $dates) ?>
</div>
</body>

<script type = "text/javascript">

    $(document).ready(function(){

        $('#summary').DataTable( {
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf'
            ],
            scrollY:        "600px",
            scrollX:        true,
            scrollCollapse: true,
            paging:         false,
            fixedColumns:   {
                leftColumns: 1
            }
        } );

        $('#datetimepicker6').datepicker({
            format: 'yyyy-mm-dd'

        });
        $('#datetimepicker7').datepicker({
            format: 'yyyy-mm-dd'
        });

        $('input[name="start_date"]').val("<?php echo $begin_str?>");
        $('input[name="end_date"]').val("<?php echo $end_str?>");

    });


</script>



