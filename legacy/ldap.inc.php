<?php

/**
 * Shim LDAP → Eloquent.
 *
 * Ce fichier remplace le legacy includes/ldap.inc.php.
 * Il redirige les fonctions de haut niveau LDAP (search_ad, search_user, etc.)
 * vers des requêtes Eloquent/PostgreSQL.
 *
 * Stratégie :
 * - Les fonctions de haut niveau (search_ad, modify_ad, etc.) sont shimmées
 *   pour retourner les données depuis Eloquent dans le format LDAP attendu.
 * - $config['bind'] reçoit un objet factice (pas de connexion LDAP réelle).
 * - Les fonctions utilitaires pures (ldap_dn2cn, etc.) sont conservées telles quelles.
 * - Les fonctions non shimmées loggent une erreur explicite via ErrorLoggerService.
 *
 * Format de retour LDAP attendu par le code legacy :
 * [
 *   0 => ['cn' => 'jdupont', 'fullname' => 'Jean Dupont', 'dn' => 'CN=jdupont,...', ...],
 *   1 => [...],
 *   'count' => 2
 * ]
 */

use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Services\ErrorLoggerService;

// Guard : ne charger qu'une seule fois
if (defined('LEGACY_LDAP_SHIM_LOADED')) {
    return;
}
define('LEGACY_LDAP_SHIM_LOADED', true);

// ─── Constantes de droits legacy (bitmask) ──────────────────────────────────
if (!defined('SE_NO_RIGHT'))             define('SE_NO_RIGHT', 0);
if (!defined('SE_USER_PASSWORD_INIT'))   define('SE_USER_PASSWORD_INIT', 1);
if (!defined('SE_USER_READ'))            define('SE_USER_READ', 2);
if (!defined('SE_USER_MODIFY'))          define('SE_USER_MODIFY', 4);
if (!defined('SE_USER_CREATE_TEMP'))     define('SE_USER_CREATE_TEMP', 8);
if (!defined('SE_USER_ASSIGN_RIGHT'))    define('SE_USER_ASSIGN_RIGHT', 0x10);
if (!defined('SE_USER_DELEGATE'))        define('SE_USER_DELEGATE', 0x20);
if (!defined('SE_SHARE_VIEW'))           define('SE_SHARE_VIEW', 0x40);
if (!defined('SE_SHARE_REFRESH'))        define('SE_SHARE_REFRESH', 0x80);
if (!defined('SE_SHARE_ADMIN'))          define('SE_SHARE_ADMIN', 0xC0);
if (!defined('SE_ELEVE_ADMIN'))          define('SE_ELEVE_ADMIN', 0x07);
if (!defined('SE_USER_ADMIN'))           define('SE_USER_ADMIN', 0xFF);
if (!defined('SE_COMPUTER_VIEW'))        define('SE_COMPUTER_VIEW', 0x100);
if (!defined('SE_COMPUTER_CONTROL'))     define('SE_COMPUTER_CONTROL', 0x200);
if (!defined('SE_COMPUTER_ELEVATE'))     define('SE_COMPUTER_ELEVATE', 0x400);
if (!defined('SE_COMPUTER_INSTALL'))     define('SE_COMPUTER_INSTALL', 0x800);
if (!defined('SE_WPKG_ASSIGN'))          define('SE_WPKG_ASSIGN', 0x1000);
if (!defined('SE_WPKG_ADD'))             define('SE_WPKG_ADD', 0x2000);
if (!defined('SE_WPKG_CREATE'))          define('SE_WPKG_CREATE', 0x4000);
if (!defined('SE_COMPUTER_ADMIN'))       define('SE_COMPUTER_ADMIN', SE_COMPUTER_VIEW | SE_COMPUTER_CONTROL | SE_COMPUTER_ELEVATE | SE_COMPUTER_INSTALL | SE_WPKG_ASSIGN | SE_WPKG_ADD);
if (!defined('SE_SERVER_ADMIN'))         define('SE_SERVER_ADMIN', 0x8000);
if (!defined('SE_ADMIN'))                define('SE_ADMIN', 0xFFFF);

// ─── Objet factice pour $config['bind'] ──────────────────────────────────────

/**
 * Objet factice représentant une "connexion LDAP" pour satisfaire
 * les vérifications isset($config['bind']) dans le code legacy.
 */
class LdapShimConnection
{
    public bool $connected = true;
}

// Initialiser $config['bind'] avec l'objet factice
global $config;
$config['bind'] = new LdapShimConnection();

// ─── Mapping attributs LDAP → Eloquent ───────────────────────────────────────

/**
 * Map LDAP attribute names to PHP-friendly names (used by search_ad).
 */
$_ldap_attr_map = [
    'sn'          => 'nom',
    'displayname' => 'fullname',
    'givenname'   => 'prenom',
    'initials'    => 'pseudo',
    'mail'        => 'email',
    'telephonenumber' => 'tel',
];

// ─── Fonctions utilitaires DN (pures — pas de LDAP) ─────────────────────────

if (!function_exists('ldap_dn2cn')) {
    function ldap_dn2cn(string $dn): string
    {
        if (preg_match('/^CN=([^,]+)/i', $dn, $m)) {
            return $m[1];
        }
        return $dn;
    }
}

if (!function_exists('ldap_dn2ou')) {
    function ldap_dn2ou(string $dn): string
    {
        if (preg_match('/OU=([^,]+)/i', $dn, $m)) {
            return $m[1];
        }
        return '';
    }
}

