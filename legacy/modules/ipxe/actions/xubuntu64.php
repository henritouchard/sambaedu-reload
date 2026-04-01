<?php
$ipxe .= "kernel  ubuntu-installer/amd64/linux\n";
$ipxe .= "initrd ubuntu-installer/amd64/initrd.gz\n";
$ipxe .= "imgargs linux auto=true locale=fr_FR keymap=fr netcfg/dhcp_timeout=60 netcfg/get_hostname=poste netcfg/get_domain=${se3domain} preseed/url=install/preseed_xubuntu.cfg initrd=ubuntu-installer/amd64/initrd.gz\n";
?>