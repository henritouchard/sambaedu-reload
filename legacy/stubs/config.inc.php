<?php

/**
 * Stub config.inc.php — remplace le legacy config.inc.php
 * quand un module est exécuté via LegacyEmbedService ou le catchall.
 *
 * Charge notre bridge config (legacy/config.inc.php) pour le $config array,
 * puis expose toutes les fonctions utilitaires du config.inc.php legacy
 * avec des guards function_exists pour éviter les conflits.
 *
 * Les fonctions overridées (get_config, header_authorize) utilisent
 * notre version Laravel au lieu de la version legacy.
 */

// S'assurer que notre bridge est chargé ($config array via Laravel)
require_once __DIR__ . '/../config.inc.php';

// ─── Fonctions OVERRIDÉES (version Laravel, pas legacy) ─────────────────

if (!function_exists('get_config')) {
    /**
     * Override get_config — retourne le $config déjà initialisé par notre bridge.
     * La version legacy lit /etc/sambaedu/ et se connecte en LDAP — on ne veut pas ça.
     */
    function get_config(array $inputConfig = [], bool $force = true, $global = false, $module = "all")
    {
        $globalConfig = $GLOBALS['config'] ?? [];
        // Fusionner : le $inputConfig passé en paramètre peut contenir des clés supplémentaires
        if (!empty($inputConfig)) {
            return array_merge($globalConfig, $inputConfig);
        }
        return $globalConfig;
    }
}

if (!function_exists('header_authorize')) {
    /**
     * Override header_authorize — l'utilisateur est déjà authentifié via Laravel.
     * Pas de redirection vers auth.php ni de chrome HTML legacy.
     */
    function header_authorize(&$config): string
    {
        if (function_exists('auth') && auth()->check()) {
            $user = auth()->user();
            $config['login'] = $user->login ?? '';
            $_SESSION['login'] = $config['login'];
            $_SESSION['level'] = 0;
            $_SESSION['etab'] = $config['etab_ou'] ?? '';
            $_SESSION['etab_ou'] = $config['etab_ou'] ?? '';
        }
        return '';
    }
}

// ─── Fonctions utilitaires du legacy config.inc.php (copiées telles quelles) ──

if (!function_exists('db_connect')) {
    function db_connect($config, $etab = 'localhost')
    {
        if (empty($etab)) {
            $etab = "localhost";
        }
        if ($etab != "localhost") {
            $etab = "se4fs-" . $etab;
        }
        return mysqli_connect($etab, 'sambaedu', $config['sql_passwd'], 'sambaedu');
    }
}

if (!function_exists('cache_valid')) {
    function cache_valid($name)
    {
        $ret = false;
        if (is_dir(CACHE_DIR)) {
            $files = scandir(CACHE_DIR);
            $m = [];
            foreach ($files as $file) {
                if (strpos($file, $name . ".cache@") === 0) {
                    $m = explode(".cache@", $file);
                    if ($m[1] != 0 && microtime(true) - (float) $m[1] > 0) {
                        if (file_exists(CACHE_DIR . "/" . $file)) {
                            unlink(CACHE_DIR . "/" . $file);
                        }
                    } else {
                        $ret = $file;
                    }
                }
            }
        }
        return $ret;
    }
}

if (!function_exists('cache_age')) {
    function cache_age($name)
    {
        if ($file = cache_valid($name)) {
            if (strpos($file, $name . ".cache@") === 0) {
                $m = explode(".cache@", $file);
                if ($m[1] == 0) {
                    return 0;
                } else {
                    return (microtime(true) - (float) $m[1]);
                }
            }
        } else {
            return false;
        }
    }
}

if (!function_exists('cache_fetch')) {
    function cache_fetch(string $name)
    {
        if ($file = cache_valid($name)) {
            $data = unserialize(file_get_contents(CACHE_DIR . "/" . $file));
        } else {
            $data = false;
        }
        return $data;
    }
}

if (!function_exists('cache_add')) {
    function cache_add(string $name, $data, int $ttl = 0)
    {
        if (cache_valid($name)) {
            return false;
        }
        return cache_store($name, $data, $ttl);
    }
}

