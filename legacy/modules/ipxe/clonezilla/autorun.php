<?php
/*
 * génération d'un fichier autorun qui lancera les actions sur sysrecuecd, récupere le nom et le uuid en GET
 * execute en boucle le script généré par sysrecuecd/action.php
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require "ipxe_functions.inc.php";

header("Content-type: text/plain");
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$name = $_POST['mac'] ?? $_GET['mac'] ?? "";
// sysrecuecd ajoute /autorun en fin d'adresse car c'est le nom du script !
$uuid = explode("/", $uuid)[0];

if (auth_action($config, $name, $uuid)) {
    $machine = get_action($config, $name, $uuid);

    $autorun = "#!/bin/bash\n";
    $autorun .= "echo 'name: $name, uuid: $uuid'\n";
    $autorun .= file_get_contents("/usr/share/sambaedu/includes/utils.inc.sh");
    $autorun .= "
my_network
i=0
ret=-1
while true;
do
    # on récupere le script a exécuter
    scripts=$(curl -F \"uuid=" . $uuid . "\" -F \"name=" . $machine['cn'] . "\" -F \"ret=\${ret}\"  -s \"" . $config['ipxe_url'] . "sysrescuecd/action.php\")
    eval \"\$scripts\"
    ret=\$?
    if [ \"\$ret\" -eq \"0\" ];
    then
#       echo \"machine : " . $machine['cn'] . " action :" . $machine['action'][$uuid]['script'] . " numéro \$i OK\"
        i=$((i+1))
    else
        echo \"machine : " . $machine['cn'] . " action :" . $machine['action'][$uuid]['script'] . " numéro \$i ERREUR : \$ret\"
        exit 1
    fi
    sleep 1
done
";
} else {
    $autorun = "#!/bin/bash\n";
    $autorun .= "echo 'name: $name, uuid: $uuid'\n";
    $autorun .= file_get_contents("/usr/share/sambaedu/includes/utils.inc.sh");
    $autorun .= "
echo 'pas d action programmées pour cette machine!'
exit 1
";
}
echo $autorun;
?>