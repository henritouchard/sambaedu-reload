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
// Le code legacy attend $config comme variable globale
global $config;
$config = $config ?? [];

// LDAP / AD
$config['ldap_base_dn']      = config('sambaedu.legacy_ldap.base_dn', '');
$config['ldap_admin_name']   = config('sambaedu.legacy_ldap.bind_dn', '');
$config['ldap_admin_passwd'] = config('sambaedu.legacy_ldap.bind_password', '');
$config['se4ad_ip']          = config('sambaedu.se4ad_ip', '');
$config['se4ad_etab_ip']     = config('sambaedu.se4ad_etab_ip', '');

// Déduire le domaine depuis le base_dn (DC=ecole,DC=local → ecole.local)
$config['domain'] = '';
$dcParts = [];
if (!empty($config['ldap_base_dn'])) {
    foreach (explode(',', $config['ldap_base_dn']) as $part) {
        $part = trim($part);
        if (stripos($part, 'DC=') === 0) {
            $dcParts[] = substr($part, 3);
        }
    }
    $config['domain'] = implode('.', $dcParts);
}

// Établissement
$config['etab_ou'] = config('sambaedu.etab_ou', '');
$config['suffix']  = '';
if (preg_match('/[0-9]{7}[a-z]/i', $config['etab_ou'])) {
    $config['suffix'] = strtolower('-' . substr($config['etab_ou'], 3));
}

// OUs — valeurs par défaut legacy (surchargées si configurées)
$config['people_rdn']        = $config['people_rdn'] ?? 'OU=people';
$config['groups_rdn']        = $config['groups_rdn'] ?? 'OU=groups';
$config['equipements_rdn']   = $config['equipements_rdn'] ?? 'OU=equipements';
$config['computers_rdn']     = $config['computers_rdn'] ?? 'OU=computers';
$config['delegations_rdn']   = $config['delegations_rdn'] ?? 'OU=delegations';
$config['parcs_rdn']         = $config['parcs_rdn'] ?? 'OU=parcs';
$config['rights_rdn']        = $config['rights_rdn'] ?? 'OU=rights';
$config['trash_rdn']         = $config['trash_rdn'] ?? 'OU=trash';
$config['etablissements_rdn'] = $config['etablissements_rdn'] ?? 'OU=etablissements';
$config['matieres_rdn']      = $config['matieres_rdn'] ?? 'OU=Matieres';
$config['cours_rdn']         = $config['cours_rdn'] ?? 'OU=Cours';
$config['classes_rdn']       = $config['classes_rdn'] ?? 'OU=Classes';
$config['equipes_rdn']       = $config['equipes_rdn'] ?? 'OU=Equipes';
$config['projets_rdn']       = $config['projets_rdn'] ?? 'OU=Projets';
$config['other_groups_rdn']  = $config['other_groups_rdn'] ?? 'OU=Autres';

// Appliquer le préfixe UAI si présent (comme get_config_file le fait)
if (preg_match('/[0-9]{7}[a-z]/i', $config['etab_ou'])) {
    $uaiPrefix = 'OU=' . $config['etab_ou'] . ',';
    $config['people_rdn']      = $uaiPrefix . $config['people_rdn'];
    $config['groups_rdn']      = $uaiPrefix . $config['groups_rdn'];
    $config['equipements_rdn'] = $uaiPrefix . $config['equipements_rdn'];
    $config['delegations_rdn'] = $uaiPrefix . $config['delegations_rdn'];
    $config['parcs_rdn']       = $uaiPrefix . $config['parcs_rdn'];
    $config['computers_rdn']   = $uaiPrefix . $config['computers_rdn'];
}

// Construire les DN complets (identique à get_config_file)
$baseDn = $config['ldap_base_dn'];
$config['dn'] = $config['dn'] ?? [];
$config['dn']['people']          = $config['people_rdn'] . ',' . $baseDn;
$config['dn']['Eleves']          = 'OU=Eleves,' . $config['people_rdn'] . ',' . $baseDn;
$config['dn']['Profs']           = 'OU=Profs,' . $config['people_rdn'] . ',' . $baseDn;
$config['dn']['Administratifs']  = 'OU=Administratifs,' . $config['people_rdn'] . ',' . $baseDn;
$config['dn']['groups']          = $config['groups_rdn'] . ',' . $baseDn;
$config['dn']['rights']          = $config['rights_rdn'] . ',' . $baseDn;
$config['dn']['equipements']     = $config['equipements_rdn'] . ',' . $baseDn;
$config['dn']['etablissements']  = $config['etablissements_rdn'] . ',' . $baseDn;
$config['dn']['delegations']     = $config['delegations_rdn'] . ',' . $baseDn;
$config['dn']['matieres']        = $config['matieres_rdn'] . ',' . $baseDn;
$config['dn']['trash']           = $config['trash_rdn'] . ',' . $baseDn;
$config['dn']['parcs']           = $config['parcs_rdn'] . ',' . $baseDn;
$config['dn']['computers']       = $config['computers_rdn'] . ',' . $baseDn;
$config['dn']['autres']          = $config['other_groups_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;
$config['dn']['projets']         = $config['projets_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;
$config['dn']['cours']           = $config['cours_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;
$config['dn']['classes']         = $config['classes_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;
$config['dn']['equipes']         = $config['equipes_rdn'] . ',' . $config['groups_rdn'] . ',' . $baseDn;

// iPXE / Déploiement réseau
$config['se4fs_ip']          = config('sambaedu.se4fs_ip', '');
$config['se4fs_name']        = config('sambaedu.se4fs_name', '');
$config['ipxe_url']          = config('sambaedu.ipxe_url', '');
$config['se4install_name']   = config('sambaedu.se4install_name', '');
$config['se4install_passwd'] = config('sambaedu.se4install_passwd', '');

// Session / utilisateur connecté
$config['login'] = '';
if (function_exists('auth') && auth()->check()) {
    $config['login'] = auth()->user()->login ?? '';
}

// Timeouts LDAP
$config['ldap_timeout']   = $config['ldap_timeout'] ?? 20;
$config['ldap_timelimit'] = $config['ldap_timelimit'] ?? 30;
$config['ent_timeout']    = $config['ent_timeout'] ?? 600;

// Caractères spéciaux pour mots de passe (repris du legacy)
$GLOBALS['char_spec'] = '_#@£%§:!?*$-';

// Le bind LDAP n'est PAS initialisé ici — le shim ldap.inc.php le gère
// $config['bind'] sera un objet factice qui redirige vers Eloquent
