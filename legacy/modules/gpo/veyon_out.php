<?php
include("config.inc.php");
$config = get_config();
include("ldap.inc.php");

function cleandn($dn)
{
    $dn = preg_replace("/ou=/", "OU=", $dn);
    $dn = preg_replace("/cn=/", "CN=", $dn);
    return preg_replace("/dc=/", "DC=", $dn);
}

$licence = $_POST["licence"] ?? 0;
if ($licence == 1) {
    $licence_file = "/etc/sambaedu/applications/veyon/licence.vlf";
    if (file_exists($licence_file)) {
        echo file_get_contents($licence_file);
    }
    exit();
}
$id = $_POST["id"] ?? $_GET["id"] ?? "";

// $liste_parcs = list_parcs($config, $nom_poste, "salle");
$info = apcu_fetch("apps." . $id);
$nom_poste = $info['machine']['cn'] ?? "";
if (empty($nom_poste)) {
    exit();
}
// création d'un compte service en lecture AD si besoin
if (empty($config['read_ldap_password'])) {
    $rules = get_password_rule($config);
    $length = $rules['min-pwd-length'] ?? 15;
    if ($length < 15) {
        $length = 15;
    }
    $password = create_random_password($length, true, false);
    $user['dn'] = "CN=read.user" . $config['suffix'] . "," . $config['dn']['people'];
    $user['cn'] = "read.user" . $config['suffix'];
    $user['displayname'] = "compte service pour lecture AD";
    $user['useraccountcontrol'] = 512;
    $user['accountexpires'] = 0;
    $user['unicodepwd'] = iconv("UTF-8", "UTF-16LE", "\"" . $password . "\"");
    $result = create_ad_user($config, $user);
    if ($result) {
        $config = set_config($config, "read_ldap_password", $password);
    }
}
if (! user_valid_passwd($config, "read.user" . $config['suffix'], $config['read_ldap_password'])) {
    usersetpassword($config, "read.user" . $config['suffix'], $config['read_ldap_password']);
}

// $salles = list_salle_childrens($config, search_parcs($config, $info['salle'], "salle")[0]);
$salles = search_ad($config, $info['salle'], "salle");
if (isset($salles[0])) {
    // $salles = search_ad($config, "*", "salle", ldap_dn2oudn($salles[0]['dn']));
    $salles = search_ad($config, "*", "salle", $salles[0]['dn']);
    // if ($info['salle'] == "base") {
    $parcFilter = "(|";
    foreach ($salles as $parc) {
        $parcFilter .= "(cn=" . $parc['ou'] . ")";
    }
    $parcFilter .= ")";
} else {
    $parcFilter = "(cn=" . $info['salle'] . ")";
}
// $liste_parcs = $info['salle'] ?? "";

