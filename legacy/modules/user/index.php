<?php
/**
 ** Interface Utilisateur
 **/
require_once 'config.inc.php';
$config = get_config();
require_once 'ldap.inc.php';
require_once 'functions.inc.php';
require_once 'fonc_outils.inc.php';
require_once 'partages.inc.php';
require_once 'samba.inc.php';
require_once 'logs.inc.php';
require_once 'ihm.inc.php';
require_once 'ent.inc.php';
require_once 'cloud.inc.php';
require_once 'user.interface.inc.php';
require_once "../vendor/autoload.php";

$html = "";
$autolog = false;

if (! empty($config['login'])) {
    // session OK
    if (is_eleve($config, $config['login'])) {
        if (! empty($config['blocage_eleves'])) {
            $html = html_user_forbidden($config);
        } elseif (! empty($config['central_url'])) {
            header("Location: " . $config['central_url']);
        } else {
            $html = html_user_interface($config);
        }
    } else {
        $html = html_user_interface($config);
    }
} else {
    // session_destroy();
    $login = $_POST['login'] ?? "";
    $password = $_POST['password'] ?? "";

    if (! empty($_POST['dummy'])) {
        // changement de mot de passe obligatoire, et le password est valide
        $html = html_change_password_form($config, $login, $password);
    } else {

        // recherche de l'utilisateur ayant ouvert la session
        if (empty($login)) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
            if ($machine = @search_machine($config, $ip, false)['cn']) {
                $users = @get_machine_status($config, $machine)['user'];
                if (isset($users) && count($users) == 1) {
                    $login = $users[0]['name'];
                    $autolog = true;
                }
            }
        }
        // $newpassword = $_POST['newpassword'] ?? "";
        // verif mot de passe en direct
        $auth = user_valid_passwd($config, $login, $password); // , $newpassword);
        if (isset($config['ent_auth'])) {} elseif (isset($config['cas_url'])) {
            $authpage = "../cas/cas.php";
        }
        if (! empty($login) && ((! empty($_POST['password']) && $auth > 0) || $autolog)) {
            if (! empty($config['blocage_eleves']) && is_eleve($config, $login)) {
                $html = html_user_forbidden($config);
            } else {
                // password ok en direct ou autologin ok
                if (! session_name()) {
                    session_name("Sambaedu");
                }
                if (empty(session_id())) {
                    session_start([
                        'cookie_lifetime' => 86400
                    ]);
                }
                $_SESSION['login'] = $login;
                $config['login'] = $login;
                $html = html_user_interface($config);
            }
        } elseif (! empty($config['ent_auth']) && $config['ent_auth'] == 1 && apcu_fetch('ent_status')) {
            // auth sur l'ent
            $authpage = "../oauth2/login.php";
            header("Location: " . $authpage);
            exit();
        } elseif (isset($config['cas_url'])) {
            $authpage = "../cas/cas.php";
            header("Location: " . $authpage);
        } elseif ($auth == - 1) { // ! empty($_POST['dummy'])) {
                                  // changement de mot de passe obligatoire, et le password est valide
            $html = html_change_password_form($config, $login, $password);
        } else {
            // auth directe
            if (! empty($_GET['mail_login']) && $_GET['page'] == "mail" || ! empty($_POST['mail'])) {
                $html = html_mail_form($config);
            } elseif (empty($_POST['password'])) {
                $html = html_login_form($config);
            } else {
                $html = html_login_form($config, "Nom d'utilisateur ou mot de passe incorrects");
            }
        }
    }
}
echo $html;
?>
