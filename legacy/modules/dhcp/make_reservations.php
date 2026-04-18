<?php
require ("config.inc.php");
$config = get_config();
require_once ("ldap.inc.php");
require_once ("dhcpd.inc.php");

$config['login'] = "admin";

$res = export_dhcp_reservations($config);
print($res);
?>