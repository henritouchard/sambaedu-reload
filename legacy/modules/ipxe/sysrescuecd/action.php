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
// require "templates.inc.php";

$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$name = $_POST['name'] ?? $_GET['name'] ?? "";
$message = $_POST['message'] ?? "";
$in = $_POST['in'] ?? ""; // demande de telechargement
// $pas = $_POST['etape'] ?? - 1; // etape du script
$ret = $_POST['ret'] ?? ""; // code sortie du script
$progress = $_POST['progress'] ?? ""; // avancement
if ($in == 1) {
    header("Content-type: application/x-binary");
} else {
    header("Content-type: text/plain");
}

if (auth_action($config, $name, $uuid)) {
    $machine = get_action($config, $uuid);
    $ou = ldap_dn2oudn($machine['dn']);
    $type = $machine['action']['type'];
    $role = $machine['action'][$uuid]['role'] ?? "";
    $pas = $machine['action'][$uuid]['ret'] ?? -1;
    $script = $machine['action'][$uuid]['script'] ?? "default";
    $etape = $machine['action'][$uuid]['etape'] ?? $_POST['etape'] ?? "default";
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
etape=\"" . $etape . "\"
echo \"script : \${etape}\"
";
    $footer = "# in\n";
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
    $wait = "echo \"attente de \$etape...\"; sleep 2\n";
    $curlargs = "-F 'name=" . $name . "' -F 'uuid=" . $uuid . "' " . $config['ipxe_url'] . "/sysrescuecd/action.php\n";
    if ($ret == 0) {
        // le client a correctement fait son travail, on génère le script suivant
        $bash = $header;
        switch ($etape) {
            case 'default':
                $bash .= "exit 0\n";
                break;
            // /////// clonage////////////////////////////////////////////////:
            case 'rescuecd':
            case 'sysprep':
                $bash .= "mkdir -p /root/.ssh\n";
                $bash .= "echo =  " . $config['se4_pub_key'] . " > /root/.ssh/authorized_keys\n";
                if ($role == "modele") {
                    set_action($config, $name, [
                        'etape' => "init-modele",
                        'ret' => -1
                    ]);
                    // $bash .= "curl -o /tmp/udp-sender " . $config['ipxe_url'] . "/sysrescuecd/udp-sender\n";
                    // $bash .= "chmod u+x /tmp/udp-sender\n";
                } else {
                    set_action($config, $name, [
                        'etape' => "init-clone",
                        'ret' => -1
                    ]);
                    // $bash .= "curl -o /tmp/udp-receiver " . $config['ipxe_url'] . "/sysrescuecd/udp-receiver\n";
                    // $bash .= "chmod u+x /tmp/udp-receiver\n";
                }
                break;

            // /////// modeles/////////////////////////////////////////////////:
            case 'init-modele':
                $file = "/var/autorun/tmp/progress";
                $bash .= "curl -s -o " . $file . " -F \"id=" . $id . "\" " . $config['ipxe_url'] . "/sysrescuecd/progress.php\n";
                $bash .= "chmod u+x " . $file . "\n";
                $bash .= $file . "& >/dev/null 2>&1\n";
                set_action($config, $name, [
                    'etape' => "send-partitions",
                    'ret' => -1
                ]);
                break;
            case 'send-partitions':
                if (@$_FILES['out']['size'] > 0) {
                    // $bash .= "echo \"" . print_r($_FILES['out']) . "\"\n";
                    $disks = get_disks($config, file_get_contents($_FILES['out']['tmp_name']));
                    // $bash .= "echo \"" . print_r(file_get_contents($_FILES['out']['tmp_name'])) . "\"\n";
                    $bash .= "echo \"upload liste partitions\"\n";
                    $bash .= $wait;
                    set_action($config, $name, [
                        'etape' => "send-sfdisk",
                        'disks' => $disks,
                        'ret' => -1
                    ]);
                } else {
                    $bash .= "lsblk -n -p -b -o KNAME,FSTYPE > /tmp/partitions.txt\n";
                    $bash .= "curl -F 'ret=0' -F 'out=@/tmp/partitions.txt' ";
                    $bash .= $curlargs;
                    increment_action($config, $name);
                }
                break;
            case 'send-sfdisk':
                $bash .= "echo \" etape : " . $pas . "\"\n";
                $devs = get_clone_disks($disks);
                if (@$_FILES['out']['size'] > 0) {
                    $file = sys_get_temp_dir() . "/" . md5($id) . "-" . $pas . ".txt";
                    move_uploaded_file($_FILES['out']['tmp_name'], $file);
                    set_disk_size($disks, file_get_contents($file));
                    $bash .= "echo \"upload  : " . $file . "\"\n";
                    $bash .= $wait;
                    set_action($config, $name, [
                        'disks' => $disks,
                        'ret' => -1
                    ]);
                }
                if (++$pas < count($devs)) {
                    $dev = $devs[$pas];
                    $bash .= "sfdisk -d $dev > /tmp/sfdisk.txt\n";
                    $bash .= "curl -F 'ret=0' -F 'out=@/tmp/sfdisk.txt' ";
                    $bash .= $curlargs;
                    increment_action($config, $name);
                } else {
                    $bash .= $wait;
                    set_action($config, $name, [
                        'etape' => "send-mbr",
                        'ret' => -1
                    ]);
                }
                break;
            case 'send-mbr':
                $bash .= "echo \" etape : " . $pas . "\"\n";
                $devs = get_clone_disks($disks);
                if (@$_FILES['out']['size'] > 0) {
                    $file = sys_get_temp_dir() . "/" . md5($id) . "-" . $pas . ".mbr";
                    move_uploaded_file($_FILES['out']['tmp_name'], $file);
                    $bash .= "echo \"upload  : " . $file . "\"\n";
                    $bash .= $wait;
                }
                if (++$pas < count($devs)) {
                    $dev = $devs[$pas];
                    $bash .= "dd if=" . $dev . " of=/tmp/mbr.bin bs=1M count=1\n";
                    $bash .= "curl -F 'ret=0' -F 'out=@/tmp/mbr.bin' ";
                    $bash .= $curlargs;

                    increment_action($config, $name);
                } else {
                    $dev = get_clone_parts($disks)[0];
                    $bash .= $wait;
                    set_action($config, $name, [
                        'etape' => "emit-partitions",
                        'ret' => -1
                    ]);
                }
                break;
            case 'emit-partitions':
                // sleep(10);
                $devs = get_clone_parts($disks);
                if (++$pas < count($devs)) {
                    $dev = $devs[$pas]['device'];
                    $fs = $devs[$pas]['fs'];
                    $compression = "gzip";
                    $port_courant = get_clone_port($id);
                    /*
                     * $pret = true;
                     * $nb = $p = 0;
                     * // on teste que tous les membre à cloner sont prêts. Ils doivent être à :
                     * // - etape n+1 si ils ont déjà démarré
                     * foreach (fetch_action()[$id] as $membre) {
                     * if (! empty($membre['role'])) {
                     * if ($membre['role'] == 'clone') {
                     * $nb ++;
                     * // $mp = (($membre['script'] == "receive-partitions") && (($membre['ret'] == $pas) || ($membre['ret'] == ($pas + 1))));
                     * $mp = (($membre['script'] == "receive-partitions") && ($membre['ret'] == $pas));
                     * if ($mp)
                     * $p ++;
                     * $pret = ($pret && $mp);
                     * }
                     * }
                     * }
                     * // il faut qu'au moins un clone ait été défini, sinon on attend
                     * $pret = $pret && ($nb > 0);
                     */
                    $pret = wait_clone($id);
                    if ($pret['pret'])
                        $m = "pret";
                    else
                        $m = "non";

                    $bash .= "echo \"emission : $m  partition : " . $dev . " clones : " . $pret['clones'] . "\"\n";

                    if ($pret['pret']) {
                        set_statut($id, "clonage de " . $dev . " en cours");
                        // $bash .= "partclone." . $fs . " -c -s " . $dev . " 2> >(tee /var/autorun/tmp/progress.log >(cat 1>&2)) | /tmp/udp-sender --portbase $port_courant --interface \$my_interface --autostart 10 --nokbd " . $compress[$compression] . "\n";
                        $bash .= "partclone." . $fs . " -c  -s " . $dev . " | /usr/bin/udp-sender --portbase $port_courant --interface \$my_interface --autostart 5  --nokbd " . $compress[$compression] . "\n";
                        increment_action($config, $name);
                    } else {
                        set_statut($id, "clonage de $dev en attente " . $pret['clones']);
                        $bash .= $wait;
                    }
                } else {
                    $bash .= "echo \"clonage de " . $dev . " terminé !\"\n";
                    set_statut($id, "clonage de " . $dev . " terminé");
                    $bash .= $wait;

                    set_action($config, $name, [
                        'etape' => "change-hostname",
                        'ret' => -1
                    ]);
                }
                break;
            // //////////////////////////////////////////////////////
            case 'change-hostname':
                $devs = get_clone_parts($disks);
                if (++$pas < count($devs)) {
                    $dev = $devs[$pas]['device'];
                    $fs = $devs[$pas]['fs'];
                    if (($fs == 'ntfs') || ($fs == 'ext4')) {
                        $bash .= "echo \"Changement du hostname sur " . $dev . "\"\n";
                        if ($type == "clonage") {
                            $specialize_cmd = "rm -f /mnt/system/Windows/autorun.cmd
curl -o /mnt/system/Windows/Panther/unattend.xml -F 'name=" . $name . "' -F 'uuid=" . $uuid . "' " . $config['ipxe_url'] . "/Win10/sysprep.xml.php || exit 1 && umount /mnt/system";
                        } elseif ($type == "clonage2") {
                            $specialize_cmd = "rm -f /mnt/system/Windows/autorun.cmd
curl --fail -o /mnt/system/Windows/autorun.cmd -F 'name=" . $name . "' -F 'uuid=" . $uuid . "' -F 'etape=join' " . $config['ipxe_url'] . "/Win10/action.php || exit 1 && umount /mnt/system";
                        }
                        $changehostname = array(
                            'ext4' => "mkdir -p /mnt/system && mount $dev /mnt/system || exit 1
echo $name > /mnt/system/etc/hostname || true
umount /mnt/system\n",
                            // 'ntfs' => "mkdir -p /mnt/system && mount.ntfs-3g $dev /mnt/system && sed -i 's!<ComputerName>.*</ComputerName>!<ComputerName>" . $name . "</ComputerName>!;s!<MachineObjectOU>.*</MachineObjectOU>!<MachineObjectOU>" . $ou . "</MachineObjectOU>!' /mnt/system/Windows/Panther/unattend.xml && umount /mnt/system\n"
                            'ntfs' => "mkdir -p /mnt/system && mount -o remove_hiberfile $dev /mnt/system || exit 1
" . $specialize_cmd . " \n",
                        );

                        $bash .= $changehostname[$fs];
                        set_statut($id, "Changeemnt du hostname sur " . $dev);
                    } elseif ($fs == "vfat" || $fs == "efi" || $fs == "fat32") {
                        // on fait une entrée dans la nvram uefi
                        //$bash .= "efibootmgr -D -c -d " . get_clone_disks($disks)[0] . " -L Windaube -l \\EFI\\Microsoft\\Boot\\bootmgfw.efi || true\n";
                    }
                    increment_action($config, $name);
                } else {
                    set_statut($id, "Reconfiguration de $name terminée !");
                    if ($role == "modele" && $machine['action']['keep']) {
                        set_action($config, $name, [
                            'etape' => "init-modele",
                            'script' => "rescuecd",
                            'ret' => -1
                        ]);
                    } else {
                        if ($type == "clonage") {
                            set_action($config, $name, [
                                'type' => $type,
                                'role' => "windows",
                                'script' => "default",
                                'etape' => "specialize",
                                'ret' => -1
                            ]);
                        } elseif ($type == "clonage2") {
                            set_action($config, $name, [
                                'type' => $type,
                                'role' => "windows",
                                'script' => "default",
                                'etape' => "join",
                                'ret' => -1
                            ]);
                        }
                    }
                    $bash .= $reboot;
                }
                break;
            /*
             * case 'verify-hostname':
             * $devs = get_clone_parts($disks);
             * if (++ $pas < count($devs)) {
             * $dev = $devs[$pas]['device'];
             * $fs = $devs[$pas]['fs'];
             * if (($fs == 'ntfs') || ($fs == 'ext4')) {
             * $verifyhostname = array(
             * 'ext4' => "mkdir -p /mnt/system && mount $dev /mnt/system && grep ".$name." /mnt/system/etc/hostname || exit 1 && umount /mnt/system \n",
             * // 'ntfs' => "mkdir -p /mnt/system && mount.ntfs-3g $dev /mnt/system && sed -i 's!<ComputerName>.*</ComputerName>!<ComputerName>" . $name . "</ComputerName>!;s!<MachineObjectOU>.*</MachineObjectOU>!<MachineObjectOU>" . $ou . "</MachineObjectOU>!' /mnt/system/Windows/Panther/unattend.xml && umount /mnt/system\n"
             * 'ntfs' => "mkdir -p /mnt/system && mount -o remove_hiberfile $dev /mnt/system
             * curl -o /mnt/system/Windows/Panther/unattend.xml -F 'name=" . $name . "' -F 'uuid=" . $uuid . "' " . $config['ipxe_url'] . "/Win10/sysprep.xml.php
             * grep ".$name." /mnt/system/Windows/Panther/unattend.xml || exit 1 && umount /mnt/system \n"
             * );
             * $bash .= $verifyhostname[$fs];
             * }
             * increment_action($config, $name);
             * } else {
             * set_statut($id, "Terminé !");
             * $bash .= $reboot;
             * //set_action($config, $name, "default", "", "", "", "default");
             * }
             * break;
             */
            // ///////////clones///////////////////////////////////////////
            case 'init-clone':
                if (empty($disks)) {
                    $bash .= $wait;
                } else {
                    set_action($config, $name, [
                        'etape' => "receive-mbr",
                        'ret' => -1
                    ]);
                }
                break;
            case 'receive-mbr':
                $devs = get_clone_disks($disks);
                //trigger_error("attente de " . md5($id));
                if ($in == 1) {
                    $file = sys_get_temp_dir() . "/" . md5($id) . "-" . $pas . ".mbr";
                    if (file_exists($file)) {
                        echo file_get_contents($file);
                        exit();
                    } else {
                        $bash .= $wait;
                    }
                } else {
                    if ((1 + $pas) < count($devs)) {
                        $file = sys_get_temp_dir() . "/" . md5($id) . "-" . ($pas + 1) . ".mbr";
                        if (file_exists($file)) {
                            $pas++;
                            $file = "/tmp/mbr.bin";
                            $dev = $devs[$pas];
                            $bash .= "curl -o " . $file . " -F 'ret=0' -F 'in=1' " . $curlargs . "\n";
                            $bash .= "dd of=" . $dev . " if=" . $file . " bs=1M count=1\n";
                            increment_action($config, $name);
                        } else {
                            // on attend le fichier
                            $bash .= $wait;
                        }
                    } else {
                        $bash .= $wait;
                        set_action($config, $name, [
                            'etape' => "receive-sfdisk",
                            'ret' => -1
                        ]);
                    }
                }
                break;
            case 'receive-sfdisk':
                $devs = get_clone_disks($disks);
                if ($in == 1) {
                    $file = sys_get_temp_dir() . "/" . md5($id) . "-" . $pas . ".txt";
                    if (file_exists($file)) {
                        echo file_get_contents($file);
                        exit();
                    } else {
                        $bash .= $wait;
                    }
                } else {
                    if ((1 + $pas) < count($devs)) {
                        $file = sys_get_temp_dir() . "/" . md5($id) . "-" . ($pas + 1) . ".txt";
                        if (file_exists($file)) {
                            $pas++;
                            $file = "/tmp/sfdisk.txt";
                            $dev = $devs[$pas];
                            $bash .= "curl -o " . $file . " -F 'ret=0' -F 'in=1' " . $curlargs . "\n";
                            $bash .= "sfdisk < " . $file . " " . $dev . "\n";
                            increment_action($config, $name);
                        } else {
                            // on attend le fichier
                            $bash .= $wait;
                        }
                    } else {
                        $bash .= $wait;
                        set_action($config, $name, [
                            'etape' => "receive-partitions",
                            'ret' => -1
                        ]);
                    }
                }
                break;
            case 'receive-partitions':
                $devs = get_clone_parts($disks);
                if (++$pas < count($devs)) {
                    $dev = $devs[$pas]['device'];
                    $fs = $devs[$pas]['fs'];
                    $compression = "gzip";
                    $port_courant = get_clone_port($id);
                    $pret = true;
                    $p = $nb = 0;
                    $mp = $m = false;
                    foreach (fetch_action()[$id] as $membre) {
                        if (! empty($membre['role'])) {
                            if ($membre['role'] == 'clone') {
                                $nb++;
                                $mp = (($membre['etape'] == "receive-partitions") && ($membre['ret'] == $pas));
                            } else {
                                $m = (($membre['etape'] == "emit-partitions") && ($membre['ret'] == $pas));
                            }
                            if ($mp)
                                $p++;
                            $pret = $pret && ($mp || $m);
                        }
                    }
                    if ($m)
                        $m = "pret";
                    else
                        $m = "non";
                    $bash .= "echo \"partition : " . $dev . " clones : " . $p . " sur " . $nb . " modele : " . $m . "\"\n";
                    // on démarre sans attendre l'émetteur, c'est pas nécessaire
                    $bash .= "/usr/bin/udp-receiver --portbase $port_courant --interface \$my_interface --nokbd " . $decompress[$compression] . " | partclone.restore -o " . $dev . "\n";
                    if ($fs == 'ntfs') {
                        $bash .= "partclone.ntfsreloc -w " . $dev . "\n";
                    }
                    increment_action($config, $name);
                } else {
                    $bash .= $wait;
                    set_action($config, $name, [
                        'etape' => "change-hostname",
                        'ret' => -1
                    ]);
                }
                break;
        }
        $bash .= $footer;
    } else { // if (empty($ret)) {
        if (empty($_POST['erreur'])) {
            // on envoie un script au client
            $bash = $header;
            $bash .= $wait;
            $bash .= $footer;
        } else {
            // le client a planté
            set_action($config, $name, [
                'message' => $message
            ]);
        }
    }
    echo $bash;
    file_put_contents("/tmp/clonage-$name.log", $bash . "\n", FILE_APPEND);
} else {
    echo "echo erreur $uuid $name\n";
}
// }
