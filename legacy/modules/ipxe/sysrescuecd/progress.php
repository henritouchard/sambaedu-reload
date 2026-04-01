<?php
/*
 * génération d'un script bash pour envoyer la progreession du clonage au serveur
 * et stockage des informations revoyées dans une vartiable prmanente apcu
 *
 * L'avancement d'udpcast est loggué dans /var/autorun/log/erreur
 * 
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require "ipxe_functions.inc.php";

$progress = $_POST['progress'] ?? "";
$id = $_POST['id'] ?? false;

if ($id) {
    if (! empty($progress)) {
        // remontée des infos
        echo "progress: " . $progress . "\n";
        set_progress($id, $progress);
    } else {
        // header("Content-type: text/plain");
        echo "#!/bin/bash
while true
do
    if [ -f \"/var/autorun/log/autorun\" ]; then
        tail /var/autorun/log/autorun | tr '[\\r\\033]' '\\n' | grep Completed: | tail -1 > /var/autorun/log/progress
        progress=$(cat /var/autorun/log/progress)
    fi
    curl -s -F \"progress=\$progress\" -F \"id=" . $id . "\" " . $config['ipxe_url'] . "/sysrescuecd/progress.php >> /var/autorun/log/res
    sleep 5
done";
    }
}
?>