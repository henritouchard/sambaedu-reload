<?php

declare(strict_types=1);

/**
 * Story 3.1 — D11.
 *
 * Configuration du domaine iPXE (boot réseau + déploiement OS).
 *
 * Pattern iso `config/auth_v1.php` (16.10) + `config/scriptsos.php` (16.12)
 * + `config/parc.php` — un fichier de config par domaine fonctionnel,
 * override-able via `.env`.
 *
 * **Aucun secret** : pas de mot de passe ni de token signé ici (un firmware
 * iPXE ne porte pas de credentials — la sécurité est portée par le
 * middleware `auth.v1.lan-only` D3).
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Nom DNS du SE4FS
    |--------------------------------------------------------------------------
    |
    | Utilisé pour la construction des URLs `chain` dans les templates iPXE.
    | Fallback : valeur de `sambaedu.se4fs_name` (legacy config) → `se4fs`.
    |
    */
    'se4fs_name' => env('IPXE_SE4FS_NAME', config('sambaedu.se4fs_name', 'se4fs')),

    /*
    |--------------------------------------------------------------------------
    | URL de base du SE4FS (override optionnel)
    |--------------------------------------------------------------------------
    |
    | Si non vide, force l'URL de base utilisée par les `chain --replace`
    | (ex. `http://192.168.122.50` ou `http://se4fs.lan`). Sinon, le service
    | reconstruit l'URL depuis le `Request` reçu (Host + scheme).
    |
    | Cas d'usage : si le SE4FS est derrière un reverse proxy qui réécrit
    | l'Host header, on peut forcer l'URL canonique ici.
    |
    */
    'se4fs_url' => env('IPXE_SE4FS_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Paramètres du menu iPXE
    |--------------------------------------------------------------------------
    */
    'menu' => [
        // Timeout par défaut pour les postes connus (5s — iso-legacy boot.php:45).
        'default_timeout_ms' => (int) env('IPXE_DEFAULT_TIMEOUT_MS', 5000),

        // Timeout pour les postes inconnus (10s — iso-legacy boot.php:58 —
        // laisse plus de temps au user pour interrompre le boot disk).
        'unknown_timeout_ms' => (int) env('IPXE_UNKNOWN_TIMEOUT_MS', 10000),

        // Résolution console iPXE.
        'resolution_x' => (int) env('IPXE_RESOLUTION_X', 1024),
        'resolution_y' => (int) env('IPXE_RESOLUTION_Y', 768),

        // Background PNG affiché par la console iPXE (chemin relatif servi
        // par Apache/Laravel à l'URL `${se4fs}/png/ipxe-se4.png`).
        'background_png' => env('IPXE_BACKGROUND_PNG', 'png/ipxe-se4.png'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback boot disk
    |--------------------------------------------------------------------------
    |
    | Liste des modèles matériel (valeur de la variable iPXE `${product}`) qui
    | doivent **forcer** le branchement UEFI dans `boot_disk` même quand
    | `${platform}` n'est pas `efi`. Liste iso-legacy
    | `sambaedu/includes/ipxe_functions.inc.php:18-32`.
    |
    | **Source de vérité unique** : ce fichier. L'override via env n'est PAS
    | supporté (fix review #7 / Q2 Henri) — l'ancien `(array) env(...)`
    | cassait le parsing CSV en singleton inutilisable. Pour ajouter un
    | modèle, éditer ce tableau directement. Si un besoin opérationnel
    | d'override env apparaît, parser via `explode(',', ...)`.
    |
    */
    'boot_disk' => [
        'force_uefi_products' => [
            'Precision T1700',
            'Precision Tower 3620',
            'Precision Tower 3420',
            'OptiPlex 3050',
            'OptiPlex 3010',
            'OptiPlex 3020',
            'OptiPlex 3030',
            'OptiPlex 3040',
            'HP Z240 Tower Workstation',
            'HP 280 G2 SFF',
            'HP EliteBook 850 G1',
            '10M8S1B000',
            '30AT0025FR',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Channel Monolog dédié pour les événements iPXE (D7).
    | Configuration dans `config/logging.php` channel `ipxe`.
    |
    */
    'log' => [
        'channel' => env('IPXE_LOG_CHANNEL', 'ipxe'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.2 — Menu admin natif (D10)
    |--------------------------------------------------------------------------
    |
    | Paramètres rendus dans `resources/views/ipxe/menu/admin.blade.php`.
    | Timeout par défaut iso-legacy `sambaedu/ipxe/admin.php:14` (30s).
    */
    'admin' => [
        'menu_timeout_ms' => (int) env('IPXE_ADMIN_TIMEOUT_MS', 30000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.2 — Menu maintenance natif (D10)
    |--------------------------------------------------------------------------
    |
    | Paramètres rendus dans `resources/views/ipxe/menu/maintenance.blade.php`.
    | Timeout iso-legacy `sambaedu/ipxe/maintenance.php:9` (10s).
    | Background PNG iso-legacy `maintenance.php:24` (`png/sysrescuecd.png`).
    */
    'maintenance' => [
        'menu_timeout_ms' => (int) env('IPXE_MAINTENANCE_TIMEOUT_MS', 10000),
        'background_png' => env('IPXE_MAINTENANCE_BG_PNG', 'png/sysrescuecd.png'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.2 — Actions whitelistées (D10)
    |--------------------------------------------------------------------------
    |
    | URLs de base utilisées par les templates `ipxe.actions.*` pour
    | construire les `kernel` / `initrd` lignes. Si `null`/`''`, le service
    | `IpxeActionResolver` reconstruit depuis `Request::getSchemeAndHttpHost()`
    | suffixé `/ipxe` (parité legacy `admin.php:12`).
    |
    | `os_url`     → base des assets OS (sysresccd, clonezilla, Win10...).
    | `script_url` → base des scripts dynamiques (autorun.php, repair.bat.php).
    | `se4install_passwd_config_key` → clé config qui héberge le mot de passe
    |                                  root sysrescuecd (iso-legacy `actions/rescuecd.php:6`).
    |                                  Lecture via `config(<clé>)` côté serveur
    |                                  uniquement, jamais dans les logs.
    */
    /*
    |--------------------------------------------------------------------------
    | Story 3.3 — Enrollment Machine (D11)
    |--------------------------------------------------------------------------
    |
    | Paramètres pour les 5 endpoints `/ipxe/enrollment/*` (name, byod, room,
    | parc-add, parc-remove) — port natif des fichiers legacy
    | `sambaedu/ipxe/{enregistrement,enregistrement_byod,salles,parcs,enleveparc}.php`.
    |
    | Le suffix de hostname iso-legacy (`add_hostname_suffix()`) reste lu
    | via `config('sambaedu.legacy_ldap.suffix')` qui existe depuis 16.3b
    | — pas de duplication ici.
    */
    'enrollment' => [
        // Active la branche enrollment depuis le menu admin (3.3). Si false,
        // l'item `(n)` nommer / `(a)` salle / `(p)` parcs est masqué — utile
        // pour freezer une VM de tests en pré-prod.
        'enabled' => filter_var(env('IPXE_ENROLLMENT_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Timeout des menus enrollment (10s — iso-legacy salles.php:15).
        'menu_timeout_ms' => (int) env('IPXE_ENROLLMENT_TIMEOUT_MS', 10000),

        // Limite de salles physiques affichées dans le menu interactif
        // (cas pathologique > 50 salles). Au-delà, on tronque + affiche un
        // item "** voir UI admin SE5 **" (placeholder Phase 3).
        'max_rooms_in_menu' => (int) env('IPXE_ENROLLMENT_MAX_ROOMS', 50),

        // Limite de parcs logiques affichés idem.
        'max_parcs_in_menu' => (int) env('IPXE_ENROLLMENT_MAX_PARCS', 50),
    ],

    'actions' => [
        'os_url' => env('IPXE_OS_URL', null),
        'script_url' => env('IPXE_SCRIPT_URL', null),
        // IMPORTANT: `se4install_passwd` (lu via `config(<clé>)`) doit rester
        // ASCII-safe (pas d'espace, pas de newline, idéalement uniquement
        // `[A-Za-z0-9._-]`). La valeur est injectée brute dans la cmdline
        // kernel iPXE `rootpass=...` — un espace ou un newline casserait le
        // parsing iPXE et rescuecd booterait sans mot de passe root sans
        // erreur visible (fix review #4 / pertinence opus 1). Pas de
        // sanitisation côté code (doc only — la config admin est trusted
        // boundary).
        'se4install_passwd_config_key' => env('IPXE_SE4INSTALL_PASSWD_KEY', 'sambaedu.se4install_passwd'),

        /*
        |--------------------------------------------------------------------------
        | Story 3.2 — Action `winpe` (fix review #2 / Q2 Henri)
        |--------------------------------------------------------------------------
        |
        | Whitelist stricte des valeurs `$version` autorisées dans la cmdline
        | iPXE rendue par `actions/winpe.blade.php`. La liste DOIT rester
        | ASCII strict (`[A-Za-z0-9]+`) — un newline/espace dans une valeur
        | permet une injection iPXE (newline → ligne `kernel http://evil/x`
        | exécutée par le firmware). Toute valeur reçue hors whitelist est
        | rejetée côté FormRequest (422) ET côté resolver (fallback
        | `DEFAULT_WIN_VERSION='Win11'` — défense en profondeur si
        | FormRequest court-circuitée par les tests).
        |
        | Pour ajouter une version (Win12), éditer ce tableau ET le folder
        | servi via Apache (`Win12/wimboot`, `Win12/winpeshl.ini`...).
        |
        */
        'winpe' => [
            'allowed_versions' => ['Win10', 'Win11'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.4 — Installation Linux (D11)
    |--------------------------------------------------------------------------
    |
    | Paramètres pour les 4 endpoints `/ipxe/installation-linux`,
    | `/ipxe/linux/{preseed,action,autorun}` — port natif des fichiers legacy
    | `sambaedu/ipxe/installation-linux.php` + `linux/*.php` +
    | `actions/{deb_*,ubuntu64,nird}.php`.
    */
    'linux' => [
        // Active la branche Installation Linux depuis le menu admin (3.4).
        // Si false, l'item (l) Installation Linux est masqué dans /ipxe/admin.
        'enabled' => filter_var(env('IPXE_INSTALL_LINUX_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Timeout du menu installation-linux (10s — iso-legacy installation-linux.php:9).
        'menu_timeout_ms' => (int) env('IPXE_INSTALL_LINUX_TIMEOUT_MS', 10000),

        // Background PNG affiché par la console iPXE du menu installation-linux.
        'background_png' => env('IPXE_INSTALL_LINUX_BG_PNG', 'png/linux2.png'),

        // Variante par défaut sélectionnée (iso-legacy installation-linux.php:29).
        'default_variant' => env('IPXE_INSTALL_LINUX_DEFAULT', 'install_deb_gnome'),

        // Liste des items menu (whitelist enum + labels iPXE-safe ASCII).
        // Pour ajouter/retirer un item, éditer ce tableau + le case enum
        // correspondant dans IpxeAdminAction.
        'menu_items' => [
            ['enum' => 'install_deb_base',     'label' => 'Debian base (sans desktop)'],
            ['enum' => 'install_deb_gnome',    'label' => 'Debian + GNOME (defaut)'],
            ['enum' => 'install_deb_lxde',     'label' => 'Debian + LXDE'],
            ['enum' => 'install_deb_kde',      'label' => 'Debian + KDE'],
            ['enum' => 'install_deb_mate',     'label' => 'Debian + MATE'],
            ['enum' => 'install_deb_xfce',     'label' => 'Debian + XFCE'],
            ['enum' => 'install_deb_cinnamon', 'label' => 'Debian + Cinnamon'],
            ['enum' => 'install_nird',         'label' => 'NIRD (Debian derivee primaire)'],
            ['enum' => 'install_ubuntu64',     'label' => 'Ubuntu 20.04 (hors domaine)'],
        ],

        // Préfixe URL des assets debian-installer/ubuntu-installer servis via
        // l'OS_URL résolu (cf. config/ipxe.php section actions).
        // Iso-legacy `actions/deb_*.php:8` : `{os_url}/debian-installer/amd64/linux`.
        'kernel_paths' => [
            'debian' => env('IPXE_LINUX_DEBIAN_KERNEL', '/debian-installer/amd64/linux'),
            'debian_initrd' => env('IPXE_LINUX_DEBIAN_INITRD', '/debian-installer/amd64/initrd.gz'),
            'ubuntu' => env('IPXE_LINUX_UBUNTU_KERNEL', '/ubuntu-installer/amd64/linux'),
            'ubuntu_initrd' => env('IPXE_LINUX_UBUNTU_INITRD', '/ubuntu-installer/amd64/initrd.gz'),
            'nird' => env('IPXE_LINUX_NIRD_KERNEL', '/nird/casper/vmlinuz'),
            'nird_initrd' => env('IPXE_LINUX_NIRD_INITRD', '/nird/casper/initrd.gz'),
        ],

        // Chemin du dossier de fragments preseed (assets statiques projet).
        // Décision D11 — copier les fragments depuis sambaedu/ipxe/linux/*.cfg
        // vers resources/ipxe/linux/*.cfg pour mise sous version control.
        'preseed_fragments_path' => env(
            'IPXE_LINUX_PRESEED_FRAGMENTS',
            resource_path('ipxe/linux'),
        ),

        // Whitelist stricte des distributions acceptées par /ipxe/linux/preseed.
        // Cf. enum LinuxDistribution.
        'allowed_distributions' => ['debian', 'ubuntu', 'nird'],

        // Whitelist stricte des variantes desktop acceptées par
        // /ipxe/linux/preseed. Cf. enum LinuxDesktopVariant.
        'allowed_variants' => ['base', 'gnome', 'lxde', 'kde', 'mate', 'xfce', 'cinnamon'],

        // Whitelist stricte des versions Debian/Ubuntu acceptées (au-delà,
        // fallback config('sambaedu.linux.version_debian') par défaut).
        // Pour ajouter une version, éditer ici ET les assets servis par Apache.
        'allowed_os_versions' => [
            'debian', 'ubuntu', 'nird',
            'trixie', 'bookworm', 'bullseye',
            'focal', 'jammy',
        ],
    ],
];
