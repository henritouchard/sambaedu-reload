<?php


/**
 * Librairie de fonctions pour controler diverses entrees

 * @Version $Id$

 * @Projet SambaEdu
 * @Auteurs Equipe Sambaedu
 * @Licence Distribue sous la licence GPL
 */

/**
 *
 * file: functions.inc.php
 *
 * @Repertoire: includes/
 */

// =================================================
/**
 * Fonction qui test si la chaine est encode en UTF8
 *
 * @Parametres $chaine la chaine a tester
 * @return
 */
function convertUTF8_to_8859($str)
{
    if (is_utf8($str) == 1) {
        // fonction qui test si la chaine encode en UTF8 contient des caractere francais: Cette fonction ne traite que des chaines en UTF8
        if (content8859_in_UTF8($str) == "TRUE") {
            // On convertit la chaine de UTF8 en ISO8859-1
            $str = utf8_decode($str);
            // retourner la chaine converti
            return ($str);
        } else { // cas ou la chaine en UTF-8 mais ne contient pas des accents francais : exemple les caracetres chinois encode en UTF8
                 // retourner la chaine non convertit
            return ($str);
        }
    } else { // cas ou la chaine n'est pas encode en UTF8
        return ($str);
    }
}

// =================================================
/**
 * Test si la chaine est en UTF-8 ou pas
 *
 * @Parametres $chaine la chaine a tester
 * @Returns true if $string is valid UTF-8 and false otherwise.
 */
function is_utf8($string)
{
    return preg_match('%^(?:
[\x09\x0A\x0D\x20-\x7E] # ASCII
| [\xC2-\xDF][\x80-\xBF] # non-overlong 2-byte
| \xE0[\xA0-\xBF][\x80-\xBF] # excluding overlongs
| [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2} # straight 3-byte
| \xED[\x80-\x9F][\x80-\xBF] # excluding surrogates
| \xF0[\x90-\xBF][\x80-\xBF]{2} # planes 1-3
| [\xF1-\xF3][\x80-\xBF][3] # planes 4-15
| \xF4[\x80-\x8F][\x80-\xBF]{2} # plane 16
)*$%xs', $string);
}

// function is_utf8

// =================================================

/**
 * fonction qui cherche s'il ya des caractres accentues francais dans une chaine en UTF8
 *
 * @Parametres $str chaine a tester
 * @return true ou false
 */
function content8859_in_UTF8($str)
{
    if (strlen($str) == 0) {
        return;
    }
    // cette fonction ne retourne de valeur si la chaine est en UTF8
    // cette fonction retourne un tableau contenant les chaines accentuees
    preg_match_all('/.{1}|[^\x00]{1,1}$/us', $str, $ar);
    $chars = $ar[0];
    $str_fr = 0;
    foreach ($chars as $i => $c) {
        $ud = 0;
        // Calcul les codes ASCII des chaines en UTF8
        if (ord($c[0]) >= 0 && ord($c[0]) <= 127) {
            continue;
        } // ASCII - next please
        if (ord($c[0]) >= 192 && ord($c[0]) <= 223) {
            $ord = (ord($c[0]) - 192) * 64 + (ord($c[1]) - 128);
        }
        if (ord($c[0]) >= 224 && ord($c[0]) <= 239) {
            $ord = (ord($c[0]) - 224) * 4096 + (ord($c[1]) - 128) * 64 + (ord($c[2]) - 128);
        }
        if (ord($c[0]) >= 240 && ord($c[0]) <= 247) {
            $ord = (ord($c[0]) - 240) * 262144 + (ord($c[1]) - 128) * 4096 + (ord($c[2]) - 128) * 64 + (ord($c[3]) - 128);
        }
        if (ord($c[0]) >= 248 && ord($c[0]) <= 251) {
            $ord = (ord($c[0]) - 248) * 16777216 + (ord($c[1]) - 128) * 262144 + (ord($c[2]) - 128) * 4096 + (ord($c[3]) - 128) * 64 + (ord($c[4]) - 128);
        }
        if (ord($c[0]) >= 252 && ord($c[0]) <= 253) {
            $ord = (ord($c[0]) - 252) * 1073741824 + (ord($c[1]) - 128) * 16777216 + (ord($c[2]) - 128) * 262144 + (ord($c[3]) - 128) * 4096 + (ord($c[4]) - 128) * 64 + (ord($c[5]) - 128);
        }
        if (ord($c[0]) >= 254 && ord($c[0]) <= 255) {
            $chars[$i] = "unknown";
            continue;
        } // error
          // Test si les caracteres contient les accents
        if (($ord == 224) || ($ord == 226) || ($ord == 235) || ($ord == 249) || ($ord == 250) || ($ord == 252) || ($ord == 251) || ($ord == 233) || ($ord == 234) || ($ord == 232) || ($ord == 231) || ($ord == 228) || ($ord == 256) || ($ord == 128) || ($ord == 156) || ($ord == 230) || ($ord == 231) || ($ord == 244) || ($ord == 225) || ($ord == 236) || ($ord == 227) || ($ord == 237) || ($ord == 238) || ($ord == 249) || ($ord == 239) || ($ord == 257)) {
            $str_fr = 1;
        }
    }
    if ($str_fr == 1) {
        return "TRUE";
    } else {
        return "FALSE";
    }
}

