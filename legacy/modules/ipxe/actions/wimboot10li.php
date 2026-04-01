<?php
$url = "http://192.168.202.3:909/ipxe/";

// cible iscsi
// $ipxe .= "ifopen net0\n";
// $ipxe .= "dhcp\n";
$ipxe .= "set net0/gateway 0.0.0.0\n";
$ipxe .= "echo IP: \${net0/ip}, Gateway: \${net0/gateway}\n";
// $ipxe .= "set net0/keep-san 1\n";
// $ipxe .= "echo keep-san: \${net0/keep-san}\n";
$iscsi_rootfs = "iscsi:192.168.202.1:::0:iqn.2003-01.org.linux-iscsi.proxmox3.x8664:sn.f8bfcf8bf214";
$ipxe .= "sanhook --drive 0x80 " . $iscsi_rootfs . "\n";
$iscsi_cdrom = "iscsi:192.168.202.1:::1:iqn.2003-01.org.linux-iscsi.proxmox3.x8664:sn.f8bfcf8bf214";
$ipxe .= "sanboot --drive 0x81 " . $iscsi_cdrom . "\n";
$ipxe .= "exit\n";
?>