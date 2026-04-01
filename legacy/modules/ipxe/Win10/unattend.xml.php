<?php
/*
 * génération d'un ficiher unattend.xml,
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once 'ipxe_functions.inc.php';
require "windows.inc.php";

header("Content-type: text/plain");
$uuid = $_POST['uuid'] ?? $_GET['uuid'] ?? "";
$uuid = strtolower($uuid);
$mac = $_POST['mac'] ?? $_GET['mac'] ?? "";
$attrs['bios'] = $_POST['bios'] ?? $_GET['bios'] ?? "legacy";
$attrs['version'] = $_POST['version'] ?? $_GET['version'] ?? "Win11";
$disk = $_POST['disk'] ?? 0;
$perso = $_POST['perso'] ?? 0;
$attrs['join'] = ($perso == 0);
if (auth_action($config, $mac, $uuid)) {
    $machine = search_machine($config, $uuid);
    $attrs['os'] = 10;
    if (isset($machine['cn'])) {
        $attrs['name'] = $machine['cn'];
        $attrs['ou'] = ldap_dn2oudn($machine['dn']);
        if ($disk == 1) {
            $attrs['bios'] = "";
        } else {
            remove_dual_boot($config, $machine['cn']);
        }
    } else {
        $attrs['name'] = "*";
    }
    $xml = update_xml_unattend($config, __DIR__ . "/unattend.xml", $attrs);
    echo $xml;
    $log = "URL utilisee pour " . $machine['cn'] . " : http://" . $config['se4fs_name'] . "/ipxe/Win10/unattend.xml.php?mac=$mac&uuid=$uuid&version=" . $attrs['version'] . "&bios=" . $attrs['bios'] . "\n";
    file_put_contents("/tmp/unattend.log", $log, FILE_APPEND);
}
?>