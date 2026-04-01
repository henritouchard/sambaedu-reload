<?php
require_once ('params.php');

$ipxe .= "set 209:string {$url}pxelinux.cfg/hdt.cfg\n";
$ipxe .= "chain --replace --autofree {$url}bin/pxelinux.0\n";

?>