<?php

/**
 * Shim DHCP — story 1bis-16.
 *
 * Complète le shim LDAP (`legacy/ldap.inc.php`) avec les fonctions DHCP utilisées
 * par les 6 scripts de `legacy/modules/dhcp/` :
 *  - export_dhcp_reservations, import_dhcp_reservations  (make_reservations.php,
 *    script_make_reservations.php, import_reservations.php)
 *  - set_dhcp_reservation, get_dhcp_reservation, delete_dhcp_reservation
 *  - list_dhcp_leases, import_dhcp_leases                (baux.php)
 *  - valid_mac, format_mac                               (helpers MAC)
 *
 * Sans ces shims, `make_reservations.php` produit `Fatal error: Call to undefined
 * function export_dhcp_reservations()` en production (le shim LDAP est prioritaire
 * dans include_path et empêche le chargement du vrai sambaedu/includes/ldap.inc.php).
 *
 * STRATÉGIE :
 *  - `export_dhcp_reservations` / `import_dhcp_reservations` : copie quasi-verbatim
 *    du legacy — elles utilisent `search_ad()` déjà shimmée (Eloquent) et écrivent
 *    via temp+rename atomique (contrat avec make_dhcpd_conf.sh).
 *  - `set_dhcp_reservation` / `delete_dhcp_reservation` : NOP loguant
 *    `_shim_log_unimplemented` — la modification AD (modify_ad write) n'est pas
 *    supportée par le shim (pas d'équivalent Eloquent → AD write). Retourne false.
 *  - `list_dhcp_leases` / `import_dhcp_leases` : parse `/var/lib/dhcp/dhcpd.leases`
 *    si présent, sinon retourne []. Pas de NOP strict — le parsing est pur.
 *  - `valid_mac` / `format_mac` / `get_dhcp_reservation` : réimplémentation pure.
 *
 * Guard `function_exists` partout pour ne pas entrer en conflit avec
 * `sambaedu/includes/ldap.inc.php` / `dhcpd.inc.php` si jamais ces fichiers sont
 * chargés en amont (non attendu — bootstrap.php les commente intentionnellement).
 */

if (defined('LEGACY_DHCP_SHIM_LOADED')) {
    return;
}
define('LEGACY_DHCP_SHIM_LOADED', true);

// ─── Helpers MAC ────────────────────────────────────────────────────────────

