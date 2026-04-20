<?php
/**

 * rafraichit la liste des salons BBB et la stocke dans une variable apcu

 * @Projet SambaEdu

 * @auteurs denis bonnenfant

 * @Licence Distribue selon les termes de la licence GPL

 */
include "config.inc.php";
require "ldap.inc.php";
require_once ("functions.inc.php");
require_once ("traitement_data.inc.php");
$config = get_config();
header_authorize_script($config);
require_once ("ldap.inc.php");
include "bbb.inc.php";
require "../vendor/autoload.php";
use BigBlueButton\BigBlueButton;
use BigBlueButton\Parameters\JoinMeetingParameters;

/*
 * List des meeting
 *
 */

$bbb_list = config_bbb($config);
load_meeting_info($config, $bbb_list, true);
?>