if (!function_exists('ldap_dn2oudn')) {
    function ldap_dn2oudn(string $dn): string
    {
        $parts = explode(',', $dn, 2);
        return $parts[1] ?? '';
    }
}

if (!function_exists('ldap_dn2parent')) {
    function ldap_dn2parent(string $dn): string
    {
        $parts = explode(',', $dn, 2);
        return $parts[1] ?? '';
    }
}

if (!function_exists('ldap_dn2sam')) {
    function ldap_dn2sam(string $dn): string
    {
        return ldap_dn2cn($dn);
    }
}

if (!function_exists('ldap_dn2uai')) {
    function ldap_dn2uai(string $dn): string
    {
        if (preg_match('/OU=([0-9]{7}[a-zA-Z])/i', $dn, $m)) {
            return $m[1];
        }
        return '';
    }
}

if (!function_exists('ldap_sam2cn')) {
    function ldap_sam2cn(string $sam): string
    {
        return $sam;
    }
}

if (!function_exists('ldap_sam2suffix')) {
    function ldap_sam2suffix(string $sam): string
    {
        if (preg_match('/-([a-z0-9]+)$/i', $sam, $m)) {
            return $m[1];
        }
        return '';
    }
}

if (!function_exists('escape_ldap_name')) {
    function escape_ldap_name(&$name): void
    {
        $name = str_replace(
            ['\\', '*', '(', ')', "\x00"],
            ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
            $name
        );
    }
}

// ─── Helpers internes ────────────────────────────────────────────────────────

/**
 * Convertit un User Eloquent en format de résultat LDAP attendu par le legacy.
 */
function _shim_user_to_ldap_entry(User $user, array $config): array
{
    $dn = $user->dn ?? ('CN=' . $user->login . ',' . ($config['dn']['people'] ?? ''));

    $memberof = [];
    if ($user->relationLoaded('groups')) {
        foreach ($user->groups as $group) {
            $memberof[] = $group->ad_dn ?? ('CN=' . $group->name . ',' . ($config['dn']['groups'] ?? ''));
        }
    }

    return [
        'cn'          => $user->login,
        'nom'         => $user->lastname ?? '',
        'fullname'    => $user->fullname ?? ($user->firstname . ' ' . $user->lastname),
        'prenom'      => $user->firstname ?? '',
        'email'       => $user->email ?? '',
        'pseudo'      => '',
        'tel'         => '',
        'description' => '',
        'dn'          => $dn,
        'displayname' => $user->fullname ?? $user->login,
        'sn'          => $user->lastname ?? '',
        'givenname'   => $user->firstname ?? '',
        'mail'        => $user->email ?? '',
        'memberof'    => $memberof,
        'useraccountcontrol' => $user->is_active ? '512' : '514',
        'userprincipalname'  => $user->login . '@' . ($config['domain'] ?? ''),
        'objectguid'  => $user->ad_guid ?? '',
        'pwdlastset'  => '1',
        'accountexpires' => '0',
        'physicaldeliveryofficename' => '',
        'title'       => '',
        'employeenumber' => '',
        'initials'    => '',
    ];
}

/**
 * Convertit un UserGroup Eloquent en format LDAP.
 */
function _shim_group_to_ldap_entry(UserGroup $group, array $config): array
{
    $dn = $group->ad_dn ?? ('CN=' . $group->name . ',' . ($config['dn']['groups'] ?? ''));

    $members = [];
    if ($group->relationLoaded('users')) {
        foreach ($group->users as $user) {
            $members[] = $user->dn ?? ('CN=' . $user->login . ',' . ($config['dn']['people'] ?? ''));
        }
    }

    return [
        'cn'          => $group->name,
        'dn'          => $dn,
        'displayname' => $group->display_name ?? $group->name,
        'description' => '',
        'member'      => $members,
        'info'        => '',
    ];
}

/**
 * Convertit un Workstation Eloquent en format LDAP.
 */
function _shim_machine_to_ldap_entry(Workstation $ws, array $config): array
{
    $dn = $ws->ad_dn ?? ('CN=' . $ws->name . ',' . ($config['dn']['computers'] ?? ''));

    return [
        'cn'              => $ws->name,
        'dn'              => $dn,
        'name'            => $ws->name,
        'os'              => $ws->os ?? '',
        'ip'              => $ws->ip ?? '',
        'iphostnumber'    => $ws->ip ?? '',
        'mac'             => $ws->mac ?? '',
        'networkaddress'  => $ws->mac ?? '',
        'netbootguid'     => $ws->uuid ?? '',
        'description'     => '',
        'operatingsystem' => $ws->os ?? '',
        'dnshostname'     => ($ws->name ?? '') . '.' . ($config['domain'] ?? ''),
        'memberof'        => [],
    ];
}

/**
 * Emballe un tableau de résultats dans le format LDAP attendu (avec 'count').
 */
function _shim_wrap_results(array $entries): array
{
    // Le format LDAP legacy : [0 => [...], 1 => [...], 'count' => 2]
    // Le code legacy utilise tantôt $result['count'] tantôt count($result)
    // Pour que count() PHP retourne le bon nombre, on ne met 'count'
    // que s'il y a des résultats (sinon tableau vide = count() PHP retourne 0)
    if (empty($entries)) {
        return [];
    }
    $entries['count'] = count($entries);
    return $entries;
}