// =================================================
/**
 * Remplace les caracteres accentues par leurs equivalents et l'espace par underscore (modifie pour utf-8)
 *
 * @Parametres $chaine a traiter
 * @return chaine sans accent
 */

// Remplace les caracteres accentues par leurs equivalents, et les majuscules
// et l'espace par underscore (modifie pour utf-8)
function enleveaccents($chaine)
{
    $chaine = convertUTF8_to_8859($chaine);
    $chaine = str_replace(array(
        'à',
        'â',
        'ä',
        'á',
        'ã',
        'å',
        'î',
        'ï',
        'ì',
        'í',
        'ô',
        'ö',
        'ò',
        'ó',
        'õ',
        'ø',
        'ù',
        'û',
        'ü',
        'ú',
        'é',
        'è',
        'ê',
        'ë',
        'ç',
        'ÿ',
        'ñ',
        'À',
        'Â',
        'Ä',
        'Á',
        'Ã',
        'Å',
        'Î',
        'Ï',
        'Ì',
        'Í',
        'Ô',
        'Ö',
        'Ò',
        'Ó',
        'Õ',
        'Ø',
        'Ù',
        'Û',
        'Ü',
        'Ú',
        'É',
        'È',
        'Ê',
        'Ë',
        'Ç',
        '¾',
        'Ñ',
        ' '
    ), array(
        'a',
        'a',
        'a',
        'a',
        'a',
        'a',
        'i',
        'i',
        'i',
        'i',
        'o',
        'o',
        'o',
        'o',
        'o',
        'o',
        'u',
        'u',
        'u',
        'u',
        'e',
        'e',
        'e',
        'e',
        'c',
        'y',
        'n',
        'a',
        'a',
        'a',
        'a',
        'a',
        'a',
        'i',
        'i',
        'i',
        'i',
        'o',
        'o',
        'o',
        'o',
        'o',
        'o',
        'u',
        'u',
        'u',
        'u',
        'e',
        'e',
        'e',
        'e',
        'c',
        'y',
        'n',
        '_'
    ), $chaine);

    return $chaine;
}

function enleveaccents2($chaine)
{
    $chaine = convertUTF8_to_8859($chaine);
    $chaine = str_replace(array(
        'à',
        'â',
        'ä',
        'á',
        'ã',
        'å',
        'î',
        'ï',
        'ì',
        'í',
        'ô',
        'ö',
        'ò',
        'ó',
        'õ',
        'ø',
        'ù',
        'û',
        'ü',
        'ú',
        'é',
        'è',
        'ê',
        'ë',
        'ç',
        'ÿ',
        'ñ',
        'À',
        'Â',
        'Ä',
        'Á',
        'Ã',
        'Å',
        'Î',
        'Ï',
        'Ì',
        'Í',
        'Ô',
        'Ö',
        'Ò',
        'Ó',
        'Õ',
        'Ø',
        'Ù',
        'Û',
        'Ü',
        'Ú',
        'É',
        'È',
        'Ê',
        'Ë',
        'Ç',
        '¾',
        'Ñ'
    ), array(
        'a',
        'a',
        'a',
        'a',
        'a',
        'a',
        'i',
        'i',
        'i',
        'i',
        'o',
        'o',
        'o',
        'o',
        'o',
        'o',
        'u',
        'u',
        'u',
        'u',
        'e',
        'e',
        'e',
        'e',
        'c',
        'y',
        'n',
        'a',
        'a',
        'a',
        'a',
        'a',
        'a',
        'i',
        'i',
        'i',
        'i',
        'o',
        'o',
        'o',
        'o',
        'o',
        'o',
        'u',
        'u',
        'u',
        'u',
        'e',
        'e',
        'e',
        'e',
        'c',
        'y',
        'n'
    ), $chaine);

    return $chaine;
}

