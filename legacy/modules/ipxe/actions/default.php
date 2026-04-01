<?php
$description = "Boot sur le disque dur (action par défaut)";
$module = "";

$ipxe .= "echo Demarrage sur les disques locaux...\n";
$ipxe .= "echo Boot Disque 1...\n";
$ipxe .= boot_disk();
?>