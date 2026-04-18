<?php
/*
 * génerateur de commande cups pour les imprimantes des clinux
 *
 * D Bonnenfant
 * Equipe sambaedu
 *
 */
include "config.inc.php";
$config = get_config();
require_once "ldap.inc.php";
require_once "printers.inc.php";

$user = $_POST['user'] ?? $_GET['user'] ?? "";
$machine = $_POST['machine'] ?? $_GET['machine'] ?? "";
$machine = preg_replace("/^l-/", "", $machine);
$action = $_POST['action'] ?? $_GET['action'] ?? "";
$command = "#!/bin/bash\n";
foreach (list_machine_printers($config, $machine, $user) as $printer) {
    $command .= cups_client_command($config, $printer, $action);
}
header("Content-type: text/plain");
echo $command;
?>