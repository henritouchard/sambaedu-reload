<?php
// boot debian 64 standard
// passage des parametres au genarateur preseed
$os = $config['version_debian'];
$type = "base";
$url = $script_url . "/linux/preseed.php?mac=" . $mac . "&uuid=" . $uuid . "&os=" . $os . "&type=" . $type;
// boot legacy et uefi
$ipxe .= "kernel " . $os_url . "/debian-installer/amd64/linux\n";
$ipxe .= "initrd --name initrd.gz " . $os_url . "/debian-installer/amd64/initrd.gz\n";
$ipxe .= "imgargs linux initrd=initrd.gz auto=true hostname=" . $machine['cn'] . " priority=critical auto url=" . $url . "\n";
$ipxe .= "boot\n";
?>