<?php
/**
 * Stub fonc_outils.inc.php — IN-REPO (Story 38.4, D6).
 *
 * `dhcp/baux.php` fait `require_once "fonc_outils.inc.php"` et appelle
 * `start_poste()`. On fournit une version minimale guardée (Wake-on-LAN /
 * démarrage poste) qui n'échoue jamais — l'action réelle (etherwake) est un
 * side effect système toléré, no-op en environnement de test.
 */

if (defined('LEGACY_FONC_OUTILS_INC_LOADED')) {
    return;
}
define('LEGACY_FONC_OUTILS_INC_LOADED', true);

if (!function_exists('start_poste')) {
    /**
     * Démarre un poste par Wake-on-LAN. Minimal : best-effort, jamais fatal.
     *
     * @param array  $config
     * @param string $machine  Nom de la machine
     * @param string $mac      Adresse MAC (optionnelle)
     */
    function start_poste($config, $machine = '', $mac = '')
    {
        if ($mac !== '' && preg_match('/^([0-9a-fA-F]{2}[:\-]){5}[0-9a-fA-F]{2}$/', $mac)) {
            @exec('sudo etherwake ' . escapeshellarg($mac) . ' 2>/dev/null');
        }
        return true;
    }
}

if (!function_exists('inverse_login')) {
    function inverse_login($login)
    {
        return $login;
    }
}
