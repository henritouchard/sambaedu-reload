<?php

/**
 * Stub dhcpd.inc.php — empêche le chargement du vrai legacy dhcpd.inc.php.
 *
 * Notre shim (legacy/dhcp_shim.inc.php) est déjà chargé via le bootstrap
 * et fournit valid_mac, format_mac, export_dhcp_reservations, etc.
 *
 * Le legacy dhcpd.inc.php déclare valid_mac() sans guard function_exists,
 * ce qui provoquerait une fatal "Cannot redeclare valid_mac()" si les
 * modules DHCP faisaient require_once("dhcpd.inc.php"). Ce stub intercepte
 * la résolution (stubs/ est prioritaire dans include_path).
 */

require_once __DIR__ . '/../dhcp_shim.inc.php';