/**
 * Log une fonction LDAP non shimmée.
 */
function _shim_log_unimplemented(string $functionName): void
{
    try {
        if (function_exists('app')) {
            app()->make(ErrorLoggerService::class)->log(
                'legacy',
                "Fonction LDAP non shimmée : {$functionName}"
            );
        }
    } catch (\Throwable $e) {
        // Fallback : log PHP natif si le service est indisponible
        error_log("[legacy-shim] Fonction LDAP non shimmée : {$functionName} (logger unavailable: {$e->getMessage()})");
    }
}

// ─── Fonctions de haut niveau shimmées ───────────────────────────────────────

if (!function_exists('search_ad')) {
    /**
     * Shim de search_ad — redirige vers Eloquent.
     *
     * Supporte les types : user, group, group_fast, machine, member, filter.
     * Les types non supportés loggent une erreur et retournent un tableau vide.
     */
    function search_ad(
        array $config,
        string $name,
        string $type = 'dn',
        string $branch = 'all',
        array $attrs = [],
        string $scope = 'subtree',
        bool $restrict_attrs = false,
        bool $nocache = false
    ): array|false {
        switch ($type) {
            case 'user':
                $query = User::query();

                if ($name !== '*') {
                    // Extraire le CN si format DN
                    if (preg_match('/^cn=(.*),(ou=.*)$/iU', $name, $m)) {
                        $name = $m[1];
                    }
                    $query->where('login', $name);
                }

                // Filtrer par branche (rôle)
                if ($branch !== 'all' && !preg_match('/^(ou|cn)=/i', $branch)) {
                    $roleMap = [
                        'Eleves' => 'eleve',
                        'Profs' => 'prof',
                        'Administratifs' => 'administratif',
                    ];
                    if (isset($roleMap[$branch])) {
                        $query->where('role', $roleMap[$branch]);
                    }
                }

                $users = $query->with('groups')->get();

                $results = [];
                foreach ($users as $user) {
                    $results[] = _shim_user_to_ldap_entry($user, $config);
                }
                return _shim_wrap_results($results);

            case 'group':
            case 'groupe':
            case 'group_fast':
                $query = UserGroup::query();

                if ($name !== '*') {
                    $query->where('name', $name);
                }

                if ($type === 'group') {
                    $query->with('users');
                }

                $groups = $query->get();

                $results = [];
                foreach ($groups as $group) {
                    $results[] = _shim_group_to_ldap_entry($group, $config);
                }
                return _shim_wrap_results($results);

            case 'machine':
                $query = Workstation::query();

                if ($name !== '*') {
                    // Le legacy cherche par name, MAC (networkAddress) ou UUID (netbootGUID).
                    // Normalisation : MAC en xx:xx:xx:xx:xx:xx minuscules, UUID en minuscules
                    // pour matcher le format stocké en DB (iso avec boot.php qui fait strtolower($uuid)).
                    $normalized = strtolower($name);
                    $looksLikeUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $normalized);
                    $query->where(function ($q) use ($name, $normalized, $looksLikeUuid) {
                        $q->where('name', $name)
                          ->orWhere('mac', $normalized);
                        if ($looksLikeUuid) {
                            $q->orWhere('uuid', $normalized);
                        }
                    });
                }

                $machines = $query->get();

                $results = [];
                foreach ($machines as $ws) {
                    $results[] = _shim_machine_to_ldap_entry($ws, $config);
                }
                return _shim_wrap_results($results);

            case 'member':
                // Recherche d'utilisateurs dans une branche (OU) spécifique
                $query = User::query();
                if ($name !== '*') {
                    $query->where('login', $name);
                }

                $roleMap = [
                    'Eleves' => 'eleve',
                    'Profs' => 'prof',
                    'Administratifs' => 'administratif',
                ];
                if (isset($roleMap[$branch])) {
                    $query->where('role', $roleMap[$branch]);
                }

                $users = $query->get();
                $results = [];
                foreach ($users as $user) {
                    $results[] = ['cn' => $user->login];
                }
                return _shim_wrap_results($results);

            case 'filter':
                // Recherche générique — filtrer les utilisateurs actifs par $name
                $query = User::active();
                if ($name !== '*' && $name !== '') {
                    $query->search($name);
                }
                $users = $query->with('groups')->get();
                $results = [];
                foreach ($users as $user) {
                    $results[] = _shim_user_to_ldap_entry($user, $config);
                }
                return _shim_wrap_results($results);

            default:
                _shim_log_unimplemented("search_ad(type={$type})");
                return _shim_wrap_results([]);
        }
    }
}

if (!function_exists('search_user')) {
    function search_user(array $config, string $cn, string $branch = 'all', bool $nocache = false): array|false
    {
        return search_ad($config, $cn, 'user', $branch, [], 'subtree', false, $nocache);
    }
}

if (!function_exists('search_group')) {
    function search_group(array $config, string $cn, bool $fast = false): array|false
    {
        return search_ad($config, $cn, $fast ? 'group_fast' : 'group');
    }
}

if (!function_exists('search_machine')) {
    /**
     * Recherche une machine par nom, UUID ou MAC.
     *
     * Le paramètre $ip du legacy est mal nommé : il signifie "recherche
     * complète" (type=machine) vs "fast" (type=machine_fast). Dans notre
     * shim les deux passent par search_ad(type='machine') qui cherche
     * déjà par name/mac/uuid.
     */
    function search_machine(array $config, string $cn, bool $ip = false): array
    {
        $results = search_ad($config, $cn, 'machine');
        if (!is_array($results) || empty($results)) {
            return [];
        }
        return $results[0];
    }
}