// =================================================

/**
 * Verification de l'intitule d'un groupe
 * L'intitule d'un groupe ne doit pas commencer et finir par les mots : Classe, Cours, Equipe, Matiere
 *
 * @Parametres
 * @return
 */
function verifIntituleGrp($intitule)
{
    $motif1 = "#^Classe$#";
    $motif2 = "#^Cours$#";
    $motif3 = "#^Equipe$#";
    $motif4 = "#^Matiere$#";
    if (preg_match($motif1, $intitule) || preg_match($motif2, $intitule) || preg_match($motif3, $intitule) || preg_match($motif4, $intitule)) {
        $ret = false;
    } else
        $ret = true;
    return $ret;
}

// =================================================

/**
 * Verification de la validite d'un mot de passe
 * longueur de 8 a 20 caracteres
 * c
 *
 * @Parametres password a tester
 * @return array [0=>true si Ok false sinon, 1=> message]
 */
function verifPwd($password, $length = 8, $complexity = true)
{
    if (preg_match("/[^[:graph:]]/", $password, $m)) {
        return [
            'status' => false,
            'message' => "Le mot de passe contient les caractères invalides \"" . implode("\" \"", $m) . "\".\n"
        ];
    } elseif (strlen($password) < $length || strlen($password) > 25) {
        return [
            'status' => false,
            'message' => "Le mot de passe doit faire entre " . $length . " et 25 caractères\n"
        ];
    } elseif ($complexity && (preg_match("/[a-z]/", $password) + preg_match("/[A-Z]/", $password) + preg_match("/[0-9]/", $password) + preg_match("/[[:graph:]]/", $password) < 3)) {
        return [
            'status' => false,
            'message' => "Le mot de passe doit contenir au moins trois types de caractères parmi les quatres suivants : minuscules, majuscules, chiffres et signes de ponctuation\n"
        ];
    } else {
        return [
            'status' => true,
            'message' => "Mot de passe valide"
        ];
    }
}

// =================================================

/**
 * Verification format date de naissance
 *
 * @Parametres date a verifier
 * @return true si ok false sinon
 */
function verifDateNaissance($date)
{
    $motif = "^[0-9]{8}$";
    $ret = false;
    if (preg_match("/$motif/", $date)) {
        // Verification de l'annee
        if ((date('Y') - substr($date, 0, 4) < 75) && (date('Y') - substr($date, 0, 4) > 4)) {
            // Verification du mois
            if ((substr($date, 4, 2) > 0) && (substr($date, 4, 2) <= 12)) {
                if ((substr($date, 6, 2) > 0) && (substr($date, 6, 2) <= 31)) {
                    $ret = true;
                }
            }
        }
    }
    return $ret;
}

// =================================================

/**
 * Verification d'une entree de type Nom ou Prenom si on a des caracteres etranges
 *
 * @Parametres $entree
 * @return true si Ok false sinon.
 */
function verifEntree($entree)
{
    $motif = "#^[_0-9a-zA-Z \'ÀÁÂÃÄÅàáâãäåÒÓÔÕÖØòóôõöøÈÉÊËèéêëÇçÌÍÎÏìíîïÙÚÛÜùúûüÿÑñ-]{2,30}$#";

    if (preg_match($motif, $entree)) {
        $ret = true;
    } else {
        $ret = false;
    }
    return $ret;
}

// =================================================

/**
 * Verification du format du pseudo
 *
 * @Parametres
 * @return
 */
function verifPseudo($pseudo)
{
    $motif = "#\|,/ #";

    if (preg_match($motif, $pseudo) || strlen($pseudo) > 20 || strlen($pseudo) == 0) {
        $ret = false;
    } else {
        $ret = true;
    }
    return $ret;
}

