<?php
/*
 * génère la liste des associations à définir sur le poste
 *
 * On se base sur le nom de la machine, c'est suffisant pour récup les infos nécessaires, et il n'y a rien de confidentiel
 *
 * - Par défaut on met à jour les paquets déjà installés, sauf si une version inférieure est spécifiée dans la définition xml du package.
 * - si une source applications est définie elle est utilisée pour les installations/mises à jour. Sinon on utilise la source MS officielle
 * - Si l'id est spécifié dans le XML, alors il sera installé par applications si il ne l'est pas déjà.
 * - NON, applications gère les dépendances Si un package avec un id est défini dans la liste des applications globales, mais n'est pas demandé pour le poste, il sera désinstallé par applications
 * - si un package est présent dans la liste des applications à desinstaller on le retire
 *
 */
include "config.inc.php";
$config = get_config();
include("wpkg_lib.php");
include("wpkg_libsql.php");
include("ldap.inc.php");
include("applications.inc.php");

$id = $_POST['id'] ?? "";
$list = $_POST["list"] ?? "";
$id = apcu_fetch("apps." . $id);
if (! is_array($id) || empty($list)) {
    header("HTTP/1.1 400 Bad request");
    exit();
}
$m = [];
$local_assoc = [];
file_put_contents("/tmp/assoc_local.json", $list);

foreach (json_decode($list, true) as $type => $apps) {
    foreach ($apps as $l) {
        preg_match("/^\s*(.*)\s*,\s*(.*)$/", $l, $m);
        $local_assoc[$m[1]] = [
            "ProgId" => $m[2],
            "type" => $type
        ];
    }
}
$xml = new DOMDocument();
$xml->formatOutput = true;
$xml->preserveWhiteSpace = false;
$xml->load($url_packages);
$packages = $xml->documentElement->getElementsByTagName('package');

$associations = [];
$liste_applications = $id['liste_applications'];
file_put_contents("/tmp/assoc_app.json", json_encode($id, JSON_PRETTY_PRINT));

foreach ($packages as $package) {
    if (is_int(array_search(strtolower($package->getAttribute('id')), array_map("strtolower", $liste_applications)))) {
        $variables = $package->getElementsByTagName("Association");
        foreach ($variables as $variable) {
            if ($variable->getAttribute('ProgId') != "" && $variable->getAttribute('Identifier') != "") {
                $type = $variable->getAttribute('type') ?? "file";
                if (empty($type)) {
                    $type = "file";
                }
                $associations[$package->getAttribute('id')][$variable->getAttribute('Identifier')] = [
                    "ProgId" => $variable->getAttribute('ProgId'),
                    "type" => $type
                ];
            }
        }
    }
}
foreach ($packages as $package) {
    if (is_int(array_search(strtolower($package->getAttribute('id')), array_map("strtolower", $liste_applications)))) {
    }
}

file_put_contents("/tmp/assoc_wpkg.json", json_encode($associations, JSON_PRETTY_PRINT));


// associations par défaut pour les apps préinstallées windows

$default = [];
if (file_exists("/usr/share/sambaedu/applications/associations/default.xml")) {
    $xml = new DOMDocument();
    $xml->formatOutput = true;
    $xml->preserveWhiteSpace = false;
    $xml->load("/usr/share/sambaedu/applications/associations/default.xml");
    $variables = $xml->getElementsByTagName("Association");
    foreach ($variables as $variable) {
        if ($variable->getAttribute('ProgId') != "" && $variable->getAttribute('Identifier') != "") {
            $type = $variable->getAttribute('type') ?? "file";
            if (empty($type)) {
                $type = "file";
            }
            $default[$variable->getAttribute('Identifier')] = [
                "ProgId" => $variable->getAttribute('ProgId'),
                "type" => $type
            ];
        }
    }
}

// recup de la liste des applications dont on veut les associations
// {
// "OnlyOffice": [
// "toto", "Parc1", "Eleves"
// ],
// "Firefox": []
// }
// "Id_application_wpkg" => ["Nom", "Parc", "groupe"]
// etc :
// 0 - force
// 1 - utilisateur
// 2 - groupe
// 3 - Machine
// 4 - Parc
// 5 - all
// usr :
// 6 - ....
$filename = "/etc/sambaedu/applications/associations/associations.json";
if (file_exists($filename)) {
    $l_add = json_decode(file_get_contents($filename), true) ?? [];
} else {
    $l_add = [];
}
foreach ($l_add as $app => $m) {
    if (! in_array($app, array_keys($associations))) {
        unset($l_add[$app]);
    }
}
// filtrage pour les app installées
$filename = "/usr/share/sambaedu/applications/associations/associations.json";
if (file_exists($filename)) {
    $add = json_decode(file_get_contents($filename), true); // ?? [];
} else {
    $add = [];
}
foreach ($add as $app => $m) {
    if (! in_array($app, array_keys($associations))) {
        unset($add[$app]);
    }
}

$result = $default;
// liste des utilisateurs, groupes, parcs inversée
$list = array_reverse($id['list']);
array_unshift($list, "all");
array_push($list, "force");
// d'abord usr
foreach ($list as $l) {
    foreach ($add as $app => $m) {
        if (in_array($l, $m)) {
            $result = array_merge($result, $associations[$app]);
        }
    }
}
foreach ($list as $l) {
    foreach ($l_add as $app => $m) {
        if (in_array($l, $m)) {
            $result = array_merge($result, $associations[$app]);
        }
    }
}

// recherche des associations à modifier
foreach ($result as $i => $a) {
    if (isset($local_assoc[$i]) && empty(array_diff($local_assoc[$i], $a))) {
        unset($result[$i]);
    }
}

file_put_contents("/tmp/assoc_result.json", json_encode($result, JSON_PRETTY_PRINT));

header('Content-type: text/json');
echo json_encode([
    'result' => $result
], JSON_PRETTY_PRINT);
