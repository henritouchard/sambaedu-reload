<?php

/**
 * Config Bridge Legacy → Laravel.
 *
 * Expose les variables de configuration legacy (constantes, globales, $config)
 * depuis config('sambaedu.*') Laravel.
 *
 * Correspondance legacy → Laravel :
 *  - $config['ldap_base_dn']       → config('sambaedu.legacy_ldap.base_dn')
 *  - $config['ldap_admin_name']    → config('sambaedu.legacy_ldap.bind_dn')
 *  - $config['ldap_admin_passwd']  → config('sambaedu.legacy_ldap.bind_password')
 *  - $config['se4ad_ip']           → config('sambaedu.se4ad_ip')
 *  - $config['etab_ou']            → config('sambaedu.etab_ou')
 *  - $config['domain']             → (extrait du base_dn)
 *  - $config['login']              → auth()->user()->login ?? ''
 *  - Constantes WOL/shutdown/WPKG  → définies statiquement (identiques au legacy)
 */

// Guard : ne charger qu'une seule fois
if (defined('LEGACY_CONFIG_LOADED')) {
    return;
}
define('LEGACY_CONFIG_LOADED', true);

// ─── Constantes legacy (identiques à config.inc.php original) ────────────────

if (!defined('SAMBAEDU_NO_WOL'))              define('SAMBAEDU_NO_WOL', 1);
if (!defined('SAMBAEDU_NO_WOL_LOG'))          define('SAMBAEDU_NO_WOL_LOG', 2);
if (!defined('SAMBAEDU_NO_SHUTDOWN'))          define('SAMBAEDU_NO_SHUTDOWN', 4);
if (!defined('SAMBAEDU_NO_SHUTDOWN_LOG'))      define('SAMBAEDU_NO_SHUTDOWN_LOG', 8);
if (!defined('SAMBAEDU_SHUTDOWN_ERROR'))       define('SAMBAEDU_SHUTDOWN_ERROR', 16);
if (!defined('SAMBAEDU_NO_IPXE'))             define('SAMBAEDU_NO_IPXE', 32);
if (!defined('SAMBAEDU_NO_LOGON_LOG'))        define('SAMBAEDU_NO_LOGON_LOG', 64);
if (!defined('SAMBAEDU_NO_LOGOFF_LOG'))       define('SAMBAEDU_NO_LOGOFF_LOG', 128);
if (!defined('SAMBAEDU_STARTUP_APP_ERROR'))   define('SAMBAEDU_STARTUP_APP_ERROR', 256);
if (!defined('SAMBAEDU_SHUTDOWN_APP_ERROR'))  define('SAMBAEDU_SHUTDOWN_APP_ERROR', 512);
if (!defined('SAMBAEDU_LOGON_APP_ERROR'))     define('SAMBAEDU_LOGON_APP_ERROR', 1024);
if (!defined('SAMBAEDU_LOGOFF_APP_ERROR'))    define('SAMBAEDU_LOGOFF_APP_ERROR', 2048);
if (!defined('SAMBAEDU_LOGON_SYS_APP_ERROR'))  define('SAMBAEDU_LOGON_SYS_APP_ERROR', 4096);
if (!defined('SAMBAEDU_LOGOFF_SYS_APP_ERROR')) define('SAMBAEDU_LOGOFF_SYS_APP_ERROR', 8192);
if (!defined('SAMBAEDU_WPKG_RUNNING'))        define('SAMBAEDU_WPKG_RUNNING', 16384);
if (!defined('SAMBAEDU_WPKG_ERROR'))          define('SAMBAEDU_WPKG_ERROR', 32768);
if (!defined('SAMBAEDU_CLOUD_ERROR'))         define('SAMBAEDU_CLOUD_ERROR', 0x10000);
if (!defined('SAMBAEDU_SLOW_NET_ERROR'))      define('SAMBAEDU_SLOW_NET_ERROR', 0x20000);
if (!defined('CACHE_DIR'))                    define('CACHE_DIR', '/tmp/phpcache');

// ─── $config array — pont vers la config Laravel ────────────────────────────
// Le code legacy attend $config comme variable globale.
// La logique est extraite dans legacy_build_config() pour être testable
// indépendamment du guard LEGACY_CONFIG_LOADED.

/**
 * Construit le tableau $config legacy depuis la config Laravel.
 *
 * Peut être appelée plusieurs fois (pas de side-effects globaux) :
 * utile dans les tests pour rejouer la logique avec des valeurs différentes.
 */
