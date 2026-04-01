<?php
/*
 * génération d'un ficiher install.bat, qui va lancer la réparation du boot depuis winpe
 */

use Google\Service\MigrationCenterAPI\DiskPartition;

require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require "ipxe_functions.inc.php";
require "windows.inc.php";

header("Content-type: text/plain");
$ipxe = "::cmd\r\n";
$action = $_POST['action'] ?? "default";
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$version = $_POST['version'] ?? "Win11";
$debug = $_POST['debug'] ?? 0;
$bios = $_POST['bios'] ?? 'legacy';
$mac = $_POST['mac'] ?? $_GET['mac'];
if ($debug == 1) {
    /*
     * choice.exe n'est pas dans le wim !
     * $pause = "choice /T 10 /D Y /M continuer ?\r
     * IF %ERRORLEVEL% EQU 2 goto n\r
     * IF %ERRORLEVEL% EQU 1 goto y\r
     */
    $pause = "PAUSE\r\n";
} else {
    $pause = "\r\n";
}
if (auth_action($config, $mac, $uuid)) { // || ! empty($uuid)) {
    $computer = get_action($config, $uuid);
    $ipxe .= "wpeutil InitializeNetwork\r\n";
    $ipxe .= "wpeutil WaitForNetwork\r\n";
    $ipxe .= "wpeinit.exe\r\n";
    $ipxe .= ":n\r\n";
   // $ipxe .= $pause;
    $ipxe .= "IPCONFIG /RENEW\r\n";
    $ipxe .= "set \"ERR=%ERRORLEVEL%\"\r\n";
    $ipxe .= "if [%ERR%]==[0] (goto y) else (goto n)\r\n";
    $ipxe .= ":y\r\n";
    // $ipxe .= "@PING 127.0.0.1\r\n";
    $ipxe .= "@PING " . $config['se4fs_ip'] . "\r\n";
    $ipxe .= "set \"ERR=%ERRORLEVEL%\"\r\n";
    $ipxe .= "if not [%ERR%]==[0] (goto n)\r\n";
    $ipxe .= "type x:\\windows\\system32\\diskpart.txt\r\n";
    $ipxe .= $pause;
    //diskpart.txt a été généré et copié dans l'image ramdisk winpe
    // $ipxe .= "%windir%\\system32\\diskpart /s x:\\windows\\system32\\diskpart.txt\r\n";
    if ($bios == "uefi") {
        $ipxe .= "%windir%\\system32\\bcdboot c:\\windows \r\n";
        //$ipxe .= "%windir%\\system32\\bcdboot c:\\windows /s u: /f UEFI\r\n";
    } else {
        //        $ipxe .= "%windir%\\system32\\bcdboot c:\\windows /s u: /f BIOS\r\n";
        $ipxe .= "%windir%\\system32\\bcdboot c:\\windows \r\n";
    }
    $ipxe .= "echo remontee du succes de l installation\r\n";
    //$ipxe .= "if exist c:\\windows\\system32\\curl.exe (c:\\windows\\system32\\curl.exe -F \"etape=repair\" -F \"name=" . $computer['cn'] . "\" -F \"ret=0\" http://" . $config['se4fs_name'] . "/ipxe/" . $version . "/action.php)\r\n";
    if ($debug) {
        $ipxe .= "cmd\r\n";
    }
    //$ipxe .= $pause;
    // on enregistre le debut de l'action install pour la machine
}
file_put_contents("/tmp/repair.log", $ipxe);
ipxe_out($config, $ipxe);
