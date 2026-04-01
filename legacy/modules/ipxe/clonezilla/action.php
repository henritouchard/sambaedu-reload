<?php
/*
 * génération d'un script bash qui est exécuté par l'autorun sysrecuecd,
 *
 * Auteur : denis bonnenfant, d'après Stéphane Boireau
 *
 * Le script bash généré se termine en refaisant une requète sur cette page en POST
 * Le résultat est interprété et sert à générer l'action suivante.
 *
 * $_POST['name'] : nom
 * $_POST['uuid'] : action en cours
 * $_POST['out'] : sortie du script
 * $_POST['ret'] : code sortie ($?)
 * $_POST['in'] : demande d'un fichier
 *
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require "ipxe_functions.inc.php";
require "templates.inc.php";

$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$name = $_POST['name'] ?? "";
$in = $_POST['in'] ?? ""; // demande de telechargement
                           // $etape = $_POST['etape'] ?? - 1; // etape du script
$ret = $_POST['ret'] ?? $_GET['ret'] ?? ""; // code sortie du script
$progress = $_POST['progress'] ?? ""; // avancement
if ($in == 1) {
    header("Content-type: application/x-binary");
} else {
    header("Content-type: text/plain");
}

if (auth_action($config, $name, $uuid)) {
    $machine = get_action($config, $name, $uuid);
    $ou = ldap_dn2oudn($machine['dn']);
    $type = $machine['action']['type'];
    $role = $machine['action'][$uuid]['role'] ?? "";
    $etape = $machine['action'][$uuid]['ret'];
    $script = $machine['action'][$uuid]['script'] ?? "default";
    $id = $machine['id'];
    $disks = @$machine['action']['disks'];
    /*
     * foreach (fetch_action()[$id] as $membre) {
     * if ($membre['role'] == 'modele') {
     * $disks = $membre['disks'];
     * }
     * }
     */
    // commandes sysrecuecd
    $header = "#!/bin/bash
