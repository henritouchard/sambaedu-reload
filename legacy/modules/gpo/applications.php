<?php
/*
 * génération d'un fichier applications-$action.cmd, qui est lancé depuis la gpo "se4_applications" qui execute un script au startup/logon/logoff/shutdown
 *
 * curl.exe -F "os=windows" -F "action=$action" -F "user=%username%" -F "machine=%computername%" "http://###_SE4FS_NAME_###/gpo/applications.php">%windir%\applications-$action.cmd
 * if exist %windir%\applications-$action.cmd (call %windir%\applications-$action.cmd)
 *
 * on passe le nom de la machine le script et user, et on récupère un fichier cmd ou bash qui est exécuté au boot ou a l'ouverture de session
 *
 */
// génération des scripts à exécuter pour la configuration des applications.
// on génere un script à partir des modèles dans /etc/sambaedu/applications/* (personnalisations locales) ou /usr/share/sambaedu/applications/* (scripts de la distrib). Le local est prioritaire.
// windows : $action.windows
// linux : $action.linux
// les modèles peuvent contenir des substitutions de variables de conf : ###_PARAMETRE_###, ils seront remplacés automatiquement
// pour les scripts dans /etc/ il est possible de passer un nom, groupe, parc, machine : startup@parc.linux par exemple
// bien evidemment seuls les parcs seront pris en compte lors du statup, vu qu'il n'y a pas de user à ce stade.
//
// Les scripts générés sont typiquement utilisés pour DL des fichiers de conf, et le copier au bon endroit
//
require "config.inc.php";
$config = get_config();
require_once("traitement_data.inc.php");
require "ldap.inc.php";
require "logs.inc.php";
require "remote.inc.php";
require "applications.inc.php";
require "cloud.inc.php";

$ret = $_POST['ret'] ?? $_GET['ret'] ?? "1";

$info = get_app_scripts_info($config);
if (! empty($info) && log_application_scripts($config, $info, $ret)) {
    $scripts = read_application_scripts($config);
    $out = make_application_scripts($config, $info, $scripts);
    echo $out[$info['interpreter']];
    // debug
    if (empty($info['user']['cn']) || $info['user']['cn'] == "nobody"){
        $info['user']['cn'] = $info['machine']['cn'];
    }
    if (! empty($info['context'])) {
        $logfile = "/tmp/applications-" . $info['action'] . "-" . $info['context'] . "-" . $info['user']['cn']  . "." . $info['interpreter'];
    } else {
        $logfile = "/tmp/applications-" . $info['action'] . "-" . $info['user']['cn'] . "." . $info['interpreter'];
    }
    if ($info['remote']) {
        $logfile .= "-remote";
    }

    file_put_contents($logfile, $out[$info['interpreter']]);
}