function legacy_build_config(): array
{
    // Hydrate toutes les clés brutes de /etc/sambaedu/sambaedu.conf — le legacy
    // les consomme directement (samba_domain, cloud_name, proxy_*, pronote_*,
    // openent_*, wpkg, dhcp, etc.). Sans ce merge, generalise_gpo /
    // specialise_gpo produisent des substitutions vides → import/export GPO
    // cassé. Les clés LDAP calculées ci-dessous écrasent celles du fichier.
    $confPath = '/etc/sambaedu/sambaedu.conf';
    $c = is_readable($confPath) ? (@parse_ini_file($confPath) ?: []) : [];

    // LDAP / AD — source unique : SambaEduConfig (/etc/sambaedu/sambaedu.conf)
    // partagée avec le reste de SER (ldap-record, AuthenticationService, etc.).
    $ldap = app(\App\Config\SambaEduConfig::class)->ldap();
    $c['ldap_base_dn']      = $ldap->baseDn;
    $c['ldap_admin_name']   = $ldap->adminName;
    $c['ldap_admin_passwd'] = $ldap->adminPassword;
    $c['domain']            = $ldap->domain;
    $c['se4ad_ip']          = $ldap->serverIp ?? '';
    $c['se4ad_etab_ip']     = $ldap->etabServerIp ?? '';

    // Établissement
    $c['etab_ou'] = config('sambaedu.etab_ou', '');
    $c['suffix']  = '';
    if (preg_match('/[0-9]{7}[a-z]/i', $c['etab_ou'])) {
        $c['suffix'] = strtolower('-' . substr($c['etab_ou'], 3));
    }

    // OUs — valeurs par défaut legacy
    $c['people_rdn']         = 'OU=people';
    $c['groups_rdn']         = 'OU=groups';
    $c['equipements_rdn']    = 'OU=equipements';
    $c['computers_rdn']      = 'OU=computers';
    $c['delegations_rdn']    = 'OU=delegations';
    $c['parcs_rdn']          = 'OU=parcs';
    $c['rights_rdn']         = 'OU=rights';
    $c['trash_rdn']          = 'OU=trash';
    $c['etablissements_rdn'] = 'OU=etablissements';
    $c['matieres_rdn']       = 'OU=Matieres';
    $c['cours_rdn']          = 'OU=Cours';
    $c['classes_rdn']        = 'OU=Classes';
    $c['equipes_rdn']        = 'OU=Equipes';
    $c['projets_rdn']        = 'OU=Projets';
    $c['other_groups_rdn']   = 'OU=Autres';

    // Appliquer le préfixe UAI si présent (comme get_config_file le fait)
    if (preg_match('/[0-9]{7}[a-z]/i', $c['etab_ou'])) {
        $uaiPrefix = 'OU=' . $c['etab_ou'] . ',';
        $c['people_rdn']      = $uaiPrefix . $c['people_rdn'];
        $c['groups_rdn']      = $uaiPrefix . $c['groups_rdn'];
        $c['equipements_rdn'] = $uaiPrefix . $c['equipements_rdn'];
        $c['delegations_rdn'] = $uaiPrefix . $c['delegations_rdn'];
        $c['parcs_rdn']       = $uaiPrefix . $c['parcs_rdn'];
        $c['computers_rdn']   = $uaiPrefix . $c['computers_rdn'];
    }

    // Construire les DN complets (identique à get_config_file)
    $baseDn = $c['ldap_base_dn'];
    $c['dn'] = [];
    $c['dn']['people']          = $c['people_rdn'] . ',' . $baseDn;
    $c['dn']['Eleves']          = 'OU=Eleves,' . $c['people_rdn'] . ',' . $baseDn;
    $c['dn']['Profs']           = 'OU=Profs,' . $c['people_rdn'] . ',' . $baseDn;
    $c['dn']['Administratifs']  = 'OU=Administratifs,' . $c['people_rdn'] . ',' . $baseDn;
    $c['dn']['groups']          = $c['groups_rdn'] . ',' . $baseDn;
    $c['dn']['rights']          = $c['rights_rdn'] . ',' . $baseDn;
    $c['dn']['equipements']     = $c['equipements_rdn'] . ',' . $baseDn;
    $c['dn']['etablissements']  = $c['etablissements_rdn'] . ',' . $baseDn;
    $c['dn']['delegations']     = $c['delegations_rdn'] . ',' . $baseDn;
    $c['dn']['matieres']        = $c['matieres_rdn'] . ',' . $baseDn;
    $c['dn']['trash']           = $c['trash_rdn'] . ',' . $baseDn;
    $c['dn']['parcs']           = $c['parcs_rdn'] . ',' . $baseDn;
    $c['dn']['computers']       = $c['computers_rdn'] . ',' . $baseDn;
    $c['dn']['autres']          = $c['other_groups_rdn'] . ',' . $c['groups_rdn'] . ',' . $baseDn;
    $c['dn']['projets']         = $c['projets_rdn'] . ',' . $c['groups_rdn'] . ',' . $baseDn;
    $c['dn']['cours']           = $c['cours_rdn'] . ',' . $c['groups_rdn'] . ',' . $baseDn;
    $c['dn']['classes']         = $c['classes_rdn'] . ',' . $c['groups_rdn'] . ',' . $baseDn;
    $c['dn']['equipes']         = $c['equipes_rdn'] . ',' . $c['groups_rdn'] . ',' . $baseDn;

    // Session / utilisateur connecté
    $c['login'] = '';
    if (function_exists('auth') && auth()->check()) {
        $c['login'] = auth()->user()->login ?? '';
    }

    // iPXE / Déploiement réseau
    $c['se4fs_ip']          = config('sambaedu.se4fs_ip', '');
    $c['se4fs_name']        = config('sambaedu.se4fs_name', '');
    $c['ipxe_url']          = config('sambaedu.ipxe_url', '');
    $c['se4install_name']   = config('sambaedu.se4install_name', '');
    $c['se4install_passwd'] = config('sambaedu.se4install_passwd', '');

    // Timeouts LDAP
    $c['ldap_timeout']   = 20;
    $c['ldap_timelimit'] = 30;
    $c['ent_timeout']    = 600;

    return $c;
}

global $config;
$config = legacy_build_config();

// Caractères spéciaux pour mots de passe (repris du legacy)
$GLOBALS['char_spec'] = '_#@£%§:!?*$-';

// Le bind LDAP n'est PAS initialisé ici — le shim ldap.inc.php le gère
// $config['bind'] sera un objet factice qui redirige vers Eloquent