if (!function_exists('valid_mac')) {
    function valid_mac($mac)
    {
        $tab_mac = explode(':', (string) $mac);
        if (count($tab_mac) !== 6) {
            return false;
        }
        $mac = strtoupper((string) $mac);
        $l = strlen($mac);
        for ($i = 0; $i < $l; $i++) {
            $c = substr($mac, $i, 1);
            if (!preg_match("/[A-F0-9:]/", $c)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('format_mac')) {
    function format_mac($ch_mac)
    {
        $ch_mac = strtoupper((string) $ch_mac);
        $parts = explode(':', $ch_mac);
        if (count($parts) !== 6) {
            return '';
        }
        foreach ($parts as &$p) {
            $p = str_pad($p, 2, '0', STR_PAD_LEFT);
        }
        return implode(':', $parts);
    }
}

// ─── Reservations (AD-side) ─────────────────────────────────────────────────

if (!function_exists('get_dhcp_reservation')) {
    function get_dhcp_reservation($config, $machine)
    {
        $res = search_ad($config, $machine, 'machine');
        if (
            isset($res[0]['iphostnumber'])
            && isset($res[0]['networkaddress'])
            && ($res[0]['dhcp_state'] ?? '') === 'reservation'
        ) {
            return [
                'cn' => $res[0]['cn'],
                'iphostnumber' => $res[0]['iphostnumber'],
                'networkaddress' => $res[0]['networkaddress'],
            ];
        }
        return false;
    }
}

if (!function_exists('delete_dhcp_reservation')) {
    function delete_dhcp_reservation($config, $machine)
    {
        if (function_exists('_shim_log_unimplemented')) {
            _shim_log_unimplemented("delete_dhcp_reservation(machine={$machine})");
        }
        return false;
    }
}

if (!function_exists('set_dhcp_reservation')) {
    function set_dhcp_reservation($config, $cn, $ip = '', $mac = '', &$html = '')
    {
        if (function_exists('_shim_log_unimplemented')) {
            _shim_log_unimplemented("set_dhcp_reservation(cn={$cn}, ip={$ip})");
        }
        return false;
    }
}

// ─── Export / Import fichier /etc/sambaedu/reservations.inc ────────────────

if (!function_exists('export_dhcp_reservations')) {
    function export_dhcp_reservations($config)
    {
        $reservations = $config['dhcp_reservations_file'] ?? '/etc/sambaedu/reservations.inc';
        $content = "# reservations exportees automatiquement de l'annuaire AD\n";
        $content .= "# le " . date(DATE_RSS) . "\n";

        $machines = search_ad($config, '*', 'machine_fast', 'all');
        if (!is_array($machines)) {
            $machines = [];
        }
        foreach ($machines as $machine) {
            if (!empty($machine['networkaddress']) && !valid_mac($machine['networkaddress'])) {
                delete_dhcp_reservation($config, $machine['cn']);
                unset($machine['networkaddress']);
            }
            $res = (!empty($machine['networkaddress']) && !empty($machine['iphostnumber']));
            if ($res) {
                $content .= "host " . preg_replace("/ /", "-", $machine['cn']) . "\n";
                $content .= "{\n";
                $content .= "hardware ethernet " . $machine['networkaddress'] . ";\n";
                $content .= "fixed-address " . $machine['iphostnumber'] . ";\n";
                $content .= "}\n";
            }
        }

        // Écriture atomique : temp + rename (contrat avec make_dhcpd_conf.sh)
        $dir = dirname($reservations);
        if (!is_dir($dir) || !is_writable($dir)) {
            return false;
        }
        $tmp = @tempnam($dir, 'reservations.');
        if ($tmp === false) {
            return false;
        }
        if (@file_put_contents($tmp, $content) === false) {
            @unlink($tmp);
            return false;
        }
        if (!@rename($tmp, $reservations)) {
            @unlink($tmp);
            return false;
        }
        @chmod($reservations, 0644);
        return true;
    }
}

if (!function_exists('import_dhcp_reservations')) {
    function import_dhcp_reservations($config)
    {
        $source = $config['dhcp_reservations_se3_file'] ?? '/etc/sambaedu/reservations.inc.se3';
        if (!file_exists($source)) {
            return '';
        }
        $contents = explode("\n", file_get_contents($source));
        $index = 0;
        $data = [];
        $html = '';
        $record = false;
        foreach ($contents as $line) {
            $m = [];
            if (preg_match("/^\s*(|#.*)$/", $line)) {
                // commentaire / ligne vide
            } elseif (preg_match("/^host (.*)$/", $line, $m) && !$record) {
                $index++;
                $data[$index]['cn'] = strtolower((string) $m[1]);
            } elseif (preg_match("/^{/", $line)) {
                $record = true;
            } elseif (preg_match("/^\s*hardware ethernet\s*([0-9a-fA-F:]*)\s*;$/", $line, $m)) {
                $data[$index]['networkaddress'] = strtolower($m[1]);
            } elseif (preg_match("/^\s*fixed-address\s*([0-9\.]*)\s*;$/", $line, $m)) {
                $data[$index]['iphostnumber'] = $m[1];
            } elseif (preg_match("/}*/", $line)) {
                $record = false;
            } else {
                $html .= "Erreur ligne '{$line}'\n";
                break;
            }
        }
        foreach ($data as $reservation) {
            if (empty($reservation['cn'])) {
                continue;
            }
            if (!search_ad($config, $reservation['cn'], 'machine')) {
                if (function_exists('create_machine')) {
                    create_machine($config, $reservation['cn'], $config['equipements_rdn'] ?? '');
                }
            }
            $res = set_dhcp_reservation(
                $config,
                $reservation['cn'],
                $reservation['iphostnumber'] ?? '',
                $reservation['networkaddress'] ?? ''
            );
            if ($res) {
                $html .= "machine " . $reservation['cn'] . ": ip " . ($reservation['iphostnumber'] ?? '') . " OK\n";
            } else {
                $html .= "machine " . $reservation['cn'] . ": ip " . ($reservation['iphostnumber'] ?? '') . " ERREUR\n";
            }
        }
        return $html;
    }
}

// ─── Leases (lecture /var/lib/dhcp/dhcpd.leases) ────────────────────────────

if (!function_exists('import_dhcp_leases')) {
    function import_dhcp_leases($config)
    {
        if (!empty($config['dhcp_external'])) {
            return [];
        }
        $leasesFile = $config['dhcp_leases_file'] ?? '/var/lib/dhcp/dhcpd.leases';
        if (!file_exists($leasesFile)) {
            return [];
        }
        $sum = @filemtime($leasesFile);
        $cacheKey = 'dhcp_' . $sum;
        if (extension_loaded('apcu') && function_exists('apcu_fetch')) {
            $cached = apcu_fetch($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $contents = explode("\n", (string) @file_get_contents($leasesFile));
        $current = 0;
        $data = [];
        foreach ($contents as $line) {
            if ($current === 0) {
                if (preg_match("/^\s*(|autho.*|#.*)$/", $line)) {
                    continue;
                }
                $m = [];
                if (preg_match("/^lease (.*) \{/", $line, $m)) {
                    $current = $m[1];
                }
            } else {
                $m = [];
                if (preg_match('/^\s*(binding state|hardware ethernet|client-hostname|uid|set vendor-class-identifier =) "?(.*?)"?;$/', $line, $m)) {
                    $data[$current][$m[1]] = $m[2];
                } elseif (preg_match("/}/", $line)) {
                    $current = 0;
                }
            }
        }
        $ret = [];
        foreach ($data as $ip => $d) {
            $state = $d['binding state'] ?? '';
            if (
                ($state === 'active' || $state === 'free')
                && isset($d['hardware ethernet'])
                && (!empty($d['client-hostname']) || isset($d['uid']))
            ) {
                $ret[] = [
                    'name' => strtolower($d['client-hostname'] ?? 'ipxe'),
                    'ip' => $ip,
                    'mac' => strtolower($d['hardware ethernet'] ?? ''),
                    'state' => $state,
                ];
            }
        }
        if (extension_loaded('apcu') && function_exists('apcu_store')) {
            @apcu_store($cacheKey, $ret, 300);
        }
        return $ret;
    }
}

if (!function_exists('list_dhcp_leases')) {
    function list_dhcp_leases($config)
    {
        $parser = import_dhcp_leases($config);
        $machines = search_ad($config, '*', 'machine');
        if (!is_array($machines)) {
            $machines = [];
        }
        foreach ($parser as $key => $lease) {
            foreach ($machines as $machine) {
                $mac = $machine['networkaddress'] ?? null;
                if ($mac === null || $mac !== $lease['mac']) {
                    continue;
                }
                $state = $machine['dhcp_state'] ?? '';
                if ($state === 'reservation') {
                    unset($parser[$key]);
                    break;
                }
                $parser[$key]['state'] = $state;
            }
        }
        return array_values($parser);
    }
}
