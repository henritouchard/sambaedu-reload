<?php

/**

 * rafraichissement des enregistrements dns
 * 
 * @Projet SambaEdu

 * @auteurs denis bonnenfant

 * @Licence Distribue selon les termes de la licence GPL
 *
 */
include "config.inc.php";
require "ldap.inc.php";
require_once("functions.inc.php");
require_once("traitement_data.inc.php");
$config = get_config();
header_authorize_script($config);
include "ihm.inc.php";
require_once("ldap.inc.php");
require_once 'ent.inc.php';
require "cloud.inc.php";

$action = $_POST['action'] ?? "";
$name = strtolower($_POST['name']) ?? "";
$ip = $_POST['ip'] ?? "";
$html = "";
switch ($action) {
    case "add":
        $res = dns_add($config, $name, $ip, false, $html);
        // $res &= dns_add_ptr($config, $name, $ip);
        break;
    case "delete":
        $res = dns_delete($config, $name, $ip, $html);
        // $res &= dns_delete_ptr($config, $ip);
        break;
}
echo $html;
/*
 * if ($res){
 * echo $action . " " . $name . " " . $ip . " : dns mis à jour.\n";
 * } else {
 * echo $action . " " . $name . " " . $ip . " : dns pas mis à jour.\n";
 * }
 */
