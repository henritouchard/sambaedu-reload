<?php
/*
 * génération d'un fichier sysprep.xml,
 */
require "config.inc.php";
$config = get_config();
require "ldap.inc.php";
require "actions.inc.php";
require_once 'ipxe_functions.inc.php';
require "windows.inc.php";

header("Content-type: text/plain");
$name = $_POST['name'] ?? $_GET['name'] ?? "";
$machine = get_action($config, $name);
$os = $machine['operatingsystem'] ?? "10";
if (preg_match("/Windows 7.*/", $os)) {
    $attrs['version'] = "Win7";
} elseif (preg_match("/Windows (1[0-9]).*/", $os, $m)) {
    $attrs['version'] = "Win" . $m[1];
}
if (isset($machine['cn']) && ((@$machine['action']['type'] == "renomme") || (@$machine['action']['type'] == "clonage") || (@$machine['action']['type'] == "postinst"))) {
    if (@$machine['action']['type'] == "renomme") {
        $attrs['name'] = $machine['action'][$machine['netbootguid']]['role'];
    } else {
        $attrs['name'] = $machine['cn'];
    }
    $attrs['ou'] = ldap_dn2oudn($machine['dn']);
    $attrs['join'] = true;
    if (@$machine['action'][$machine['netbootguid']]['script'] == "change-hostname" || @$machine['action'][$machine['netbootguid']]['role'] == "windows")
        $specialize = true;
    else
        $specialize = false;
    $xml = update_xml_unattend($config, __DIR__ . "/sysprep.xml", $attrs, $specialize);
    echo $xml;
    $log = "URL utilisee pour " . $name . " : http://" . $config['se4fs_name'] . "/ipxe/Win10/sysprep.xml.php?name=$name\n";
    file_put_contents("/tmp/sysprep.log", $log, FILE_APPEND);
} else {
    file_put_contents("/tmp/sysprep.log", "Rien à faire pour " . $name . "\n", FILE_APPEND);
}
?>