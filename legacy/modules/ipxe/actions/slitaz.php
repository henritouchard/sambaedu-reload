<?php
$menu_timeout = '10000';
require_once ('../params.php');
$ipxe .= "kernel bzImage \n";
$ipxe .= "initrd rootfs.gz \n";
$ipxe .= "imgargs bzImage rw root=/dev/null lang=fr_FR kmap=fr-latin1 vga=normal\n";
$ipxe .= "boot\n";
?>