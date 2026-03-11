<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Service utilitaire SE4
 * Remplace les fonctions de includes/functions.inc.php
 */
class UtilityService
{
    /**
     * Retourne l'IP distante
     * Utilisation de $_SERVER['REMOTE_ADDR'] pour éviter l'IP spoofing
     */
    public function remoteIp(): string
    {
        if (getenv("HTTP_CLIENT_IP")) {
            $ip = getenv("HTTP_CLIENT_IP");
        } elseif (filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP)) {
            $ip = $_SERVER['REMOTE_ADDR'];
        } else {
            $ip = "";
        }

        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
        
        return "";
    }

    /**
     * Ouverture de session SE4
     * 
     * @param array $config Configuration SE4
     * @param string $login Login utilisateur
     * @param string $passwd Mot de passe
     * @param string $newpasswd Nouveau mot de passe (optionnel)
     * @return bool Succès de l'authentification
     */
    public function openSession(array $config, string $login, string $passwd, string $newpasswd = ""): bool
    {
        $res = false;
        $authLdap = 0;

        // Validation via LDAP (fonction legacy à implémenter)
        $authLdap = $this->userValidPasswd($config, $login, $passwd, $newpasswd);
        
        if ($authLdap == 0) {
            // Log en cas d'échec
            $this->logAuthFailure($login);
        }

        if ($authLdap > 0) {
            if (!session_name()) {
                session_name("Sambaedu");
            }
            
            if (empty(session_id())) {
                session_start([
                    'cookie_lifetime' => 86400
                ]);
            }
            
            $_SESSION['login'] = $login;
            $_SESSION['passwd'] = $passwd;
            $res = true;
        }

        return $res;
    }

    /**
     * Log des échecs d'authentification
     */
    protected function logAuthFailure(string $login): void
    {
        $logEntry = "[0] " . date("Y-m-d H:i:s") . 
                   "|remote ip : " . $this->remoteIp() . 
                   "|Login : " . $login . 
                   "|TimeStamp srv : " . time() . "\n";

        $fp = fopen("/var/log/sambaedu/auth.log", "a");
        if ($fp) {
            fputs($fp, $logEntry);
            fclose($fp);
        }
    }

    /**
     * Validation du mot de passe utilisateur (fonction legacy)
     * Cette fonction devra être implémentée pour utiliser les fonctions LDAP legacy
     */
    protected function userValidPasswd(array $config, string $login, string $passwd, string $newpasswd = ""): int
    {
        // TODO: Implémenter la validation LDAP legacy
        // Pour l'instant, retourne 0 (échec)
        Log::warning('userValidPasswd not implemented yet', ['login' => $login]);
        return 0;
    }

    /**
     * Ferme la session en cours
     */
    public function closeSession(): void
    {
        if (session_id()) {
            session_destroy();
        }
        
        // Destruction du cookie de session
        setcookie("Sambaedu", "", time() - 3600, "/", "", 0);
    }

    /**
     * Affichage du menu SE4
     * 
     * @param array $config Configuration
     * @param array $liens Liens du menu
     * @param int $menu Menu actuel
     */
    public function menuPrint(array $config, array $liens, int $menu): void
    {
        $rights = $this->listRights($config, $config['login'], true);
        $getintlevel = $this->getIntLevel();

        for ($idmenu = 0; $idmenu < count($liens); $idmenu++) {
            echo "<div id=\"menu$idmenu\" style=\"position:absolute; left:10px; top:12px; width:200px; z-index:" . $idmenu . " ";
            if ($idmenu != $menu) {
                echo "; visibility: hidden";
            }
            echo "\">\n";

            echo "<table width=\"200\" border=\"0\" cellspacing=\"3\" cellpadding=\"6\">\n";
            
            for ($menunbr = 1; $menunbr < count($liens); $menunbr++) {
                $menutarget = "main";
                $rightname = $liens[$menunbr]['right'];
                $level = $liens[$menunbr]['level'];
                
                $afftest = ($rightname == 0 || ($rights & $rightname) > 0);
                if ($level > $getintlevel) {
                    $afftest = false;
                }
                
                if ($afftest) {
                    if (($idmenu == $menunbr) && ($idmenu != 0)) {
                        echo "<tr><td class=\"menuheader\">";
                        echo "<p style='margin:2px; padding-top:2px; padding-bottom:2px'>";
                        echo "<a href=\"javascript:;\" onClick=\"P7_autoLayers('menu0');return false\">&#10514;&nbsp;</a>";
                        echo "<a href=\"javascript:;\" onClick=\"P7_autoLayers('menu" . $menunbr . "');return false\">" . $liens[$menunbr]['text'] . "</a>";
                        echo "</p></td></tr><tr><td class=\"menucell\">";
                        
                        foreach ($liens[$menunbr]['sub'] as $sub) {
                            $subrightname = $sub['right'];
                            $level = $sub['level'];
                            $afftest = ($subrightname == 0 || ($rights & $subrightname) > 0);
                            
                            if ($level > $getintlevel) {
                                $afftest = false;
                            }
                            
                            if ($afftest) {
                                echo "&#8226; &nbsp;";
                                echo "<a href=\"" . $sub['link'] . "\" TARGET='$menutarget'>" . $sub['text'] . "</a><br>\n";
                            }
                        }
                        echo "</td></tr>\n";
                    } else {
                        echo "<tr><td class=\"menuheader\">";
                        echo "<p style='margin:2px; padding-top:2px; padding-bottom:2px'>";
                        echo "<a href=\"javascript:;\" onClick=\"P7_autoLayers('menu" . $menunbr . "');return false\">";
                        echo "&#10515;&nbsp;" . $liens[$menunbr]['text'] . "</a></p></td></tr>\n";
                    }
                }
            }
            
            $lien = "<a href=\"./user/index.php\" TARGET='user/index.php'>Mode Utilisateur</a><br>\n";
            echo "<tr><td class=\"menuheader\">";
            echo "<p style='margin:2px; padding-top:2px; padding-bottom:2px'>" . $lien . "</p>";
            echo "</td></tr>\n";
            echo "</table></div>\n";
        }
    }

    /**
     * Liste des droits utilisateur (fonction legacy à implémenter)
     */
    protected function listRights(array $config, string $login, bool $cache = false): int
    {
        // TODO: Implémenter la récupération des droits legacy
        Log::warning('listRights not implemented yet', ['login' => $login]);
        return 0;
    }

    /**
     * Retourne le niveau de l'interface
     */
    public function getIntLevel(): int
    {
        return $_SESSION['level'] ?? 4;
    }

    /**
     * Change le niveau d'interface dans la session
     */
    public function setIntLevel(int $newLevel): void
    {
        $_SESSION['level'] = $newLevel;
    }

    /**
     * Création d'un tableau avec titre
     */
    public function mkTable(string $title, string $content): string
    {
        return "<H3>$title</H3>" . $content;
    }

    /**
     * Fonction de debug pour afficher les variables
     */
    public function debugVar(): void
    {
        echo "<div style='border: 1px solid black; background-color: white; color: black;'>\n";
        echo "<p><strong>Variables transmises en POST, GET, SESSION,...</strong></p>\n";

        // Variables POST
        echo "<p>Variables envoyées en POST: ";
        if (count($_POST) == 0) {
            echo "aucune";
        } else {
            echo "<table summary=\"Tableau de debug\">\n";
            foreach ($_POST as $post => $val) {
                echo "<tr><td valign='top'>\$_POST['" . $post . "']=</td><td>";
                if (is_array($val)) {
                    echo "Array";
                } else {
                    echo htmlspecialchars($val);
                }
                echo "</td></tr>\n";
            }
            echo "</table>\n";
        }
        echo "</p>\n";

        // Variables GET
        echo "<p>Variables envoyées en GET: ";
        if (count($_GET) == 0) {
            echo "aucune";
        } else {
            echo "<table summary=\"Tableau de debug sur GET\">";
            foreach ($_GET as $get => $val) {
                echo "<tr><td valign='top'>\$_GET['" . $get . "']=</td><td>";
                if (is_array($val)) {
                    echo "Array";
                } else {
                    echo htmlspecialchars($val);
                }
                echo "</td></tr>\n";
            }
            echo "</table>\n";
        }
        echo "</p>\n";

        // Variables SESSION
        echo "<p>Variables envoyées en SESSION: ";
        if (count($_SESSION) == 0) {
            echo "aucune";
        } else {
            echo "<table summary=\"Tableau de debug sur SESSION\">";
            foreach ($_SESSION as $variable => $val) {
                echo "<tr><td>\$_SESSION['" . $variable . "']=</td><td>" . htmlspecialchars($val) . "</td></tr>\n";
            }
            echo "</table>\n";
        }
        echo "</p>\n";

        // Variables SERVER (limitées pour éviter la surcharge)
        echo "<p>Variables SERVER principales: ";
        $serverVars = ['REQUEST_METHOD', 'REQUEST_URI', 'HTTP_HOST', 'REMOTE_ADDR', 'HTTP_USER_AGENT'];
        echo "<table summary=\"Tableau de debug sur SERVER\">";
        foreach ($serverVars as $var) {
            if (isset($_SERVER[$var])) {
                echo "<tr><td>\$_SERVER['" . $var . "']=</td><td>" . htmlspecialchars($_SERVER[$var]) . "</td></tr>\n";
            }
        }
        echo "</table>\n";
        echo "</p>\n";

        // Variables FILES
        echo "<p>Variables envoyées en FILES: ";
        if (count($_FILES) == 0) {
            echo "aucune";
        } else {
            echo "<table summary=\"Tableau de debug\">\n";
            foreach ($_FILES as $key => $val) {
                echo "<tr><td valign='top'>\$_FILES['" . $key . "']=</td><td>";
                if (is_array($val)) {
                    echo "Array";
                } else {
                    echo htmlspecialchars($val);
                }
                echo "</td></tr>\n";
            }
            echo "</table>\n";
        }
        echo "</p>\n";

        // Variables COOKIES
        echo "<p>Variables COOKIES: ";
        if (count($_COOKIE) == 0) {
            echo "aucune";
        } else {
            echo "<table summary=\"Tableau de debug sur COOKIE\">";
            foreach ($_COOKIE as $get => $val) {
                echo "<tr><td valign='top'>\$_COOKIE['" . $get . "']=</td><td>" . htmlspecialchars($val) . "</td></tr>\n";
            }
            echo "</table>\n";
        }
        echo "</p>\n";

        echo "</div>\n";
    }

    /**
     * Fonction utilitaire pour l'affichage de tableaux de debug récursifs
     */
    public function tabDebugVar(string $chaineTabNiv1, array $tableau, string $prefChaine, int $cptDebug): void
    {
        echo "<table id='container_debug_var_$cptDebug' summary=\"Tableau de debug\">\n";
        foreach ($tableau as $post => $val) {
            echo "<tr><td valign='top'>" . $prefChaine . "['" . $post . "']=</td><td>";
            
            if (is_array($val)) {
                echo "Array";
                $this->tabDebugVar($chaineTabNiv1, $val, $prefChaine . '[' . $post . ']', $cptDebug + 1);
            } else {
                echo htmlspecialchars($val);
            }
            
            echo "</td></tr>\n";
        }
        echo "</table>\n";
    }

    /**
     * Validation et nettoyage des données d'entrée
     */
    public function sanitizeInput(string $input, string $type = 'string'): string
    {
        $input = trim($input);
        
        switch ($type) {
            case 'email':
                return filter_var($input, FILTER_SANITIZE_EMAIL);
            case 'url':
                return filter_var($input, FILTER_SANITIZE_URL);
            case 'int':
                return filter_var($input, FILTER_SANITIZE_NUMBER_INT);
            case 'float':
                return filter_var($input, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            case 'html':
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            default:
                return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        }
    }

    /**
     * Validation des permissions d'accès
     */
    public function checkPermission(array $config, string $requiredRight): bool
    {
        if (empty($config['login'])) {
            return false;
        }

        $userRights = $this->listRights($config, $config['login'], true);
        return ($userRights & $requiredRight) > 0;
    }

    /**
     * Génération d'un token CSRF
     */
    public function generateCsrfToken(): string
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Vérification d'un token CSRF
     */
    public function verifyCsrfToken(string $token): bool
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Formatage des tailles de fichiers
     */
    public function formatFileSize(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    /**
     * Validation d'une adresse IP
     */
    public function isValidIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Validation d'un nom d'utilisateur SE4
     */
    public function isValidUsername(string $username): bool
    {
        // Règles SE4 : alphanumerique + quelques caractères spéciaux, longueur 3-20
        return preg_match('/^[a-zA-Z0-9._-]{3,20}$/', $username) === 1;
    }

    /**
     * Génération d'un identifiant unique
     */
    public function generateUniqueId(string $prefix = ''): string
    {
        return $prefix . uniqid() . '_' . mt_rand(1000, 9999);
    }

    /**
     * Conversion de timestamp en format lisible
     */
    public function formatTimestamp(int $timestamp, string $format = 'Y-m-d H:i:s'): string
    {
        return date($format, $timestamp);
    }

    /**
     * Vérification de la complexité d'un mot de passe
     */
    public function checkPasswordComplexity(string $password): array
    {
        $result = [
            'valid' => false,
            'errors' => []
        ];

        if (strlen($password) < 8) {
            $result['errors'][] = 'Le mot de passe doit contenir au moins 8 caractères';
        }

        if (!preg_match('/[A-Z]/', $password)) {
            $result['errors'][] = 'Le mot de passe doit contenir au moins une majuscule';
        }

        if (!preg_match('/[a-z]/', $password)) {
            $result['errors'][] = 'Le mot de passe doit contenir au moins une minuscule';
        }

        if (!preg_match('/[0-9]/', $password)) {
            $result['errors'][] = 'Le mot de passe doit contenir au moins un chiffre';
        }

        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $result['errors'][] = 'Le mot de passe doit contenir au moins un caractère spécial';
        }

        $result['valid'] = empty($result['errors']);
        return $result;
    }
}