if (!function_exists('cache_store')) {
    function cache_store(string $name, $data, int $ttl = 0)
    {
        if (!file_exists(CACHE_DIR)) {
            mkdir(CACHE_DIR);
        }
        if ($ttl > 0) {
            $time = microtime(true) + $ttl;
            $tmp = CACHE_DIR . "/" . $name . ".cache@" . $time;
        } else {
            $tmp = CACHE_DIR . "/" . $name . ".cache@0";
        }
        if ($file = cache_valid($name)) {
            unlink(CACHE_DIR . "/" . $file);
        }
        return file_put_contents($tmp, serialize($data));
    }
}

if (!function_exists('cache_delete')) {
    function cache_delete(string $name)
    {
        if ($file = cache_valid($name)) {
            if (file_exists(CACHE_DIR . "/" . $file)) {
                return unlink(CACHE_DIR . "/" . $file);
            }
        } else {
            return true;
        }
    }
}

if (!function_exists('cache_delete_multi')) {
    function cache_delete_multi(string $regexp)
    {
        $n = 0;
        if (is_dir(CACHE_DIR)) {
            $files = scandir(CACHE_DIR);
            foreach ($files as $file) {
                if (preg_match($regexp, $file)) {
                    if (file_exists(CACHE_DIR . "/" . $file)) {
                        unlink(CACHE_DIR . "/" . $file);
                    }
                    $n++;
                }
            }
        }
        return $n;
    }
}

if (!function_exists('apcu_delete_multi')) {
    function apcu_delete_multi(string $regexp)
    {
        return apcu_delete(new APCUIterator($regexp));
    }
}

if (!function_exists('lock_conf')) {
    function lock_conf($mode)
    {
        $lock = "/var/lock/sambaedu.lock";
        if ($fp = fopen($lock, "c")) {
            $startTime = microtime();
            do {
                $canWrite = flock($fp, $mode);
                if (!$canWrite)
                    usleep(round(rand(0, 100) * 1000));
            } while ((!$canWrite) and ((microtime() - $startTime) < 1000));
            if ($canWrite) {
                return $fp;
            }
        }
        return false;
    }
}

if (!function_exists('etab_suffix')) {
    function etab_suffix($uai)
    {
        if (preg_match("/[0-9]{7}[a-z]/i", $uai)) {
            return strtolower("-" . substr($uai, 3));
        } else {
            return "";
        }
    }
}

if (!function_exists('is_local')) {
    function is_local($config)
    {
        return empty($config['etab_ou']) || !isset($config['central_se4fs_ip']);
    }
}

