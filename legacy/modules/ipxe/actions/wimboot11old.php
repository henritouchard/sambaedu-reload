<?php
/*
 * description: Installation auto de Windows 11 version precedente
 * module: client_windows
 */
$version = "Win11-old";
$ipxe .= "kernel Win10/wimboot\n";
$ipxe .= "initrd --name winpeshl.ini Win10/winpeshl.ini winpeshl.ini\n";
$ipxe .= "params\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param mac " . $mac . "\n";
$ipxe .= "param debug " . $debug . "\n";
$ipxe .= "param version " . $version . "\n";
$ipxe .= "param action " . $action . "\n";
$ipxe .= "iseq \${platform} efi && param bios uefi || param bios legacy\n";
$ipxe .= "initrd --name install.bat Win10/install.bat.php##params install.bat\n";
$ipxe .= "params\n";
$ipxe .= "param uuid " . $uuid . "\n";
$ipxe .= "param mac " . $mac . "\n";
$ipxe .= "param action " . $action . "\n";
$ipxe .= "param version " . $version . "\n";
$ipxe .= "param disk " . $disk . "\n";
$ipxe .= "param perso " . $perso . "\n";
// $ipxe .= "param bios legacy\n";
$ipxe .= "iseq \${platform} efi && param bios uefi || param bios legacy\n";
$ipxe .= "initrd --name unattend.xml Win10/unattend.xml.php##params unattend.xml\n";
$ipxe .= "initrd --name BCD " . $version . "/boot/bcd BCD\n";
$ipxe .= "initrd --name boot.sdi " . $version . "/boot/boot.sdi boot.sdi\n";
$ipxe .= "initrd --name boot.wim " . $version . "/sources/boot.wim  boot.wim\n";
$ipxe .= "boot\n";
?>