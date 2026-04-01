<?php
$description = "Clonage manuel avec Clonezilla";
$module = "clonezilla";
$ipxe .= "kernel " . $os_url . "/clonezilla/vmlinuz\n";
$ipxe .= "initrd --name initram.img " . $os_url . "/clonezilla/initrd.img\n";
$ipxe .= "imgargs vmlinuz initrd=initram.img boot=live config noswap nolocales edd=on nomodeset  ocs_prerun=\"\"  ocs_live_run=\"\" ocs_live_extra_param=\"\" keyboard-layouts=\"fr\"  locales=\"fr_FR.UTF-8\" vga=788 nosplash noprompt fetch=" . $os_url . "/clonezilla/filesystem.squashfs\n";
$ipxe .= "boot\n";
?>