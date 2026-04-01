<?php
require_once ('pxe.inc.php');

$ipxe .= "set 209:string {$url}pxelinux.cfg/memtest86plus.cfg\n";
$ipxe .= "chain --replace --autofree {$url}bin/pxelinux.0\n";

?>