// =================================================

/**
 * Verification du champ description
 *
 * @Parametres
 * @return
 */
function verifDescription($entree)
{
    $motif = "/^[a-zA-Z0-9\s,.;\"\'\/:&ÀÁÂÃÄÅàáâãäåÒÓÔÕÖØòóôõöøÈÉÊËèéêëÇçÌÍÎÏìíîïÙÚÛÜùúûüÿÑñ-]{0,80}$/";
    if (preg_match($motif, stripslashes($entree))) {
        $ret = true;
    } else {
        $ret = false;
    }
    return $ret;
}

// =================================================

/**
 * Verification numero de telephone
 *
 * @Parametres
 * @return
 */
function verifTel($tel)
{
    // $motif ="#^[0-9a-zA-Z-]{10}$#";
    $motif = "#^[0-9a-zA-Z\-]*$#";

    if (preg_match($motif, $tel) || strlen($tel) == 0) {
        $ret = true;
    } else {
        $ret = false;
    }
    return $ret;
}

// =================================================

/**
 * Affiche le haut des pages avec le login
 *
 * @Parametres
 * @return
 */
function header_html($config)
{
    global $login;
    get_config();
    ?>
<html>
<head>
<style type='text/css'>
body {
    background: url(<?php echo replace_element($config, "LogoFond", "/elements/images/fond_SE4.png")?>) ghostwhite bottom right
    no-repeat fixed;
}
</style>
<title>Interface d'administration de <?php echo replace_element($config, "Athena", "SambaEdu")?></title>
<meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1" />
<link type='text/css' rel="stylesheet"
	href="/elements/style_sheets/sambaedu.css" />
</head>
<body>
<?php
    print "<h3 align='right'>Bonjour $login</h3>";
}

// =================================================

// =================================================

/**
 * Affiche le menu de recherche dans la partie LDAP
 *
 * @Parametres
 * @return
 */
function aff_mnu_search($user_type, &$html)
{
    if ($user_type == "Y") {

        //
        // Affichage menu admin
        $html .= "

     <ul>
       <li><b>Rechercher :</b>
            <ul>
                <li><a href=\"search.php\">Effectuer une recherche...</a>(pour d'éventuelles modifications)</li>
            </ul>
            <br />
        </li>

       <li><b>Ajouter :</b>
         <ul>
           <li><a href=\"add_user.php\">un utilisateur...</a></li>
           <li><a href=\"add_group.php\">un groupe...</a></li>";
        $html .= "</ul>
           <br />
        </li>
        <li><b>Importation en masse :</b>
          <ul>
            <li><a href=\"../annu/view_sync.php#end\">Voir l'importation en cours.</a></li><br>
            <li><a href=\"../annu/import_siecle.php\">Assistant d'importation des comptes depuis SIECLE.</a></li>
            <li><a href=\"../annu/import_sts.php\">Assistant d'importation des comptes depuis STS.</a></li>
            <li><a href=\"../annu/import_ent.php?action=nouveau\">Assistant d'importation des comptes ENT.</a></li>
            <li><a href=\"../annu/import_gpei.php?action=nouveau\">Assistant d'importation des comptes à partir des fichiers GPEI.</a></li>
            <li><a href=\"../annu/import_csv.php?action=nouveau\">Assistant d'importation des comptes à partir de fichiers CSV, JSON ou XML.</a></li>";

        $html .= "          </ul>
        </li>
     </ul>\n";
    } else {
        // Affichage menu user
        $html .= "
     <ul>
       <li><a href=\"search.php\">Effectuer une recherche...</a></li>
     </ul>\n";
    }
}

// =================================================

/**
 * Affichage de la barre remorquee de haut de page
 *
 * mode 1 : lien Annuaire
 * mode 2 : lien Annuaire -> Recherche
 * mode 3 : lien Annuaire -> Lien Recherche
 * mode 31 : lien Annuaire -> Modification
 * mode 4 : lien Annuaire -> lien Modification pseudo
 * mode 5 : lien Annuaire -> lien Modification pwd
 * mode 6 : lien Annuaire -> lien Ajout groupe
 * mode 7 : lien Annuaire -> lien Ajout utilisateur
 *
 * @Parametres
 * @return
 */
