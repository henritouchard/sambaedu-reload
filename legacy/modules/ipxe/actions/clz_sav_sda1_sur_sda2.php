<?php
$description = "Clonage automatique avec Clonezilla";
$module = "clonezilla";
$ocs_live_batch = $script_url . "/clonezilla/autorun.php?mac=" . $mac . "&uuid=" . $uuid;

$ipxe .= "kernel " . $os_url . "/clonezilla/vmlinuz initrd=initram.igz boot=live config noswap nolocales edd=on nomodeset ocs_prerun=\"mount -t auto /dev/sda2 /home/partimag/\"  ocs_live_run=\"ocs-sr -q2  -j2 -z1 -i 2000 -fsck-src-part -p reboot saveparts savesda1 sda1\" ocs_live_extra_param=\"\" keyboard-layouts=\"fr\" ocs_live_batch=\"no\" locales=\"fr_FR.UTF-8\" vga=788 nosplash noprompt fetch=" . $os_url . "/clonezilla/filesystem.squashfs\n";

$ipxe .= "initrd --name initram.igz " . $os_url . "/clonezilla/initrd.img\n";
$ipxe .= "boot\n";
?>