<?php
/*
 * génération d'un fichier pour diskpart,
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once 'ipxe_functions.inc.php';
require "windows.inc.php";

header("Content-type: text/plain");
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";
$attrs['bios'] = $_POST['bios'] ?? $_GET['bios'] ?? "legacy";
$attrs['version'] = $_POST['version'] ?? $_GET['version'] ?? "Win11";
$disk = $_POST['disk'] ?? 0;
$perso = $_POST['perso'] ?? 0;
$attrs['join'] = ($perso == 0);
if (auth_action($config, $mac, $uuid)) {
    $txt = "select disk O\r
select partition 1\r
assign letter=U\r
";
    echo $txt;
}
