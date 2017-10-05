<?php 
function staffLoginPage($msg='Unauthorized') {
    Http::response(403,'Must login: '.Format::htmlchars($msg));
    exit;
}

define('AJAX_REQUEST', 1);
chdir('../../../scp');
require('staff.inc.php');
require_once(INCLUDE_DIR.'class.ticket.php');

//Clean house...don't let the world see your crap.
ini_set('display_errors', '0'); // Set by installer
ini_set('display_startup_errors', '0'); // Set by installer

//TODO: disable direct access via the browser? i,e All request must have REFER?
if(!defined('INCLUDE_DIR'))    Http::response(500, 'Server configuration error');

global $thisstaff;
if(isset($_POST['a'])){
    switch($_POST['a']){
        case 'merge':
            if($thisstaff && ($master = Ticket::lookup($_POST['master'])) 
                && ($ticket = Ticket::lookup($_POST['ticket']))
                && $master->checkStaffPerm($thisstaff)
                && $ticket->checkStaffPerm($thisstaff)){
                MergingPlugin::merge($master, $_POST['ticket']);
            }
            break;
        case 'split':
            if($thisstaff && ($master = Ticket::lookup($_POST['master'])) 
                && ($ticket = Ticket::lookup($_POST['ticket'])) 
                && $master->checkStaffPerm($thisstaff)
                && $ticket->checkStaffPerm($thisstaff)){
                MergingPlugin::split($master, $_POST['ticket']);
            }
            break;
        case 'masssplit':
            if($thisstaff && isset($_POST['tickets'])){
                MergingPlugin::massSplit($_POST['tickets']);
            }
            break;
        case 'massmerge':
            if($thisstaff && ($master = Ticket::lookup($_POST['master']))
                && isset($_POST['tickets'])){
                error_log(print_r("test", true));
                MergingPlugin::merge($master, $_POST['tickets']);
            }
            break;
    }
}
 ?>