function aff_trailer($mode)
{
    global $imagespath;
    $html = "";
    $html .= "<h2><a href=\"annu.php\">Annuaire</a>&nbsp;";
    if ($mode == 1) {
        $html .= "</h2>";
    } elseif ($mode == 2) {
        $html .= "-> Recherche</h2>";
    } elseif ($mode == 3) {
        $html .= "-> <a href=\"search.php\">Recherche</a></h2>";
    } elseif ($mode == 31) {
        $html .= "-> <a href=\"search.php\">Recherche</a> -> Modification</h2>";
    } elseif ($mode == 4) {
        $html .= "-> <a href=\"mod_entry.php\">Modification</a></h2>";
    } elseif ($mode == 5) {
        $html .= "-> <a href=\"mod_pwd.php\">Modification</a></h2>";
    } elseif ($mode == 6) {
        $html .= "-> <a href=\"add_group.php\">Ajout d'un groupe</a></h2>";
    } elseif ($mode == 7) {
        $html .= "-> <a href=\"add_user.php\">Ajout d'un utilisateur</a></h2>";
    } elseif ($mode != "") {
        list ($valeur, $filtre) = preg_split("#_#", $mode);
        if ($valeur == 8) {
            $mode = preg_replace("#8_#", "", $mode);
            $html .= "-> <a href=\"search.php\">Recherche</a> -><a href=\"group.php?filter=$mode\"> Modification</a> -> " . ldap_dn2cn($mode) . "</h2>";
        }
        if ($valeur == 9) {
            $mode = preg_replace("#9_#", "", $mode);
            $html .= "-> <a href=\"search.php\">Recherche</a> -><a href=\"people.php?cn=$mode\"> Modification</a> -> $mode</h2>";
        }
    } else {
        $html .= "</h2>";
    }
    $html .= "<hr />\n";
    return $html;
}

function new_aff_trailer($config, $mode, &$html)
{
    $html .= "<h2><a href=\"annu.php\">Annuaire</a>&nbsp;";
    $cn = decrypt($config, $_POST['cn'] ?? $_GET['cn'] ?? "");
    $dn = decrypt($config, $_POST['dn'] ?? $_GET['dn'] ?? "");
    switch ($mode) {
        case 1:
            $html .= "</h2>";
            break;
        case 2:
            $html .= "-> Recherche</h2>";
            break;
        case 3:
            $html .= "-> <a href=\"search.php\">Recherche</a></h2>";
            break;
        case 31:
            $html .= "-> <a href=\"search.php\">Recherche</a> -> Modification</h2>";
            break;
        case 4:
            if (! empty($cn)) {
                $html .= "-> <a href=\"search.php\">Recherche</a> -> <a href=\"people.php?cn=" . encrypt($config, $cn ). "\"> Modification</a> -> $cn</h2>";
            } elseif (! empty($dn)) {
                $html .= "-> <a href=\"search.php\">Recherche</a> -> <a href=\"group.php?dn=" . encrypt($config, $dn) . "\"> Modification</a> -> " . ldap_dn2cn($dn) . "</h2>";
            } else {
                $html .= "-> <a href=\"search.php\">Recherche</a> -> <a href=\"people.php\">Modification</a></h2>";
            }
            break;
        case 5:
            $html .= "-> <a href=\"mod_pwd.php\">Modification</a></h2>";
            break;
        case 6:
            $html .= "-> <a href=\"add_group.php\">Ajout d'un groupe</a></h2>";
            break;
        case 7:
            $html .= "-> <a href=\"add_user.php\">Ajout d'un utilisateur</a></h2>";
            break;
        default:
            if (! empty($cn)) {
                $html .= "-> <a href=\"search.php\">Recherche</a> -><a href=\"people.php?cn=" . encrypt($config, $cn) . "\"> Modification</a> -> $cn</h2>";
            } elseif (! empty($dn)) {
                $html .= "-> <a href=\"search.php\">Recherche</a> -><a href=\"group.php?dn=" . encrypt($config, $dn) . "\"> Modification</a> -> " . ldap_dn2cn($dn) . "</h2>";
            } else {
                $html .= "</h2>";
            }
    }
    $html .= "<hr/>\n";
}

?>
