<?php

/**

 * Import de l'iso windows


 * @Projet SambaEdu

 * @auteurs  denis.bonnenfant

 * @Licence Distribue selon les termes de la licence GPL

 * @note

 */
include "config.inc.php";
require "ldap.inc.php";
require_once ("functions.inc.php");
require_once ("traitement_data.inc.php");
$config = get_config();
include 'admin_ui.inc.php';
$html = "";
admin_header_html($config, $html);
admin_topbar_html($config, $html);
admin_menu_html($config, $html);
$html .= header_authorize($config);
require_once "ihm.inc.php";
require_once "gpo.inc.php";

$html .= "<h1>Mise en place des sources d'installation Windows</h1>";
$version = $_POST['version'] ?? "";
$iso = $_POST['iso'] ?? "";

// connexion();
function test_pwsh()
{
    $command = "/usr/bin/pwsh -c exit";
    exec($command, $out, $ret);
    if ($ret == 0) {
        return true;
    } else {
        return false;
    }
}

function get_win_url($config, $version, &$html)
{
    $command = "/usr/bin/pwsh /usr/share/sambaedu/scripts/Fido.ps1 -Arch x64 -Win " . $version . " -Ed Pro -rel Latest -Lang Fr -GetUrl";
    exec($command, $out, $ret);
    if ($ret == 0) {
        preg_match("#(Win.*iso)#", $out[0], $m);
        $html .= "<a href=" . trim($out[0]) . ">URL de téléchargement de l'iso " . $m[1] . "</a><br>";
        return [
            'url' => trim($out[0]),
            "iso" => $m[1]
        ];
    } else {
        $html .= "erreur : " . implode("<br>", $out) . "<br>";
        return false;
    }
}

if (! have_right($config, SE_COMPUTER_ADMIN))
    die("Vous n'avez pas les droits suffisants pour accéder à cette fonction</BODY></HTML>");

// Aide

if (empty($version)) {
    if (test_pwsh()) {
        $html .= "<h3>Choix des images déjà  disponibles<h3>";
        exec("sudo ls /var/sambaedu/unattended/install/os/iso/Win*.iso", $liste);
        $html .= "<FORM method=\"post\" action=\"win_iso.php\">\n";
        $html .= "<select NAME=\"iso\">";
        $html .= "<option value=''>Télécharger une nouvelle image...</option>";
        foreach ($liste as $fichier) {
            $html .= "<option value='" . basename($fichier) . "'>" . basename($fichier, ".iso") . "</option>";
        }
        $html .= "</select>";
        $html .= "<h3>Choisir la version à mettre à jour si nécessaire</h3>";
        $html .= "<select NAME=\"version\" onchange=onchange=submit()>";
        $html .= "<option value=''>Choisir la version...</option>";
        $html .= "<option value=10>Windows 10 Pro x64</option>";
        $html .= "<option value=11>Windows 11 Pro x64</option>";
        $html .= "</select>";
        $html .= "</FORM>\n";
    } else {
        batch_command("/usr/share/sambaedu/scripts/install-win-iso.sh $version $iso");
        batch_write("normal");
        $html .= "La mise en place des outils nécessaires au téléchargement de Windows a été lancée. Cela peut durer quelques minutes. Veuillez patienter.";
    }
} else {
    if (empty($iso)) {
        if ($url = get_win_url($config, $version, $html)){
            batch_command("curl -o /var/sambaedu/unattended/install/os/iso/" . $url['iso'] . " \"" . $url['url'] . "\"");
            batch_command("/usr/share/sambaedu/scripts/install-win-iso.sh $version " . $url['iso']);
            $iso = $url['iso'];
        }
    } else {
        batch_command("/usr/share/sambaedu/scripts/install-win-iso.sh $version $iso");
        $url = $iso;
    }
    batch_write("normal");
    if ($url) {
        $html .= "<p>La mise en place des sources d'installation de Windows $version $iso se fera dans les 10 minutes à venir. Veuillez patienter, la taille des fichiers est de 5Go environ.<br>
Vous recevrez un mail que cela sera terminé.<br>
En cas d'erreur, vous pouvez tenter de télécharger directement avec l'URL ci-dessus.<p>
Si votre carte réseau ou le disque ne sont pas reconnus lors de l'installation,
vous devrez injecter les drivers réseau dans l'image de boot avec DISM depuis un poste windows 10 / 11.
Vous pouvez si besoin récuperer les drivers dans le fichier /var/sambaedu/unattended/install/os/Win$version/sources/boot.wim-Win$version-old<br>
Consultez <a href=https://doc.sambaedu.org/administrer_se4/co/install_auto.html>la documentation</a></p>";
    }
}
admin_footer_html($html);
echo $html;
?>
