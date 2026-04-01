<?php
$ipxe .= "kernel  ubuntu-installer/i386/linux\n";
$ipxe .= "initrd ubuntu-installer/i386/initrd.gz\n";
$ipxe .= "imgargs linux auto=true locale=fr_FR keymap=fr netcfg/dhcp_timeout=60 netcfg/get_hostname=poste netcfg/get_domain=${se3domain} preseed/url=install/preseed_xubuntu.cfg initrd=ubuntu-installer/i386/initrd.gz\n";
?>