if (!function_exists('get_config_file')) {
    function get_config_file(string $module = "sambaedu", bool|string $global = true, bool $old = false, bool $table = false)
    {
        if ($old) {
            $ext = "-old";
        } else {
            $ext = "";
        }
        $config = array();
        if ($module == "all") {
            $config = get_config_file("sambaedu", $global, $old, $table);
            if ($handle = opendir('/etc/sambaedu/sambaedu.conf.d')) {
                unset($module);
                while (false !== ($entry = readdir($handle))) {
                    if ($entry != "." && $entry != "..") {
                        $module = preg_replace("/\.conf" . $ext . "/", "", $entry);
                        if (isset($module)) {
                            $config = array_merge($config, get_config_file($module, $global, $old, $table));
                        }
                    }
                }
                closedir($handle);
            }
        } elseif ($module == "sambaedu") {
            $conf_file = "/etc/sambaedu/sambaedu.conf" . $ext;
            if (!file_exists($conf_file)) {
                return $config;
            }
            $config = parse_ini_file($conf_file);
            if ($table) {
                $t = [];
                foreach ($config as $p => $v) {
                    $t[$p] = [
                        'module' => $module,
                        'valeur' => $v
                    ];
                }
                $config = $t;
            } else {
                $config['etablissements_rdn'] = $config['etablissements_rdn'] ?? "OU=etablissements";
                $config['matieres_rdn'] = $config['matieres_rdn'] ?? "OU=Matieres";
                if ($global === false && !empty($config['etab_ou'])) {
                    $global = $config['etab_ou'];
                }
                if (!preg_match("/[0-9]{7}[a-z]/i", $global)) {
                    $config['suffix'] = "";
                } else {
                    $config['etab_ou'] = $global;
                    $config['people_rdn'] = "OU=" . $config['etab_ou'] . "," . $config['people_rdn'];
                    $config['groups_rdn'] = "OU=" . $config['etab_ou'] . "," . $config['groups_rdn'];
                    $config['equipements_rdn'] = "OU=" . $config['etab_ou'] . "," . $config['equipements_rdn'];
                    $config['delegations_rdn'] = "OU=" . $config['etab_ou'] . "," . $config['delegations_rdn'];
                    $config['parcs_rdn'] = "OU=" . $config['etab_ou'] . "," . $config['parcs_rdn'];
                    $config['computers_rdn'] = "OU=" . $config['etab_ou'] . "," . $config['computers_rdn'];
                    $config['suffix'] = etab_suffix($config['etab_ou']);
                }
                $config['dn']['people'] = $config['people_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['Eleves'] = "OU=Eleves," . $config['people_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['Profs'] = "OU=Profs," . $config['people_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['Administratifs'] = "OU=Administratifs," . $config['people_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['groups'] = $config['groups_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['rights'] = $config['rights_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['equipements'] = $config['equipements_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['etablissements'] = $config['etablissements_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['delegations'] = $config['delegations_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['matieres'] = $config['matieres_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['trash'] = $config['trash_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['parcs'] = $config['parcs_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['computers'] = $config['computers_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['autres'] = $config['other_groups_rdn'] . "," . $config['groups_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['projets'] = $config['projets_rdn'] . "," . $config['groups_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['cours'] = $config['cours_rdn'] . "," . $config['groups_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['classes'] = $config['classes_rdn'] . "," . $config['groups_rdn'] . "," . $config['ldap_base_dn'];
                $config['dn']['equipes'] = $config['equipes_rdn'] . "," . $config['groups_rdn'] . "," . $config['ldap_base_dn'];
            }
        } else {
            $conf_file = "/etc/sambaedu/sambaedu.conf.d/$module.conf" . $ext;
            if (file_exists($conf_file)) {
                if ($lock = lock_conf(LOCK_SH)) {
                    $config = parse_ini_file($conf_file);
                    if ($table) {
                        $t = [];
                        foreach ($config as $p => $v) {
                            $t[$p] = [
                                'module' => $module,
                                'valeur' => $v
                            ];
                        }
                        $config = $t;
                    }
                    fclose($lock);
                }
            }
        }
        return ($config);
    }
}

if (!function_exists('compare_config')) {
    function compare_config($module = "all")
    {
        $conf = get_config_file($module, true, false, false);
        $old_conf = get_config_file($module, true, true, false);
        $conf_diff = @array_diff_assoc($conf, $old_conf);
        $conf_reverse_diff = @array_diff_assoc($old_conf, $conf);
        $diff = array_merge($conf_reverse_diff, $conf_diff);
        if ($old_diff = apcu_fetch('diff')) {
            $diff = array_merge($old_diff, $diff);
        }
        return $diff;
    }
}

if (!function_exists('set_config_action')) {
    function set_config_action($param)
    {
        exec("sudo -c '/usr/share/sambaedu/scripts/config_action.sh $param'", $ret);
        return ($ret);
    }
}

if (!function_exists('set_config')) {
    function set_config($config, $param, $value = "", $module = "sambaedu")
    {
        $new_config = get_config_file($module, true, false, false);
        if ($param != "etab_ou") {
            if (empty($value)) {
                if (isset($new_config[$param]))
                    unset($new_config[$param]);
            } else {
                $new_config[$param] = $value;
            }
        }
        $content = "";
        foreach ($new_config as $key => $v) {
            if (!($key == "dn" || $key == "login" || empty($v) || empty($key))) {
                $content .= $key . " = \"" . $v . "\"\n";
            }
        }
        if ($module == "sambaedu") {
            $conf_file = "/etc/sambaedu/sambaedu.conf";
        } else {
            $conf_file = "/etc/sambaedu/sambaedu.conf.d/$module.conf";
        }
        if ($lock = lock_conf(LOCK_EX)) {
            if (!$handle = fopen($conf_file, "w")) {
                fclose($lock);
                die("Erreur d'ecriture de la configuration se4 : $module $param $value");
            }
            $res = fwrite($handle, $content);
            fclose($handle);
            fclose($lock);
        } else {
            die("Erreur d'ecriture de la configuration se4 : $module $param $value");
        }
        return get_config($config, true);
    }
}

if (!function_exists('init_param')) {
    function init_param(&$config, $nom, $valeur, $module = "sambaedu")
    {
        if (!isset($config[$nom])) {
            $config = set_config($config, $nom, $valeur, $module);
        }
    }
}

if (!function_exists('set_param')) {
    function set_param(&$config, $nom, $valeur, $module = "sambaedu")
    {
        $config = set_config($config, $nom, $valeur, $module);
        return $valeur;
    }
}

if (!function_exists('get_param')) {
    function get_param($config, $nom)
    {
        if (isset($config[$nom])) {
            return $config[$nom];
        } else {
            return "";
        }
    }
}

if (!function_exists('replace_param')) {
    function replace_param($config, $param, $valeur)
    {
        $config_table = get_config_file("all", true, false, true);
        $old_v = $config_table[$param]['valeur'];
        foreach ($config_table as $p => $table) {
            if (preg_match("|" . $old_v . "|", $table['valeur']) == 1) {
                $config = set_config($config, $p, preg_replace("|" . $old_v . "|", $valeur, $table['valeur']), $table['module']);
            }
        }
        return $config;
    }
}

if (!function_exists('write_param')) {
    function write_param(array $params, array &$lignes)
    {
        $l = $lignes;
        foreach ($params as $param => $valeur) {
            if (is_string($param) && (is_string($valeur) || is_int($valeur))) {
                $lignes = preg_replace("/###_" . strtoupper($param) . "_###/", $valeur, $lignes);
            }
        }
        $lignes = preg_replace("/###_(.*)_###/U", "", $lignes);
        return ($l != $lignes);
    }
}

if (!function_exists('batch_command')) {
    function batch_command($command)
    {
        $batch = apcu_fetch("batch_" . session_id());
        $batch .= $command . "\n";
        apcu_store("batch_" . session_id(), $batch, 500);
    }
}

if (!function_exists('batch_write')) {
    function batch_write($queue = "normal")
    {
        $batch = apcu_fetch("batch_" . session_id()) ?? "";
        if (file_exists("/tmp/admin_script_$queue.sh")) {
            $script = file_get_contents("/tmp/admin_script_$queue.sh");
        } else {
            $script = "#!/bin/bash\n";
            $script .= "rm -f /tmp/admin_script_$queue.sh\n";
        }
        $script .= $batch;
        file_put_contents("/tmp/admin_script_$queue.sh", $script);
        system("sudo chmod 755 /tmp/admin_script_$queue.sh");
        apcu_delete("batch_" . session_id());
    }
}

if (!function_exists('ad_url')) {
    function ad_url($config, $mode = "ldap", $master = false)
    {
        if ($master || empty($url = apcu_fetch("ad_url_" . $mode))) {
            $dns = $config['se4ad_ip'];
            if ($master) {
                $kdc[1] = $config['se4ad_ip'];
            } else {
                if (!empty($config['se4ad_etab_ip'])) {
                    $kdc[0] = $config['se4ad_etab_ip'];
                    $kdc[1] = $config['se4ad_ip'];
                } else {
                    $kdc[0] = $config['se4ad_ip'];
                }
                $resolv = array_merge($kdc, file("/etc/resolv.conf"));
                foreach ($resolv as $line) {
                    $m = [];
                    if (preg_match("/([0-9]*\.[0-9]*\.[0-9]*\.[0-9]*)/", $line, $m)) {
                        $res = 0;
                        $out = [];
                        exec("host -W1 " . $config['domain'] . " " . $m[1] . " 2>&1", $out, $res);
                        if ($res == 0) {
                            $dns = $m[1];
                            $out = array_merge($kdc, $out);
                            foreach ($out as $l) {
                                $i = [];
                                if (preg_match("/^(.*has address )?([0-9]*\.[0-9]*\.[0-9]*\.[0-9]*)/", $l, $i)) {
                                    $re = 0;
                                    system("host -W1 " . $config['domain'] . " " . $i[2] . " >/dev/null 2>&1", $re);
                                    if ($re == 0) {
                                        if (!in_array($i[2], $kdc)) {
                                            $kdc[] = $i[2];
                                        }
                                        if (!empty($config['etab_ou']) || count($kdc) > 4) {
                                            break;
                                        }
                                    } else {
                                        if ($k = array_search($i[2], $kdc)) {
                                            unset($kdc[$k]);
                                        }
                                    }
                                }
                            }
                            break;
                        }
                    }
                }
            }
            $url = "";
            unset($resolv);
            $resolv[0] = "domain " . $config['domain'];
            foreach ($kdc as $n) {
                switch ($mode) {
                    case "sambatool":
                        $res = 0;
                        $out = [];
                        exec("host " . $n . " " . $dns . "| grep pointer", $out, $res);
                        if ($res == 0) {
                            foreach ($out as $l) {
                                $m = [];
                                if (preg_match("/pointer (.*)\..*$/", $l, $m)) {
                                    $url .= "-H ldap://" . $m[1] . " ";
                                    break;
                                }
                            }
                        } else {
                            $url .= "-H ldap://" . $config['se4ad_name'] . " ";
                        }
                        break 2;
                    case "dns":
                        $res = 0;
                        $out = [];
                        exec("host " . $n . " " . $dns . "| grep pointer", $out, $res);
                        if ($res == 0) {
                            foreach ($out as $l) {
                                $m = [];
                                if (preg_match("/pointer (.*)\..*$/", $l, $m)) {
                                    $url .= $m[1];
                                    break;
                                }
                            }
                        } else {
                            $url .= $config['se4ad_name'] . " ";
                        }
                        break 2;
                    case "ldaps":
                        $url .= "ldaps://" . $n . " ";
                        break 2;
                    default:
                        $url .= "ldap://" . $n . " ";
                        break 2;
                }
                $resolv[] = "nameserver " . $n;
            }
            if (!$master && count($resolv) > 1) {
                file_put_contents("/tmp/resolv.conf", implode("\n", $resolv) . "\n");
                exec("sudo cp -f /tmp/resolv.conf /etc/resolv.conf");
                exec("sudo chown root /etc/resolv.conf");
                apcu_store("ad_url_" . $mode, $url, 300);
            }
        }
        return $url;
    }
}

if (!function_exists('curl_proxy_options')) {
    function curl_proxy_options($config, &$opt = [])
    {
        $proxy = true;
        if (empty($config['server_proxy']) || empty($config['proxy_address']) || empty($config['proxy_port'])) {
            $proxy = false;
        } else if (!empty($opt['base_uri'])) {
            putenv('RES_OPTIONS=retrans:1 retry:1 timeout:1 attempts:1');
            $ip = gethostbyname(parse_url($opt['base_uri'])['host']);
            if (!empty($config['mask']) && !empty($config['network'])) {
                if ((ip2long($ip) & ip2long($config['mask'])) === ip2long($config['network'])) {
                    $proxy = false;
                }
            }
        }
        if ($proxy) {
            $opt['curl'] = [
                CURLOPT_PROXY => "http://" . $config['proxy_address'] . ":" . $config['proxy_port'],
                CURLOPT_NOPROXY => $config['no_proxy'] ?? "",
            ];
        }
        $opt['curl'][CURLOPT_SSL_VERIFYPEER] = false;
        $opt['curl'][CURLOPT_SSL_VERIFYHOST] = false;
        $opt['curl'][CURLOPT_CONNECTTIMEOUT_MS] = 2000;
    }
}

if (!function_exists('set_git_proxy')) {
    function set_git_proxy($config)
    {
        if (!empty($config['server_proxy']) && !empty($config['proxy_address']) && !empty($config['proxy_port'])) {
            $command = "git config --global http.proxy http://" . $config['proxy_address'] . ":" . $config['proxy_port'];
        } else {
            $command = "git config --global --unset http.proxy";
        }
        exec($command);
    }
}

if (!function_exists('set_oauth_proxy')) {
    function set_oauth_proxy($config, &$opt)
    {
        if (!empty($config['server_proxy']) && !empty($config['proxy_address']) && !empty($config['proxy_port'])) {
            $opt['proxy'] = "http://" . $config['proxy_address'] . ":" . $config['proxy_port'];
            $opt['verify'] = false;
        }
    }
}

if (!function_exists('sudo_proxy')) {
    function sudo_proxy($config)
    {
        if (!empty($config['server_proxy']) && !empty($config['proxy_address']) && !empty($config['proxy_port'])) {
            $no_proxy = $config['no_proxy'] ?? "";
            $ret = "sudo env https_proxy=http://" . $config['proxy_address'] . ":" . $config['proxy_port'] . " env no_proxy=\"$no_proxy\"  ";
        } else {
            $ret = "sudo ";
        }
        return $ret;
    }
}

if (!function_exists('replace_element')) {
    function replace_element($config, $name, $default)
    {
        if (!empty($config[$name])) {
            return $config[$name];
        } else {
            return $default;
        }
    }
}

if (!function_exists('header_authorize_script')) {
    function header_authorize_script($config)
    {
        $se4_key = $_POST['se4_key'] ?? $_GET['se4_key'] ?? "";
        if (!($_SERVER['REMOTE_ADDR'] == $config['se4fs_ip'] && $se4_key == $config["se4_key"])) {
            header($_SERVER["SERVER_PROTOCOL"] . " 403 Forbidden");
            header("Location:/interdit.html");
            exit();
        } else {
            @session_name("Sambaedu");
            @session_start();
            header("Content-type: text/plain");
        }
    }
}

if (!function_exists('put_ini_file')) {
    function put_ini_file($config, $file, $has_section = false, $write_to_file = true)
    {
        $fileContent = '';
        if (!empty($config) && is_array($config)) {
            foreach ($config as $i => $v) {
                if ($has_section) {
                    $fileContent .= "\n[$i]" . PHP_EOL . put_ini_file($v, $file, false, false);
                } else {
                    if (is_array($v)) {
                        foreach ($v as $t => $m) {
                            if ($i == "php_flag") {
                                $fileContent .= $i . "[" . $t . "]=" . (is_numeric($m) ? $m : $m) . PHP_EOL;
                            } else {
                                $fileContent .= $i . "[" . $t . "]=" . (is_numeric($m) ? $m : '"' . $m . '"') . PHP_EOL;
                            }
                        }
                    } else
                        $fileContent .= $i . "=" . (is_numeric($v) ? $v : '"' . $v . '"') . PHP_EOL;
                }
            }
        }
        if ($write_to_file && strlen($fileContent))
            return file_put_contents($file, $fileContent, LOCK_EX);
        else
            return $fileContent;
    }
}

if (!function_exists('get_fpm_conf')) {
    function get_fpm_conf(string $file)
    {
        $php_version = PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;
        $php_conf_path = "/etc/php/" . $php_version;
        $old = parse_ini_file($php_conf_path . $file, true);
        return $old;
    }
}

if (!function_exists('set_fpm_conf')) {
    function set_fpm_conf(array $conf, string $file, bool $section = true)
    {
        $php_version = PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;
        $php_conf_path = "/etc/php/" . $php_version;
        $old = parse_ini_file($php_conf_path . $file, true);
        $new = array_replace_recursive($old, $conf);
        if ($mod = ($new != $old)) {
            system("sudo chown www-admin " . $php_conf_path . $file);
            $mod = put_ini_file($new, $php_conf_path . $file, $section, true);
            system("sudo chown root " . $php_conf_path . $file);
        }
        return $mod;
    }
}

if (!function_exists('configure_fpm')) {
    function configure_fpm($config)
    {
        $fpm = get_fpm_conf("/fpm/pool.d/sambaedu.conf");
        $memory_limit = "2G";
        $apc_shm_size = "1024M";
        if (count(activated_etabs($config)) > 1) {
            $memory_limit = "16G";
            $apc_shm_size = "4096M";
        }
        if (!empty($config['etab_ou'])) {
            $memory_limit = "3G";
            $apc_shm_size = "2048M";
        }
        $fpm['sambaedu'] = array_merge($fpm['sambaedu'], [
            'pm' => "dynamic",
            'pm.max_children' => "40",
            'pm.start_servers' => "15",
            'pm.min_spare_servers' => "15",
            'pm.max_spare_servers' => "25",
            'pm.max_requests' => "200",
            'php_value' => [
                'include_path' => ".:/var/www/sambaedu/includes:/var/www/sambaedu/central/php/includes",
                'max_execution_time' => "300",
                'max_input_vars' => "3000",
                'upload_max_filesize' => "8M",
                'memory_limit' => $memory_limit,
                'apc.shm_size' => $apc_shm_size,
                'apc.ttl' => "1200",
                'date.timezone' => "Europe/Paris"
            ]
        ]);
        if (!empty($config['redis_key'])) {
            $fpm['sambaedu']['php_value'] = array_merge($fpm['sambaedu']['php_value'], [
                'session.save_handler' => "redis",
                'session.save_path' => "tcp://se4fs:6379?auth=" . $config['redis_key']
            ]);
        }
        $mod = set_fpm_conf($fpm, "/fpm/pool.d/sambaedu.conf");
        $mod &= set_debug($config);
        if ($mod) {
            batch_command("systemctl reload php" . PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION . "-fpm.service");
            batch_write("fast");
        }
        return $mod;
    }
}

setlocale(LC_ALL, "C");