if (!function_exists('list_members_group')) {
    function list_members_group(array $config, string $group): array
    {
        $g = UserGroup::where('name', $group)->with('users')->first();
        if (!$g) {
            return _shim_wrap_results([]);
        }
        $results = [];
        foreach ($g->users as $user) {
            $results[] = _shim_user_to_ldap_entry($user, $config);
        }
        return _shim_wrap_results($results);
    }
}

if (!function_exists('list_groups')) {
    function list_groups(array $config, string $user): array
    {
        $u = User::where('login', $user)->with('groups')->first();
        if (!$u) {
            return _shim_wrap_results([]);
        }
        $results = [];
        foreach ($u->groups as $group) {
            $results[] = _shim_group_to_ldap_entry($group, $config);
        }
        return _shim_wrap_results($results);
    }
}

if (!function_exists('list_classes')) {
    function list_classes(array $config, string $name, bool $full = false, bool $groups = false): array
    {
        $query = UserGroup::where('type', 'classe');
        if ($name !== '*') {
            $query->where('name', 'ILIKE', "%{$name}%");
        }
        if ($full || $groups) {
            $query->with('users');
        }
        $results = [];
        foreach ($query->get() as $group) {
            $results[] = _shim_group_to_ldap_entry($group, $config);
        }
        return _shim_wrap_results($results);
    }
}

// ─── Droits & permissions (shim bitmask legacy → Spatie) ────────────────────

/**
 * Mapping rôles Spatie → bitmask legacy.
 * Un rôle Spatie confère TOUS les bits correspondants.
 */
$_spatie_role_to_bitmask = [
    'super-admin'         => SE_ADMIN,
    'computer-admin'      => SE_COMPUTER_ADMIN | SE_USER_READ,
    'user-admin'          => SE_USER_ADMIN,
    'technicien'          => SE_COMPUTER_ADMIN | SE_USER_READ | SE_SHARE_VIEW,
    'referent-numerique'  => SE_USER_READ | SE_SHARE_VIEW | SE_COMPUTER_VIEW,
    'share-admin'         => SE_SHARE_ADMIN | SE_USER_READ,
    'eleve-admin'         => SE_ELEVE_ADMIN,
    'prof'                => SE_USER_READ | SE_SHARE_VIEW,
    'eleve'               => SE_USER_READ,
];

if (!function_exists('list_rights')) {
    /**
     * Shim list_rights : traduit les rôles Spatie en bitmask legacy.
     */
    function list_rights(array $config, $name, bool $deleg = false, bool $refresh = false): int
    {
        global $_spatie_role_to_bitmask;

        // Résoudre le login
        if (is_array($name) && isset($name['cn'])) {
            $login = $name['cn'];
        } elseif ($name === 'login' || empty($name)) {
            $login = $config['login'] ?? '';
        } else {
            $login = $name;
        }

        if ($login === 'admin') {
            return SE_ADMIN;
        }

        // Trouver l'utilisateur et ses rôles Spatie
        $user = User::where('login', $login)->first();
        if (!$user) {
            return SE_NO_RIGHT;
        }

        $bitmask = SE_NO_RIGHT;
        foreach ($user->getRoleNames() as $role) {
            $bitmask |= ($_spatie_role_to_bitmask[$role] ?? 0);
        }

        return $bitmask;
    }
}

if (!function_exists('have_right')) {
    /**
     * Shim have_right : vérifie si un utilisateur possède un droit legacy (bitmask).
     */
    function have_right(array $config, int $test_right, $user = 'login', bool $or = false): bool
    {
        if ($user === 'login') {
            $user = $config['login'] ?? '';
        }

        if ($user === 'admin') {
            return true;
        }

        $right = list_rights($config, $user);

        if ($or) {
            return ($test_right & $right) != 0;
        } else {
            return (~(~$test_right | $right) == 0);
        }
    }
}

if (!function_exists('have_right_or_delegation')) {
    function have_right_or_delegation(array $config, int $right, string $name = 'login'): bool
    {
        return have_right($config, $right, $name, true);
    }
}

// getintlevel : défini dans le vrai functions.inc.php du legacy — pas de shim ici.

// ─── Fonctions de modification (lecture seule pour l'instant) ────────────────

if (!function_exists('modify_ad')) {
    function modify_ad(
        array $config,
        $name,
        string $type = '',
        array $attrs = [],
        string $mode = 'replace',
        bool $invalidate_cache = true
    ): bool {
        _shim_log_unimplemented("modify_ad(name={$name}, type={$type}, mode={$mode})");
        return false;
    }
}

if (!function_exists('modify_ad_attr')) {
    function modify_ad_attr(array $config, array $name, string $attr, $value = ''): bool
    {
        _shim_log_unimplemented("modify_ad_attr(attr={$attr})");
        return false;
    }
}

if (!function_exists('delete_ad')) {
    function delete_ad($config, $name, $type = ''): bool
    {
        _shim_log_unimplemented("delete_ad(name={$name})");
        return false;
    }
}

if (!function_exists('move_ad')) {
    function move_ad($config, $name, $new_dn, $type = ''): bool
    {
        _shim_log_unimplemented("move_ad(name={$name}, new_dn={$new_dn})");
        return false;
    }
}

