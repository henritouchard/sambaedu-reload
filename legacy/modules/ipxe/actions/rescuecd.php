<?php
$description = "Clonage automatique avec sysrescueCD 6";
$module = "clonage";
$autorun_url = $script_url . "/sysrescuecd/autorun.php?mac=" . $mac . "&uuid=" . $uuid;

$ipxe .= "kernel " . $os_url . "/sysresccd/boot/x86_64/vmlinuz initrd=initram.igz ip=dhcp copytoram nofirewall archisobasedir=sysresccd archiso_http_srv=" . $os_url . "/ checksum rootpass=" . $config['se4install_passwd'] . " setkmap=fr  ar_source=" . $autorun_url . " ar_attempts=5 ar_suffixes=no ar_nodel\n";
$ipxe .= "initrd --name intel_ucode.img " . $os_url . "/sysresccd/boot/intel_ucode.img\n";
$ipxe .= "initrd --name amd_ucode.img " . $os_url . "/sysresccd/boot/amd_ucode.img\n";
$ipxe .= "initrd --name initram.igz " . $os_url . "/sysresccd/boot/x86_64/sysresccd.img\n";
$ipxe .= "boot\n";
?>