uuid=" . $uuid . "
script=\"" . $script . "\"
echo \"script : \$script\"
";
    $footer = "# fin\n";
    $compress = array(
        'lzop' => "--pipe 'lzop -c -f -'",
        'none' => "",
        'pbzip2' => "--pipe 'pbzip2 -1 -c -f -'",
        'gzip' => "--pipe 'pigz -c -f -'"
    );

    $decompress = array(
        'lzop' => "--pipe 'lzop -c -d -f -'",
        'none' => "",
        'pbzip2' => "--pipe 'pbzip2 -1 -c -d -f -'",
        'gzip' => "--pipe 'pigz -c -d -f -'"
    );
    $reboot = "shutdown -r now\n";
    $wait = "echo \"attente de \$script...\"; sleep 2\n";
    $curlargs = "-F 'name=" . $name . "' -F 'uuid=" . $uuid . "' " . $config['ipxe_url'] . "/sysrescuecd/action.php\n";

    if ($ret == 0) {
        // le client a correctement fait son travail, on génère le script suivant
        $bash = $header;
        switch ($script) {
            case 'default':
                $bash .= "exit 0\n";
                break;
            // /////// clonage////////////////////////////////////////////////:
            case 'rescuecd':
                if ($role == "modele") {
                    set_action($config, $name, "", "", "", "", "send-partitions", - 1);
                    $bash .= "curl -o /tmp/udp-sender " . $config['ipxe_url'] . "/sysrescuecd/udp-sender\n";
                    $bash .= "chmod u+x /tmp/udp-sender\n";
                } else {
                    set_action($config, $name, "", "", "", "", "init-clone", - 1);
                    $bash .= "curl -o /tmp/udp-receiver " . $config['ipxe_url'] . "/sysrescuecd/udp-receiver\n";
                    $bash .= "chmod u+x /tmp/udp-receiver\n";
                }
                break;

            // /////// modeles/////////////////////////////////////////////////:
            case 'init-modele':
                $bash .= $wait;
                set_action($config, $name, "", "", "", "", "send-partitions", - 1);
                break;
            case 'send-partitions':
                if (@$_FILES['out']['size'] > 0) {
                    // $bash .= "echo \"" . print_r($_FILES['out']) . "\"\n";
                    $disks = get_disks($config, file_get_contents($_FILES['out']['tmp_name']));
                    // $bash .= "echo \"" . print_r(file_get_contents($_FILES['out']['tmp_name'])) . "\"\n";
                    $bash .= "echo \"upload liste partitions\"\n";
                    $bash .= $wait;
                    set_action($config, $name, "", "", "", "", "send-sfdisk", - 1, $disks);
                } else {
                    $bash .= "lsblk -n -p -b -o KNAME,FSTYPE > /tmp/partitions.txt\n";
                    $bash .= "curl -F 'ret=0' -F 'out=@/tmp/partitions.txt' ";
                    $bash .= $curlargs;
                    increment_action($config, $name);
                }
                break;
            case 'send-sfdisk':
                $bash .= "echo \" etape : " . $etape . "\"\n";
                $devs = get_clone_disks($disks);
                if (@$_FILES['out']['size'] > 0) {
                    $file = sys_get_temp_dir() . "/" . md5($id) . "-" . $etape . ".txt";
                    move_uploaded_file($_FILES['out']['tmp_name'], $file);
                    $bash .= "echo \"upload  : " . $file . "\"\n";
                    $bash .= $wait;
                }
                if (++ $etape < count($devs)) {
                    $dev = $devs[$etape];
                    $bash .= "sfdisk -d $dev > /tmp/sfdisk.txt\n";
                    $bash .= "curl -F 'ret=0' -F 'out=@/tmp/sfdisk.txt' ";
                    $bash .= $curlargs;
                    increment_action($config, $name);
                } else {
                    $bash .= $wait;
                    set_action($config, $name, "", "", "", "", "send-mbr", - 1);
                }
                break;
            case 'send-mbr':
                $bash .= "echo \" etape : " . $etape . "\"\n";
                $devs = get_clone_disks($disks);
                if (@$_FILES['out']['size'] > 0) {
                    $file = sys_get_temp_dir() . "/" . md5($id) . "-" . $etape . ".mbr";
                    move_uploaded_file($_FILES['out']['tmp_name'], $file);
                    $bash .= "echo \"upload  : " . $file . "\"\n";
                    $bash .= $wait;
                }
                if (++ $etape < count($devs)) {
                    $dev = $devs[$etape];
                    $bash .= "dd if=" . $dev . " of=/tmp/mbr.bin bs=1M count=1\n";
                    $bash .= "curl -F 'ret=0' -F 'out=@/tmp/mbr.bin' ";
                    $bash .= $curlargs;

                    increment_action($config, $name);
                } else {
                    $dev = get_clone_parts($disks)[0];
                    $bash .= $wait;
                    set_action($config, $name, "", "", "", "", "emit-partitions", - 1);
                }
                break;
            case 'emit-partitions':
                // sleep(10);
                $devs = get_clone_parts($disks);
                if (++ $etape < count($devs)) {
                    $dev = $devs[$etape]['device'];
                    $fs = $devs[$etape]['fs'];
                    $compression = "gzip";
                    $port_courant = get_clone_port($id);
                    $pret = true;
                    $nb = $p = 0;
                    // on teste que tous les membre à cloner sont prêts. Ils doivent être à :
                    // - etape n+1 si ils ont déjà démarré
                    foreach (fetch_action()[$id] as $membre) {
                        if (! empty($membre['role'])) {
                            if ($membre['role'] == 'clone') {
                                $nb ++;
                                // $mp = (($membre['script'] == "receive-partitions") && (($membre['ret'] == $etape) || ($membre['ret'] == ($etape + 1))));
                                $mp = (($membre['script'] == "receive-partitions") && ($membre['ret'] == $etape));
                                if ($mp)
                                    $p ++;
                                $pret = ($pret && $mp);
                            }
                        }
                    }
                    // il faut qu'au moins un clone ait été défini, sinon on attend
                    $pret = $pret && ($nb > 0);
                    if ($pret)
                        $m = "pret";
                    else
                        $m = "non";

                    $bash .= "echo \"emission : $m  partition : " . $dev . " clones : " . $p . " sur " . $nb . "\"\n";

                    if ($pret) {

                        // $bash .= "partclone." . $fs . " -c -s " . $dev . " 2> >(tee /tmp/progress.log >(cat 1>&2)) | /tmp/udp-sender --portbase $port_courant --interface \$my_interface --autostart 10 --nokbd " . $compress[$compression] . "\n";
                        $bash .= "partclone." . $fs . " -c  -s " . $dev . " | /tmp/udp-sender --portbase $port_courant --interface \$my_interface --autostart 5  --nokbd " . $compress[$compression] . "\n";
                        increment_action($config, $name);
                    } else {
                        $bash .= $wait;
                    }
                } else {
                    $bash .= "echo \"clonage terminé !\"\n";
                    $bash .= $wait;

                    set_action($config, $name, "", "", "", "", "change-hostname", - 1);
                }
                break;
            // //////////////////////////////////////////////////////
            case 'change-hostname':
                $devs = get_clone_parts($disks);
                if (++ $etape < count($devs)) {
                    $dev = $devs[$etape]['device'];
                    $fs = $devs[$etape]['fs'];
                    if (($fs == 'ntfs') || ($fs == 'ext4')) {
                        $changehostname = array(
                            'ext4' => "mkdir -p /mnt/system && mount $dev /mnt/system && echo $name > /mnt/system/etc/hostname && umount /mnt/system\n",
                            // 'ntfs' => "mkdir -p /mnt/system && mount.ntfs-3g $dev /mnt/system && sed -i 's!<ComputerName>.*</ComputerName>!<ComputerName>" . $name . "</ComputerName>!;s!<MachineObjectOU>.*</MachineObjectOU>!<MachineObjectOU>" . $ou . "</MachineObjectOU>!' /mnt/system/Windows/Panther/unattend.xml && umount /mnt/system\n"
                            'ntfs' => "mkdir -p /mnt/system && mount -o remove_hiberfile $dev /mnt/system
curl -o /mnt/system/Windows/Panther/unattend.xml -F 'name=" . $name . "' -F 'uuid=" . $uuid . "' " . $config['ipxe_url'] . "/Win10/sysprep.xml.php || exit 1 && umount /mnt/system \n"
                        );
                        $bash .= $changehostname[$fs];
                    }
                    increment_action($config, $name);
                } else {
                    $bash .= $wait;
                    set_action($config, $name, "", "", "", "", "verify-hostname", - 1);
                }
                break;
            case 'verify-hostname':
                $devs = get_clone_parts($disks);
                if (++ $etape < count($devs)) {
                    $dev = $devs[$etape]['device'];
                    $fs = $devs[$etape]['fs'];
                    if (($fs == 'ntfs') || ($fs == 'ext4')) {
                        $verifyhostname = array(
                            'ext4' => "mkdir -p /mnt/system && mount $dev /mnt/system && grep " . $name . " /mnt/system/etc/hostname || exit 1 && umount /mnt/system \n",
                            // 'ntfs' => "mkdir -p /mnt/system && mount.ntfs-3g $dev /mnt/system && sed -i 's!<ComputerName>.*</ComputerName>!<ComputerName>" . $name . "</ComputerName>!;s!<MachineObjectOU>.*</MachineObjectOU>!<MachineObjectOU>" . $ou . "</MachineObjectOU>!' /mnt/system/Windows/Panther/unattend.xml && umount /mnt/system\n"
                            'ntfs' => "mkdir -p /mnt/system && mount -o remove_hiberfile $dev /mnt/system
curl -o /mnt/system/Windows/Panther/unattend.xml -F 'name=" . $name . "' -F 'uuid=" . $uuid . "' " . $config['ipxe_url'] . "/Win10/sysprep.xml.php
grep " . $name . " /mnt/system/Windows/Panther/unattend.xml || exit 1 && umount /mnt/system \n"
                        );
                        $bash .= $changehostname[$fs];
                    }
                    increment_action($config, $name);
                } else {
                    $bash .= $reboot;
                    // set_action($config, $name, "default", "", "", "", "default");
                }
                break;

            // ///////////clones///////////////////////////////////////////
            case 'init-clone':
                if (empty($disks)) {
                    $bash .= $wait;
                } else {
                    set_action($config, $name, "", "", "", "", "receive-mbr", - 1);
                }
                break;
            case 'receive-mbr':
                $devs = get_clone_disks($disks);
                if ($in == 1) {
                    $file = sys_get_temp_dir() . "/" . md5($id) . "-" . $etape . ".mbr";
                    if (file_exists($file)) {
                        echo file_get_contents($file);
                        exit();
                    } else {
                        $bash .= $wait;
                    }
                } else {
                    if ((1 + $etape) < count($devs)) {
                        $file = sys_get_temp_dir() . "/" . md5($id) . "-" . ($etape + 1) . ".mbr";
                        if (file_exists($file)) {
                            $etape ++;
                            $file = "/tmp/mbr.bin";
                            $dev = $devs[$etape];
                            $bash .= "curl -o " . $file . " -F 'ret=0' -F 'in=1' " . $curlargs . "\n";
                            $bash .= "dd of=" . $dev . " if=" . $file . " bs=1M count=1\n";
                            increment_action($config, $name);
                        } else {
                            // on attend le fichier
                            $bash .= $wait;
                        }
                    } else {
                        $bash .= $wait;
                        set_action($config, $name, "", "", "", "", "receive-sfdisk", - 1);
                    }
                }
                break;
            case 'receive-sfdisk':
                $devs = get_clone_disks($disks);
                if ($in == 1) {
                    $file = sys_get_temp_dir() . "/" . md5($id) . "-" . $etape . ".txt";
                    if (file_exists($file)) {
                        echo file_get_contents($file);
                        exit();
                    } else {
                        $bash .= $wait;
                    }
                } else {
                    if ((1 + $etape) < count($devs)) {
                        $file = sys_get_temp_dir() . "/" . md5($id) . "-" . ($etape + 1) . ".txt";
                        if (file_exists($file)) {
                            $etape ++;
                            $file = "/tmp/sfdisk.txt";
                            $dev = $devs[$etape];
                            $bash .= "curl -o " . $file . " -F 'ret=0' -F 'in=1' " . $curlargs . "\n";
                            $bash .= "sfdisk < " . $file . " " . $dev . "\n";
                            increment_action($config, $name);
                        } else {
                            // on attend le fichier
                            $bash .= $wait;
                        }
                    } else {
                        $bash .= $wait;
                        set_action($config, $name, "", "", "", "", "receive-partitions", - 1);
                    }
                }
                break;
            case 'receive-partitions':
                $devs = get_clone_parts($disks);
                if (++ $etape < count($devs)) {
                    $dev = $devs[$etape]['device'];
                    $fs = $devs[$etape]['fs'];
                    $compression = "gzip";
                    $port_courant = get_clone_port($id);
                    $pret = true;
                    $p = $nb = 0;
                    foreach (fetch_action()[$id] as $membre) {
                        if (! empty($membre['role'])) {
                            if ($membre['role'] == 'clone') {
                                $nb ++;
                                $mp = (($membre['script'] == "receive-partitions") && ($membre['ret'] == $etape));
                            } else {
                                $m = (($membre['script'] == "emit-partitions") && ($membre['ret'] == $etape));
                            }
                            if ($mp)
                                $p ++;
                            $pret = $pret && ($mp || $m);
                        }
                    }
                    if ($m)
                        $m = "pret";
                    else
                        $m = "non";
                    $bash .= "echo \"partition : " . $dev . " clones : " . $p . " sur " . $nb . " modele : " . $m . "\"\n";
                    // on démarre sans attendre l'émetteur, c'est pas nécessaire
                    $bash .= "/tmp/udp-receiver --portbase $port_courant --interface \$my_interface --nokbd " . $decompress[$compression] . " | partclone.restore -o " . $dev . "\n";
                    if ($fs == 'ntfs') {
                        $bash .= "partclone.ntfsreloc -w " . $dev . "\n";
                    }
                    increment_action($config, $name);
                } else {
                    $bash .= $wait;
                    set_action($config, $name, "", "", "", "", "change-hostname", - 1);
                }
                break;
        }
        $bash .= $footer;
    } elseif (empty($ret)) {
        // on envoie un script au client
        $bash = $header;
        $bash .= $wait;
        $bash .= $footer;
    }
    echo $bash;
} else {
    $ipxe .= "echo erreur $uuid $name\n";
}
?>