// ─── Fonctions list_* shimmées ───────────────────────────────────────────────

if (!function_exists('list_profs')) {
    function list_profs(array $config, string $name): array
    {
        return search_ad($config, $name, 'member', 'Profs');
    }
}

if (!function_exists('list_eleves')) {
    function list_eleves(array $config, string $name): array
    {
        return search_ad($config, $name, 'member', 'Eleves');
    }
}

if (!function_exists('list_rights')) {
    function list_rights(array $config, string $name, bool $deleg = false, bool $refresh = false): array
    {
        _shim_log_unimplemented('list_rights');
        return _shim_wrap_results([]);
    }
}

if (!function_exists('list_delegations')) {
    function list_delegations(array $config, string $login = 'login', bool $recurse = true, string $level = '', ?string $initial_login = null): array
    {
        _shim_log_unimplemented('list_delegations');
        return _shim_wrap_results([]);
    }
}

if (!function_exists('list_etabs')) {
    function list_etabs(array $config): array
    {
        _shim_log_unimplemented('list_etabs');
        return _shim_wrap_results([]);
    }
}

if (!function_exists('search_etabs')) {
    function search_etabs(array $config, string $name = '*'): array
    {
        _shim_log_unimplemented('search_etabs');
        return _shim_wrap_results([]);
    }
}

if (!function_exists('search_delegations')) {
    function search_delegations(array $config, string $parc = ''): array
    {
        _shim_log_unimplemented('search_delegations');
        return _shim_wrap_results([]);
    }
}

// ─── Fonctions de filtrage ───────────────────────────────────────────────────

if (!function_exists('filter_user')) {
    function filter_user(array $config, string $filter): array
    {
        $users = User::search($filter)->with('groups')->get();
        $results = [];
        foreach ($users as $user) {
            $results[] = _shim_user_to_ldap_entry($user, $config);
        }
        return _shim_wrap_results($results);
    }
}

if (!function_exists('filter_group')) {
    function filter_group(array $config, string $filter, string $branch = 'default'): array
    {
        // Parser les filtres LDAP basiques : (cn=xxx), (name=xxx)
        if (preg_match('/^\((?:cn|name)\s*=\s*(.+)\)$/i', $filter, $m)) {
            $name = trim($m[1]);
            $groups = $name === '*'
                ? UserGroup::all()
                : UserGroup::where('name', $name)->get();
        } else {
            $groups = UserGroup::search($filter)->get();
        }

        $results = [];
        foreach ($groups as $group) {
            $results[] = _shim_group_to_ldap_entry($group, $config);
        }
        return _shim_wrap_results($results);
    }
}

if (!function_exists('filter_group_classes')) {
    function filter_group_classes(array $config): array
    {
        return _shim_wrap_results(
            UserGroup::where('type', 'classe')->get()->map(fn($g) => _shim_group_to_ldap_entry($g, $config))->values()->all()
        );
    }
}

if (!function_exists('filter_group_equipes')) {
    function filter_group_equipes(array $config): array
    {
        return _shim_wrap_results(
            UserGroup::where('type', 'equipe')->get()->map(fn($g) => _shim_group_to_ldap_entry($g, $config))->values()->all()
        );
    }
}

if (!function_exists('filter_group_cours')) {
    function filter_group_cours(array $config): array
    {
        return _shim_wrap_results(
            UserGroup::where('type', 'cours')->get()->map(fn($g) => _shim_group_to_ldap_entry($g, $config))->values()->all()
        );
    }
}

if (!function_exists('filter_group_matieres')) {
    function filter_group_matieres(array $config): array
    {
        return _shim_wrap_results(
            UserGroup::where('type', 'matiere')->get()->map(fn($g) => _shim_group_to_ldap_entry($g, $config))->values()->all()
        );
    }
}

if (!function_exists('filter_group_projets')) {
    function filter_group_projets(array $config): array
    {
        return _shim_wrap_results(
            UserGroup::where('type', 'projet')->get()->map(fn($g) => _shim_group_to_ldap_entry($g, $config))->values()->all()
        );
    }
}

if (!function_exists('filter_group_autres')) {
    function filter_group_autres(array $config): array
    {
        return _shim_wrap_results(
            UserGroup::where('type', 'autre')->get()->map(fn($g) => _shim_group_to_ldap_entry($g, $config))->values()->all()
        );
    }
}

// ─── Fonctions de création (non shimmées — logguent l'appel) ────────────────

if (!function_exists('create_ad_user')) {
    function create_ad_user(array $config, array $user): bool
    {
        _shim_log_unimplemented('create_ad_user');
        return false;
    }
}

if (!function_exists('create_group')) {
    function create_group(array $config, string $name, string $description, string $type = 'groupe'): bool
    {
        try {
            $repo = app(\App\Repositories\GroupRepository::class);
            $result = $repo->createGroup($name, $description, $type);

            if ($result) {
                // Sync immédiate en SQL pour que search_ad le trouve tout de suite
                // (le sync AD→SQL normal est asynchrone)
                $cnPrefixes = [
                    'classe' => 'Classe_',
                    'equipe' => 'Equipe_',
                    'cours' => 'Cours_',
                    'matiere' => 'Matiere_',
                    'projet' => 'Projet_',
                ];
                $prefix = $cnPrefixes[$type] ?? '';
                $cn = $prefix . $name;

                UserGroup::firstOrCreate(
                    ['name' => $cn],
                    ['display_name' => $description, 'type' => $type]
                );
            }

            return $result;
        } catch (\Throwable $e) {
            _shim_log_unimplemented("create_group FAILED: {$e->getMessage()}");
            return false;
        }
    }
}