$json = json_decode(file_get_contents("/usr/share/sambaedu/applications/veyon/veyon.json"), true);
$local_json = "/etc/sambaedu/applications/veyon/local.json";
if (file_exists($local_json)) {
    $json = array_replace_recursive($json, json_decode(file_get_contents($local_json), true));
}
$key = file_get_contents("/usr/share/sambaedu/applications/veyon/default-pubkey.pem");
$ldap_pass_crypted = "";
openssl_public_encrypt($config['read_ldap_password'], $ldap_pass_crypted, $key, OPENSSL_PKCS1_OAEP_PADDING);
$pdn = cleandn($config['parcs_rdn']);
$cdn = cleandn($config['computers_rdn']);
$json['LDAP'] = array(
    "BaseDN" => cleandn($config['ldap_base_dn']),
    "BindDN" => "CN=read.user" . $config['suffix'] . "," . $config['people_rdn'] . "," . $config['ldap_base_dn'],
    "BindPassword" => bin2hex($ldap_pass_crypted),
    "ComputerContainersFilter" => "",
    "ComputerDisplayNameAttribute" => "cn",
    "ComputerGroupTree" => $pdn,
    "ComputerGroupsFilter" => $parcFilter,
    "ComputerHostNameAsFQDN" => false,
    "ComputerHostNameAttribute" => "name",
    "ComputerLocationAttribute" => "dn",
    "ComputerLocationsByAttribute" => false,
    "ComputerLocationsByContainer" => false,
    "ComputerMacAddressAttribute" => "networkAddress",
    "ComputerRoomAttribute" => "dn",
    "ComputerRoomMembersByAttribute" => false,
    "ComputerRoomMembersByContainer" => true,
    "ComputerRoomNameAttribute" => "cn",
    "ComputerTree" => $cdn,
    "ComputersFilter" => "(objectClass=computer)",
    "ConnectionSecurity" => 1,
    "GroupMemberAttribute" => "member",
    "IdentifyGroupMembersByNameAttribute" => false,
    "LocationNameAttribute" => "cn",
    "QueryNamingContext" => false,
    "RecursiveSearchOperations" => true,
    "ServerHost" => ad_url($config, "dns"),
    "ServerPort" => 389,
    "TLSVerifyMode" => 1,
    "UseBindCredentials" => true,
    "UserGroupsFilter" => "(|(CN=Eleves)(CN=Profs)(CN=Administratifs))",
    "UserLoginAttribute" => "cn",
    "UserLoginNameAttribute" => "cn",
    "UsersFilter" => "(objectClass=person)"
);
// passage en contexte global pour évaluer les groupes d'utilisateurs
$config = get_config($config, true, true);
$json['LDAP']['UserTree'] = cleandn($config['people_rdn']);
$json['LDAP']['GroupTree'] = cleandn($config['groups_rdn']);

// $json["AccessControl"]["AccessControlRules"]["JsonStoreArray"][0]["Parameters"][0]["Argument"] = "CN=Eleves," . cleandn($config['groups_rdn']);
// $json["AccessControl"]["AccessControlRules"]["JsonStoreArray"][1]["Parameters"][0]["Argument"] = "CN=Eleves," . cleandn($config['groups_rdn']);
// $json["AccessControl"]["AccessControlRules"]["JsonStoreArray"][3]["Parameters"][0]["Argument"] = "CN=Eleves," . cleandn($config['groups_rdn']);
// $json["AccessControl"]["AccessControlRules"]["JsonStoreArray"][4]["Parameters"][0]["Argument"] = "CN=Profs," . cleandn($config['groups_rdn']);
// $json["AccessControl"]["AccessControlRules"]["JsonStoreArray"][5]["Parameters"][0]["Argument"] = "CN=Administratifs," . cleandn($config['groups_rdn']);
$json["AccessControl"]["AuthorizedUserGroups"][0] = "CN=Admins," . cleandn($config['groups_rdn']);
$json["AccessControl"]["AuthorizedUserGroups"][1] = "CN=Profs," . cleandn($config['groups_rdn']);
$json["AccessControl"]["AuthorizedUserGroups"][2] = "CN=Administratifs," . cleandn($config['groups_rdn']);

if (isset($config['openent_uri'])) {
    $json["DesktopServices"]["PredefinedWebsites"]["JsonStoreArray"][0]["Name"] = "ENT";
    $json["DesktopServices"]["PredefinedWebsites"]["JsonStoreArray"][0]["Path"] = $config['openent_uri'];
}

// openssl_public_encrypt($config['vnc_password'], $vnc_pass_crypted, $key, OPENSSL_PKCS1_OAEP_PADDING);
// $json['ExternalVncServer']["Password"] = bin2hex($vnc_pass_crypted);
// if ($info['os'] == "linux"){
// $json["Service"]["ActiveSession"] = true;
// $json["Service"]["MultiSession"] = false;
// }

header('Content-type:application/json;charset=utf-8');

echo json_encode($json, JSON_PRETTY_PRINT);
