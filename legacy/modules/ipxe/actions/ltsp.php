<?php
/*
 * commandes à exécuter sur le clinux à transformer en serveur ltsp
 * ```
 * wget https://ltsp.org/misc/ltsp-ubuntu-ppa-focal.list -O /etc/apt/sources.list.d/ltsp-ubuntu-ppa-focal.list
 * wget https://ltsp.org/misc/ltsp_ubuntu_ppa.gpg -O /etc/apt/trusted.gpg.d/ltsp_ubuntu_ppa.gpg
 * apt update
 * apt install -y ltsp ltsp-binaries nfs-kernel-server squashfs-tools dnsmasq
 * ltsp image /
 * ltsp nfs
 * ltsp initrd
 * ltsp ipxe
 * ltsp dnsmasq -d0 -p0 -r0 -t1
 *
 * mettre dans /etc/ltsp/ltsp.conf :
 *
 * [clients]
 * FSTAB_HOME="se4fs:/home /home nfs4 defaults,nolock 0 0"
 *
 * Et c'est tout !!!
 */
$serv_ltsp = search_machine($config, $script, true)['iphostnumber'];
if (empty($serv_ltsp)) {
    $ipxe .= "chain --replace --autofree " . $url . "ltsp.php##params\n || sleep 10\n";
}
$img = "x86_64";
$ipxe .= "set cmdline_ltsp BOOTIF=01-" . $mac . " systemd.hostname=l-" . $name . " ltsp.hostname=l-" . $name . "\n";
$ipxe .= "set cmdline_method root=/dev/nfs nfsroot=" . $serv_ltsp . ":/srv/ltsp ltsp.image=images/" . $img . ".img loop.max_part=9\n";
$ipxe .= "set cmdline \${cmdline_method} \${cmdline_ltsp} \${cmdline_client}\n";

$ipxe .= "kernel tftp://" . $serv_ltsp . "/ltsp/" . $img . "/vmlinuz initrd=ltsp.img initrd=initrd.img \${cmdline}\n";
$ipxe .= "initrd  tftp://" . $serv_ltsp . "/ltsp/ltsp.img\n";
$ipxe .= "initrd  tftp://" . $serv_ltsp . "/ltsp/" . $img . "/initrd.img\n";

$ipxe .= "boot\n";
?>