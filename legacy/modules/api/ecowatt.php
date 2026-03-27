<?php
/*
 * relaie le signal ecowatt aux serveurs sambaedu
 */
require_once "config.inc.php";
$config = get_config();
require_once "ldap.inc.php";
require_once ("power.inc.php");

header("Content-Type: text/json");
echo json_encode(relay_ecowatt($config));