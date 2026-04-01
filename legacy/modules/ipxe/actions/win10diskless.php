<?php
header("Content-type: text/plain");
$description = "Boot Windows 10 client leger ISCSI";
$module = "client_windows";

// cible iscsi
// $ipxe .= "ifopen net0\n";
// $ipxe .= "dhcp\n";
$ipxe .= "set net0/gateway 0.0.0.0\n";
// $ipxe .= "set net0/keep-san 1\n";
$iscsi_rootfs = "iscsi:192.168.202.1:::0:iqn.2003-01.org.linux-iscsi.proxmox3.x8664:sn.f8bfcf8bf214";
$ipxe .= "sanboot --drive 0x80 " . $iscsi_rootfs . "\n";
$ipxe .= "boot\n";
?>