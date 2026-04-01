<?php
// boot image nird
// passage des parametres au genarateur preseed
$os = "debian";
$module = "nird";
$url = $script_url . "/linux/preseed.php?mac=" . $mac . "&uuid=" . $uuid . "&os=" . $os . "&type=nird&perso=1";
// boot legacy et uefi
$ipxe .= "kernel " . $os_url . "/nird/casper/vmlinuz\n";
$ipxe .= "initrd --name initrd.gz " . $os_url . "/nird/casper/initrd.gz\n";
//$ipxe .= "initrd --name filesystem.squashfs " . $os_url . "/nird/casper/filesystem.squashfs\n";
//$ipxe .= "imgargs linux initrd=initrd.gz auto=true hostname=" . $machine['cn'] . " priority=critical auto url=" . $url . "\n";
$ipxe .= "imgargs vmlinuz initrd=initrd.gz root=/dev/nfs boot=casper netboot=nfs nfsroot=" . $config['se4fs_ip'] . ":/var/sambaedu/unattended/install/os/nird root ip=dhcp auto=true hostname=" . $machine['cn'] . " priority=critical auto url=" . $url . "\n";
$ipxe .= "boot\n";
