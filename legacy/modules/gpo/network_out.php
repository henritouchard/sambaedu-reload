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
//
// Les scripts générés sont typiquement utilisés pour DL des fichiers de conf, et le copier au bon endroit
//
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "network.inc.php";
require "logs.inc.php";
$action = $_POST['action'] ?? $_GET['action'] ?? "";
$os = $_POST['os'] ?? $_GET['os'] ?? "linux";
$id = $_POST['id'] ?? $_GET['id'] ?? "";

if (! empty($action) && ! empty($os) && $os == "linux") {
    switch ($action) {
        case "startup":
            $header = [
                'windows' => "::cmd\r\n::" . $action . "\r\n:: script de configuration du réseau windows\r\n",
                'linux' => "#!/bin/bash\n#" . $action . "\n# script de configuration du reseau Linux\n"
            ];
            $out = $header[$os];
            $out .= network_create_script($config, $id, $os, $action);
            $out .= system_proxy($config);
            header("Content-type: text/plain");
            echo $out;
            file_put_contents("/tmp/network-" . $action . "-" . $id . ".log", $out);
            break;
        case "logon":
            $header = [
                'windows' => "::cmd\r\n::" . $action . "\r\n:: script de configuration du réseau windows\r\n",
                'linux' => "#!/bin/bash\n#" . $action . "\n# script de configuration du reseau Linux\n"
            ];
            $out = $header[$os];
            $out .= gnome_proxy($config);
            header("Content-type: text/plain");
            echo $out;
            file_put_contents("/tmp/network-" . $action . "-" . $id . ".log", $out);
            break;
    }
}
?>