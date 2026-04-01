<?php
// boot debian 64 standard pour serveur FS de site
// passage des parametres au genarateur preseed
$os = "debian";
$type = "se4fs";
$url = $script_url . "/linux/preseed.php?mac=" . $mac . "&uuid=" . $uuid . "&os=" . $os . "&type=" . $type . "&mask=\${net0/netmask}&gateway=\${net0/gateway}";
$ip = slave_ip($config, $_SERVER['REMOTE_ADDR'], $type);

// boot legacy et uefi
$ipxe .= "kernel " . $os_url . "/debian-installer/amd64/linux ";
$ipxe .= "initrd=initrd.gz auto=true hostname=" . $machine['cn'] . " ";
$ipxe .= "netcfg/disable_autoconfig=true  netcfg/choose_interface=ens18 netcfg/get_hostname=" . $machine['cn'] . " ";
$ipxe .= "netcfg/dhcp_options=Configure network manually netcfg/get_ipaddress=" . $ip . "   netcfg/get_netmask=\${net0/netmask} netcfg/get_gateway=\${net0/gateway} ";
$ipxe .= "netcfg/get_nameservers=" . $config['se4ad_ip'] . "   netcfg/confirm_static=true priority=critical auto url=" . $url . "\n";
$ipxe .= "initrd --name initrd.gz " . $os_url . "/debian-installer/amd64/initrd.gz\n";
$ipxe .= "boot\n";
?>