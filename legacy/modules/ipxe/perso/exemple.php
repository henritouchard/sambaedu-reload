<?php
$ipxe .= "item  deb_lxde Installation de Debian automatisee (LXDE)\n";
$ipxe .= ":deb_lxde\n";
$ipxe .= "param  action deb_lxde\n";
$ipxe .= "chain --replace --autofree action.php##params\n";