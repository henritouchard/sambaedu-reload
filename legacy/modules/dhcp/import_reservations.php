<?php
include "config.inc.php";
require "ldap.inc.php";
require_once ("functions.inc.php");
require_once ("traitement_data.inc.php");
$config = get_config();
header_authorize_script($config);

echo import_dhcp_reservations($config);

// $html = "";
// admin_footer_html($html, false);
// echo $html;
?>