if (!function_exists('create_machine')) {
    function create_machine(array $config, string $name, string $ou_rdn = '', string $description = 'reservation dhcp uniquement'): bool
    {
        _shim_log_unimplemented('create_machine');
        return false;
    }
}

if (!function_exists('create_parc')) {
    function create_parc(array $config, string $parc, string $description = '', string $type = 'salle', string $parentou = ''): bool
    {
        _shim_log_unimplemented('create_parc');
        return false;
    }
}

// ─── Fonctions user trash/recup (non shimmées) ──────────────────────────────

if (!function_exists('trash_user')) {
    function trash_user(array $config, array $user): bool
    {
        _shim_log_unimplemented('trash_user');
        return false;
    }
}

if (!function_exists('recup_user')) {
    function recup_user(array $config, array $user, string $cat = ''): bool
    {
        _shim_log_unimplemented('recup_user');
        return false;
    }
}

if (!function_exists('trash_users')) {
    function trash_users(array $config): array
    {
        _shim_log_unimplemented('trash_users');
        return _shim_wrap_results([]);
    }
}

if (!function_exists('user_valid_passwd')) {
    /**
     * Vérifie le mot de passe d'un utilisateur via AuthenticationService Laravel.
     * Retourne 1 si OK, 0 si échec (compatible avec le code legacy).
     */
    function user_valid_passwd(array $config, string $login, string $password): int
    {
        try {
            $authService = app(\App\Services\AuthenticationService::class);
            $result = $authService->authenticate($login, $password, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
            return ($result['success'] ?? false) ? 1 : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('is_dual_boot')) {
    /**
     * Vérifie si une machine est en dual boot (membre des parcs "windows" ET "linux").
     */
    function is_dual_boot($config, $machine): bool
    {
        $name = $machine['cn'] ?? ($machine['name'] ?? '');
        if (empty($name)) {
            return false;
        }
        $ws = Workstation::where('name', $name)->first();
        if (!$ws) {
            return false;
        }
        $groups = $ws->groups()->pluck('name')->map(fn($n) => strtolower($n))->toArray();
        return in_array('windows', $groups) && in_array('linux', $groups);
    }
}

if (!function_exists('register_machine_hardware')) {
    /**
     * Enregistre/met à jour le netbootguid d'une machine.
     * Shim : retourne simplement $machine sans toucher à l'AD,
     * le uuid est déjà stocké dans PostgreSQL.
     */
    function register_machine_hardware($config, array $machine, string $guid): array
    {
        if (!empty($guid) && empty($machine['netbootguid'])) {
            $machine['netbootguid'] = $guid;
        }
        return $machine;
    }
}

if (!function_exists('is_eleve')) {
    /**
     * Vérifie si un utilisateur est un élève (membre du groupe "Eleves").
     * Utilise search_user() déjà shimmée pour récupérer les données.
     */
    function is_eleve($config, $name): bool
    {
        if (!is_array($name)) {
            // search_user retourne un tableau wrappé [0 => user, 'count' => N]
            $result = search_user($config, $name);
            $name = $result[0] ?? null;
        }
        if (empty($name)) {
            // Compte non trouvé → élève par défaut (least privilege fallback legacy)
            return true;
        }
        if (isset($name['memberof']) && is_array($name['memberof'])) {
            foreach ($name['memberof'] as $g) {
                if (preg_match('/Eleves/', $g)) {
                    return true;
                }
            }
        }
        return false;
    }
}

if (!function_exists('is_prof')) {
    /**
     * Vérifie si un utilisateur est un professeur (membre du groupe "Profs").
     */
    function is_prof($config, $name): bool
    {
        if (!is_array($name)) {
            // search_ad retourne un tableau wrappé [0 => user, 'count' => N]
            $result = search_ad($config, $name, 'member', 'Profs');
            return is_array($result) && isset($result['count']) && $result['count'] > 0;
        }
        if (isset($name['memberof']) && is_array($name['memberof'])) {
            foreach ($name['memberof'] as $g) {
                if (preg_match('/Profs/', $g)) {
                    return true;
                }
            }
        }
        return false;
    }
}

// ─── Fonctions de comparaison (utilitaires pures — pas de LDAP) ─────────────

if (!function_exists('cmp_fullname')) {
    function cmp_fullname(array $a, array $b): int
    {
        return strcmp($a['fullname'] ?? '', $b['fullname'] ?? '');
    }
}

if (!function_exists('cmp_nom')) {
    function cmp_nom(array $a, array $b): int
    {
        return strcmp($a['nom'] ?? '', $b['nom'] ?? '');
    }
}

if (!function_exists('cmp_cn')) {
    function cmp_cn(array $a, array $b): int
    {
        return strcmp($a['cn'] ?? '', $b['cn'] ?? '');
    }
}

if (!function_exists('cmp_group')) {
    function cmp_group(array $a, array $b): int
    {
        return strcmp($a['group'] ?? '', $b['group'] ?? '');
    }
}

// ─── Fonctions list_members_parc (machines dans un parc) ────────────────────

if (!function_exists('list_members_parc')) {
    function list_members_parc(array $config, string $parc, bool $attrs = false, bool $recurse = false, string $type = 'all'): array
    {
        $group = WorkstationGroup::where('name', $parc)->first();
        if (!$group) {
            return _shim_wrap_results([]);
        }

        $workstations = $group->workstations ?? collect();
        $results = [];
        foreach ($workstations as $ws) {
            $results[] = _shim_machine_to_ldap_entry($ws, $config);
        }
        return _shim_wrap_results($results);
    }
}

// ─── Fonctions locking (utilitaires — conservées) ────────────────────────────

if (!function_exists('lock')) {
    function lock(string $var, bool $apcu = true): void
    {
        $cpt = 0;
        if ($apcu && function_exists('apcu_add')) {
            while (!apcu_add($var . '_lock', true, 5)) {
                usleep(10000);
                if ($cpt++ > 1000) {
                    return;
                }
            }
        }
    }
}

if (!function_exists('try_lock')) {
    function try_lock(string $var, bool $apcu = true): bool
    {
        if ($apcu && function_exists('apcu_add')) {
            return apcu_add($var . '_lock', true, 600);
        }
        return true;
    }
}

if (!function_exists('is_locked')) {
    function is_locked(string $var)
    {
        if (function_exists('cache_age')) {
            return cache_age($var . '_lock');
        }
        return false;
    }
}

if (!function_exists('unlock')) {
    function unlock(string $var, bool $apcu = true): void
    {
        if ($apcu && function_exists('apcu_delete')) {
            apcu_delete($var . '_lock');
        }
    }
}

// ─── Fonctions de chiffrement URL (utilitaires pures) ────────────────────────

if (!function_exists('se_encrypt')) {
    /** @deprecated Use encrypt() — kept as alias for internal references */
    function se_encrypt($config, $data)
    {
        if (empty($config['url_key'])) {
            return '';
        }
        $key = hex2bin($config['url_key']);
        $method = 'aes-128-cbc';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = openssl_random_pseudo_bytes($iv_length);
        $encrypted_1 = openssl_encrypt($data, $method, $key, OPENSSL_RAW_DATA, $iv);
        $encrypted_2 = hash_hmac('sha256', $encrypted_1, $key, true);
        return bin2hex($iv . $encrypted_1 . $encrypted_2);
    }
}

if (!function_exists('se_decrypt')) {
    function se_decrypt($config, $input)
    {
        if (empty($input) || empty($config['url_key'])) {
            return $input;
        }
        $key = hex2bin($config['url_key']);
        $mix = hex2bin($input);
        $method = 'aes-128-cbc';
        $iv_length = openssl_cipher_iv_length($method);
        $iv = substr($mix, 0, $iv_length);
        $encrypted_2 = substr($mix, -32);
        $encrypted_1 = substr($mix, $iv_length, -32);
        $data = openssl_decrypt($encrypted_1, $method, $key, OPENSSL_RAW_DATA, $iv);
        if (hash_equals($encrypted_2, hash_hmac('sha256', $encrypted_1, $key, true))) {
            return $data;
        }
        return '';
    }
}

// ─── array_unique_multi (utilitaire) ─────────────────────────────────────────

if (!function_exists('array_unique_multi')) {
    function array_unique_multi(array $input): array
    {
        $serialized = array_map('serialize', $input);
        $unique = array_unique($serialized);
        return array_intersect_key($input, $unique);
    }
}

// ─── get_config shim ─────────────────────────────────────────────────────────
// Le code legacy appelle get_config() pour initialiser la connexion LDAP.
// Ce shim retourne le $config déjà initialisé par config.inc.php sans
// ouvrir de connexion LDAP réelle.

if (!function_exists('get_config')) {
    function get_config(array $config = [], bool $force = true, $global = false, string $module = 'all', $ad = false): array
    {
        // Partir du $config global (initialisé par legacy_build_config dans config.inc.php)
        $globalConfig = $GLOBALS['config'] ?? [];

        // Fusionner : le $config passé en paramètre peut contenir des clés supplémentaires
        $config = !empty($config) ? array_merge($globalConfig, $config) : $globalConfig;

        // Préserver le bind existant
        if (!isset($config['bind'])) {
            $config['bind'] = new LdapShimConnection();
        }

        // S'assurer que les DNs sont construits
        if (empty($config['ldap_base_dn'])) {
            $config['ldap_base_dn'] = config('sambaedu.legacy_ldap.base_dn', '');
        }

        // Login depuis la session Laravel
        if (function_exists('auth') && auth()->check()) {
            $config['login'] = auth()->user()->login ?? '';
        }

        return $config;
    }
}

// ─── Fonctions de config legacy (shimmées) ───────────────────────────────────

if (!function_exists('get_config_file')) {
    function get_config_file(string $module = 'sambaedu', $global = true, bool $old = false, bool $table = false): array
    {
        // Retourner la config depuis Laravel au lieu de lire /etc/sambaedu/
        global $config;
        return $config ?? [];
    }
}

if (!function_exists('set_config')) {
    function set_config($config, $param, $value = '', $module = 'sambaedu'): array
    {
        _shim_log_unimplemented("set_config(param={$param})");
        $config[$param] = $value;
        return $config;
    }
}

if (!function_exists('set_param')) {
    function set_param(&$config, $nom, $valeur, $module = 'sambaedu')
    {
        _shim_log_unimplemented("set_param(nom={$nom})");
        $config[$nom] = $valeur;
        return $valeur;
    }
}

if (!function_exists('init_param')) {
    function init_param(&$config, $nom, $valeur, $module = 'sambaedu'): void
    {
        if (!isset($config[$nom])) {
            $config[$nom] = $valeur;
        }
    }
}

if (!function_exists('is_local')) {
    function is_local(array $config): bool
    {
        return empty($config['etab_ou']) || !isset($config['central_se4fs_ip']);
    }
}

if (!function_exists('etab_suffix')) {
    function etab_suffix(string $uai): string
    {
        if (preg_match('/[0-9]{7}[a-z]/i', $uai)) {
            return strtolower('-' . substr($uai, 3));
        }
        return '';
    }
}

if (!function_exists('ad_url')) {
    function ad_url(array $config, string $proto = 'ldaps', $ad = false): string
    {
        // Shim — retourne une URL factice, aucune connexion LDAP réelle
        $server = $config['se4ad_ip'] ?? 'localhost';
        $port = ($proto === 'ldaps') ? 636 : 389;
        return "{$proto}://{$server}:{$port}";
    }
}

// ─── Cache disque (conservé du legacy — fonctions utilitaires) ───────────────

if (!function_exists('cache_valid')) {
    function cache_valid(string $name)
    {
        if (!is_dir(CACHE_DIR)) {
            return false;
        }
        $files = scandir(CACHE_DIR);
        foreach ($files as $file) {
            if (strpos($file, $name . '.cache@') === 0) {
                $m = explode('.cache@', $file);
                if ($m[1] != 0 && microtime(true) - (float) $m[1] > 0) {
                    @unlink(CACHE_DIR . '/' . $file);
                } else {
                    return $file;
                }
            }
        }
        return false;
    }
}

if (!function_exists('cache_age')) {
    function cache_age(string $name)
    {
        if ($file = cache_valid($name)) {
            $m = explode('.cache@', $file);
            if ($m[1] == 0) {
                return 0;
            }
            return microtime(true) - (float) $m[1];
        }
        return false;
    }
}

if (!function_exists('cache_fetch')) {
    function cache_fetch(string $name)
    {
        if ($file = cache_valid($name)) {
            return unserialize(file_get_contents(CACHE_DIR . '/' . $file));
        }
        return false;
    }
}

if (!function_exists('cache_add')) {
    function cache_add(string $name, $data, int $ttl = 0): bool
    {
        if (cache_valid($name)) {
            return false;
        }
        return (bool) cache_store($name, $data, $ttl);
    }
}

if (!function_exists('cache_store')) {
    function cache_store(string $name, $data, int $ttl = 0)
    {
        if (!file_exists(CACHE_DIR)) {
            @mkdir(CACHE_DIR);
        }
        $time = ($ttl > 0) ? microtime(true) + $ttl : 0;
        $tmp = CACHE_DIR . '/' . $name . '.cache@' . $time;
        if ($file = cache_valid($name)) {
            @unlink(CACHE_DIR . '/' . $file);
        }
        return file_put_contents($tmp, serialize($data));
    }
}

if (!function_exists('cache_delete')) {
    function cache_delete(string $name)
    {
        if ($file = cache_valid($name)) {
            return @unlink(CACHE_DIR . '/' . $file);
        }
        return true;
    }
}

if (!function_exists('cache_delete_multi')) {
    function cache_delete_multi(string $regexp): int
    {
        $n = 0;
        if (is_dir(CACHE_DIR)) {
            foreach (scandir(CACHE_DIR) as $file) {
                if (preg_match($regexp, $file)) {
                    @unlink(CACHE_DIR . '/' . $file);
                    $n++;
                }
            }
        }
        return $n;
    }
}

if (!function_exists('activated_etabs')) {
    /**
     * Shim activated_etabs — retourne la liste des établissements activés.
     * En mono-etab, retourne simplement l'etab courant depuis la config.
     */
    function activated_etabs(array $config): array
    {
        $uai = $config['etab_ou'] ?? config('sambaedu.etab_ou', '');
        return [
            [
                'uai' => $uai,
                'activated' => true,
            ],
        ];
    }
}

if (!function_exists('my_etabs')) {
    /**
     * Shim my_etabs — retourne les établissements auxquels un utilisateur appartient.
     * En mono-etab, retourne l'etab courant.
     */
    function my_etabs(array $config, $login): array
    {
        $uai = $config['etab_ou'] ?? config('sambaedu.etab_ou', '');
        return [
            [
                'uai' => $uai,
                'activated' => true,
            ],
        ];
    }
}

if (!function_exists('etab_to_name')) {
    /**
     * Shim etab_to_name — retourne le nom d'un établissement à partir de son UAI.
     * La version legacy interroge l'AD directement — ici on retourne le nom depuis la config.
     */
    function etab_to_name($config, $uai = 0)
    {
        $etabName = $config['etab_name'] ?? '';
        $currentUai = $config['etab_ou'] ?? '';
        if ($uai == 0 || $uai == $currentUai) {
            return !empty($etabName) ? "($etabName)" : '';
        }
        return '';
    }
}

if (!function_exists('db_connect')) {
    function db_connect($config, $etab = 'localhost')
    {
        _shim_log_unimplemented('db_connect');
        return false;
    }
}

if (!function_exists('lock_conf')) {
    function lock_conf($mode)
    {
        _shim_log_unimplemented('lock_conf');
        return false;
    }
}
