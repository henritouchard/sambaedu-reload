<?php
//
include "config.inc.php";
require "ldap.inc.php";
require_once("functions.inc.php");
require_once("traitement_data.inc.php");
$config = get_config();
include 'admin_ui.inc.php';
$html = "";
admin_header_html($config, $html);
admin_topbar_html($config, $html);
admin_menu_html($config, $html);
$html .= header_authorize($config);
echo $html;
include "ihm.inc.php";
require "shortcuts.inc.php";

if (have_right($config, SE_ADMIN)) {
    $action = $_POST['action'] ?? "";
    $select_application = $_POST['application'] ?? "";
    echo "<h1>Gestion des applications Windows pour les clients Linux</h1>";
    echo "<form method=\"post\" action=\"wine.php\" enctype=\"multipart/form-data\">\n";

    echo " Cette page permet de gérer le support des applications Windows sur les postes Linux grâce à Wine.<p>
<p>Pour l'activer vous devez :</p>
<ul><li>Ajouter l'application Wpkg Wine aux parcs de clients linux concernés.</li>
<li>Sur un de ces clients Linux, vous connecter avec le compte <b>se4install</b> et installer les applications windows souhaitées avec Wine.
Par défaut le prefix .wine est utilisé. Mais vous pouvez définir des prefixes differents pour chaque application avec
<pre>WINEPREFIX=/home/se4install/.wine-{application}>/pre> avant la commande wine</li>
Il peut être utile d'installer quelques composants Windows avec <pre>winetricks</pre>
Par exemple :<pre>winetricks -q corefonts</pre></li>
<li>Il est également possible d'exécuter directement des applications portables situées sur un partage réseau.</li>
<li>Une fois les applications installées, verifiez que le raccourci sur le bureau de l'application est bien fonctionnel. Il sera récupéré par la suite.</li>
<li>Se déconnecter de la session se4install, et génerer l'image partagée des applications avec le bouton ci-dessous. Attention la génération doit être refaite en cas de mise à jour de la version de Wine sur les postes.<br>
<input type=\"submit\" name=\"action\" value=\"Générer l'image\"></li>
<li>Générer les raccourcis pour pouvoir ensuite les affecter aux aux utilisateurs depuis cette page.<br>
<input type=\"submit\" name=\"action\" value=\"Générer les raccourcis\"></li></ul>
<p>Si vous souhaitez rajouter de nouvelles applications, il suffit de le faire depuis le compte se4install, puis de régénerer l'image. Si vous voulez tester une application, vous pouvez copier le dossier .wine en .wine.old, afin de pouvoir le restaurer en cas de problème.</p>
Vous pouvez choisir le conteneur wine à utiliser (par défaut c'est .wine).<br>
Si plusieurs conteneurs sont générés, les conteneurs .wine-{application} seront automatiquement montés et utilisés si 'application' correspond à une application wpkg activée pour le poste.<br>
<select name=application>
<option value=''>Conteneur par défaut (.wine)</option>";
    $liste = dir("/var/sambaedu/unattended/install/wine");
    foreach ($liste as $l) {
        $m = [];
        if (preg_match("#^wine-(.*)$#", $l, $m)) {
            $applications[] = $m[1];
        }
    }
    foreach ($applications as $application) {
        echo  "<option value=\"" . $application . "\"";
        if ($application = $select_application) {
            echo " checked";
        }
        echo ">.wine-" . $application . "</option>";
    }
    echo "</select>
</form>";
    switch ($action) {
        case "Générer l'image":
            batch_command("/usr/share/sambaedu/scripts/make_wine_image.sh " . $application);
            batch_write("normal");
            echo "Patientez, la génération de l'image peut prendre une dizaine de minutes...";
            break;
        case "Générer les raccourcis":
            if ($json = file_get_contents("/etc/sambaedu/applications/shortcuts/shortcuts.json")) {
                $shortcuts = json_decode($json, true) ?? [];
            }
            $shortcuts = array_merge($shortcuts, get_wine_shortcuts($config, $application));
            file_put_contents("/etc/sambaedu/applications/shortcuts/shortcuts.json", json_encode($shortcuts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            break;
    }
} else {
    print("Vous n'avez pas les droits nécessaires pour ouvrir cette page...");
}
// Footer
$html = "";
admin_footer_html($html);
echo $html;
