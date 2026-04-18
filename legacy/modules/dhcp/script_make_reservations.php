<?php
include "config.inc.php";
require "ldap.inc.php";
require_once ("functions.inc.php");
require_once ("traitement_data.inc.php");
require_once 'admin_ui.inc.php';
$config = get_config();
header_authorize_script($config);
$config['login'] = "admin";
if ($lock = apcu_fetch("dhcp_reservations_lock")) {
    exit();
}
apcu_store("dhcp_reservations_lock", "dhcp");
$res = export_dhcp_reservations($config);
if ($res) {
    echo "ok\n";
}
apcu_delete("dhcp_reservations_lock");
?>