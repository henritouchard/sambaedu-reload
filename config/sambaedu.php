<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configuration SambaEdu
    |--------------------------------------------------------------------------
    |
    | Configuration spécifique à SambaEdu
    |
    */

    'rte_api_key' => env('RTE_API_KEY', ''),

    /*
    |--------------------------------------------------------------------------
    | Legacy Catchall
    |--------------------------------------------------------------------------
    | Chemin absolu vers le répertoire legacy PHP SambAEdu.
    | Utilisé par LegacyCatchallController pour résoudre les routes non migrées.
    */
    'legacy_path' => env('LEGACY_PATH', '/var/www/sambaedu'),

    'legacy_base_url' => env('LEGACY_BASE_URL', 'http://127.0.0.1:80'),


    // UAI de l'établissement — utilisé pour stripper le préfixe dans les URLs legacy
    'etab_ou' => env('ETAB_OU', ''),

    // Nom affiché de l'établissement (override admin possible via .env)
    'establishment_name' => env('ESTABLISHMENT_NAME') ?: 'Mon établissement',
    
    'block_migrated_routes' => env('LEGACY_BLOCK_MIGRATED_ROUTES', true),

    'log_404' => env('LEGACY_LOG_404', true),

    /*
    | Routes legacy bloquées : mapping regex_pattern => URL SER de redirection.
    | Les patterns sont testés avec preg_match() sur le path de la requête.
    | Les routes dans allowed_legacy_routes prennent la priorité.
    */
    'blocked_legacy_routes' => [
        '^annu2/annu\.php' => 'app/users',
        'parcs/show_parc.php' => 'app/parcs',
        'gpo/shortcuts_out\.php' => 'app/shortcuts',
        // Story 16.2 — Décision SM D5 : bloquer uniquement la page d'index legacy.
        // Les pages d'édition (gpo-maj.php, gpo-export.php, etc.) restent
        // accessibles pour la cohabitation jusqu'aux Stories 16.4/16.5.
        // Story 16.9 — cible migrée vers `admin/settings/gpo` (l'UI vit
        // désormais sous le groupe admin, cf. routes/web.php).
        '^gpo/gestion_gpo\.php$' => 'admin/settings/gpo',
        // Story 16.3c — Wine UI native. La page `/gpo/wine.php` legacy est
        // remplacée par `/admin/settings/gpo/wine` (Livewire SFC + Job queue,
        // renommée par Story 16.9). Redirect 302 (pattern iso 16.2 D5).
        '^gpo/wine\.php(?:\?.*)?$' => 'admin/settings/gpo/wine',
    ],

    /*
    | Routes legacy explicitement autorisées (passent même si bloquées).
    | Tableau de patterns regex.
    */
    'allowed_legacy_routes' => [
        // Exemple : '^gpo/shortcuts_out\.php$',
    ],

    /*
    | Routes legacy en mode direct : pas de shims, le vrai code legacy
    | gère tout (connexion LDAP/AD, opérations d'écriture...).
    | Tableau de patterns regex testés sur le REQUEST_URI.
    */
    'direct_legacy_routes' => [
        '^/ipxe/',
    ],

    'se4ad_ip' => env('SE4AD_IP'),
    'se4ad_etab_ip' => env('SE4AD_ETAB_IP'),
    'strict_local_ad' => env('STRICT_LOCAL_AD', true),

    // Port HTTP du legacy SambaEdu (vhost séparé). Utilisé par le helper
    // `legacy_url()` pour construire les liens vers les pages legacy depuis
    // les vues Laravel (cf. Story 16.9 — UI admin GPO sous /admin/settings/gpo).
    'legacy_port' => (int) env('SAMBAEDU_LEGACY_PORT', 8082),

    'trusted_proxies' => env('TRUSTED_PROXIES'),


    /*
    |--------------------------------------------------------------------------
    | Configuration SE4FS pour applications tierces
    |--------------------------------------------------------------------------
    |
    | Configuration pour l'intégration SE4FS avec des applications tierces
    |
    */
    
    'se4fs' => [
        // Identifiant unique de cette instance SE4FS
        'instance_id' => env('SE4FS_INSTANCE_ID', 'se4fs-' . gethostname()),
        
        // Activation de l'API SE4FS
        'api_enabled' => env('SE4FS_API_ENABLED', true),
        
        // Clé secrète pour la génération de tokens
        'secret_key' => env('SE4FS_SECRET_KEY', 'your-secret-key-here'),
        
        // Timeout pour les webhooks sortants (en secondes)
        'webhook_timeout' => env('SE4FS_WEBHOOK_TIMEOUT', 30),
        
        // Niveau de log pour SE4FS
        'log_level' => env('SE4FS_LOG_LEVEL', 'info'),
        
        // Informations sur l'établissement
        'establishment' => [
            'name' => env('SE4FS_ESTABLISHMENT_NAME', 'SE4FS - Établissement'),
            'uai' => env('SE4FS_ESTABLISHMENT_UAI', '0751234A'),
            'dn' => env('SE4FS_ESTABLISHMENT_DN', 'DC=etablissement,DC=ac-paris,DC=fr'),
            'type' => env('SE4FS_ESTABLISHMENT_TYPE', 'lycee'),
            'academie' => env('SE4FS_ESTABLISHMENT_ACADEMIE', 'Paris'),
            'address' => [
                'street' => env('SE4FS_ESTABLISHMENT_STREET', '123 Rue de l\'Éducation'),
                'city' => env('SE4FS_ESTABLISHMENT_CITY', 'Paris'),
                'postal_code' => env('SE4FS_ESTABLISHMENT_POSTAL', '75001'),
                'country' => 'France'
            ]
        ],
        
        // Configuration application tierce
        'client' => [
            'webhook_url' => env('CLIENT_WEBHOOK_URL', ''),
            'api_token' => env('CLIENT_API_TOKEN', ''),
            'webhook_token' => env('CLIENT_WEBHOOK_TOKEN', ''),
        ],
        
        // Rate limiting par endpoint
        'rate_limits' => [
            'discovery' => env('SE4FS_RATE_DISCOVERY', '10,1'), // 10 req/min
            'handshake' => env('SE4FS_RATE_HANDSHAKE', '5,1'),  // 5 req/min
            'api' => env('SE4FS_RATE_API', '100,1'),            // 100 req/min
        ],
        
        // Configuration webhook
        'webhook' => [
            'enabled' => env('SE4FS_WEBHOOK_ENABLED', true),
            'events' => [
                'user_login' => env('SE4FS_EVENT_USER_LOGIN', true),
                'user_logout' => env('SE4FS_EVENT_USER_LOGOUT', true),
                'file_uploaded' => env('SE4FS_EVENT_FILE_UPLOADED', true),
                'file_shared' => env('SE4FS_EVENT_FILE_SHARED', true),
                'quota_exceeded' => env('SE4FS_EVENT_QUOTA_EXCEEDED', true),
                'system_status' => env('SE4FS_EVENT_SYSTEM_STATUS', true),
            ],
            'retry_attempts' => env('SE4FS_WEBHOOK_RETRY', 3),
            'retry_delay' => env('SE4FS_WEBHOOK_RETRY_DELAY', 5), // secondes
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration WPKG / AppStore
    |--------------------------------------------------------------------------
    |
    | Configuration pour la gestion des applications WPKG
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Configuration iPXE / Déploiement réseau
    |--------------------------------------------------------------------------
    |
    | Variables requises par le module legacy ipxe pour le boot réseau,
    | le déploiement Windows et l'installation Linux.
    |
    */

    'se4fs_ip'          => env('SE4FS_IP', ''),
    'se4fs_name'        => env('SE4FS_NAME', ''),
    'ipxe_url'          => env('IPXE_URL', ''),
    'se4install_name'   => env('SE4INSTALL_NAME', ''),
    'se4install_passwd' => env('SE4INSTALL_PASSWD', ''),

    /*
    |--------------------------------------------------------------------------
    | Story 3.4 — Variables Linux preseed (D11)
    |--------------------------------------------------------------------------
    |
    | Variables consommées par LinuxPreseedService pour interpoler les
    | placeholders ###_<KEY>_### dans les fragments preseed. Toutes ces
    | valeurs DOIVENT être lues depuis `.env` (jamais hardcodées) — elles
    | contiennent des secrets (mots de passe root, clés AD, tokens).
    |
    | Convention : tous les SECRETS sont rendus en clair dans le preseed
    | text/plain renvoyé au poste LAN qui boot. Mitigation = auth.v1.lan-only
    | + MAC/UUID matching strict (cf. story 3.4 D3).
    */
    /*
    |--------------------------------------------------------------------------
    | Story 3.5 — Variables Windows unattend + install.bat (D11)
    |--------------------------------------------------------------------------
    |
    | Variables consommées par WindowsUnattendBuilder (XML DOMDocument) et
    | WindowsInstallBatBuilder (bash WinPE). Toutes ces valeurs DOIVENT être
    | lues depuis `.env` (jamais hardcodées) — elles contiennent des secrets
    | (mots de passe admin local Windows, clé produit).
    |
    | Convention : tous les SECRETS sont rendus en clair dans l'unattend.xml +
    | install.bat text/plain renvoyés au poste LAN qui boot. Mitigation =
    | auth.v1.lan-only + MAC/UUID matching strict (cf. story 3.5 D3).
    */
    'windows' => [
        // Nom de l'admin local Windows créé sur chaque poste installé.
        // Iso-legacy `windows.inc.php:304` `$config['adminse_name']`.
        'adminse_name' => env('SAMBAEDU_ADMINSE_NAME', 'adminse'),

        // Mot de passe de l'admin local Windows (SECRET).
        // Iso-legacy `windows.inc.php:305` `$config['adminse_passwd']`.
        'adminse_passwd' => env('SAMBAEDU_ADMINSE_PASSWD', ''),

        // Product Key Windows par défaut (KMS generic Win10/11) — utilisé
        // comme fallback si pas de clé spécifique fournie. Iso-legacy
        // `windows.inc.php:265`.
        'win_key' => env('SAMBAEDU_WIN_KEY', 'VK7JG-NPHTM-C97JM-9MPGT-3V66T'),

        // User local Windows pour le mode `perso=1` (pc perso hors domaine).
        // Iso-legacy `windows.inc.php:234` chain `win_user ?? perso_user ?? linux_user`.
        'win_user' => env('SAMBAEDU_WIN_USER', ''),

        // Mot de passe du user perso (SECRET).
        'win_user_passwd' => env('SAMBAEDU_WIN_USER_PASSWD', ''),

        // Flag autologon (LogonCount = 4294967295 si !join && win_autologon == 1).
        // Iso-legacy `windows.inc.php:335-339`.
        'win_autologon' => (int) env('SAMBAEDU_WIN_AUTOLOGON', 0),
    ],

    'linux' => [
        'locale' => env('SAMBAEDU_LINUX_LOCALE', 'fr_FR'),
        'keyboard' => env('SAMBAEDU_LINUX_KEYBOARD', 'fr(latin9)'),
        'interface' => env('SAMBAEDU_LINUX_INTERFACE', 'auto'),
        'user' => env('SAMBAEDU_LINUX_USER', ''),
        'user_passwd' => env('SAMBAEDU_LINUX_USER_PASSWD', ''), // SECRET
        'version_debian' => env('SAMBAEDU_LINUX_VERSION_DEBIAN', 'trixie'),
        'apt_proxy' => env('SAMBAEDU_LINUX_APT_PROXY', ''),
        'server_proxy' => env('SAMBAEDU_LINUX_SERVER_PROXY', ''),
        'proxy_type' => env('SAMBAEDU_LINUX_PROXY_TYPE', 'none'),
        'proxy_address' => env('SAMBAEDU_LINUX_PROXY_ADDRESS', ''),
        'proxy_port' => env('SAMBAEDU_LINUX_PROXY_PORT', ''),
        'proxy_url' => env('SAMBAEDU_LINUX_PROXY_URL', ''),
        'token' => env('SAMBAEDU_LINUX_TOKEN', ''), // SECRET
        'depot_type' => env('SAMBAEDU_LINUX_DEPOT_TYPE', 'main'),
        'commande_fin_preseed' => env('SAMBAEDU_LINUX_COMMANDE_FIN', ''),
        'disk' => env('SAMBAEDU_LINUX_DISK', ''), // optionnel — force /dev/sdX si défini
        'mask' => env('SAMBAEDU_LINUX_MASK', ''),
        'gateway' => env('SAMBAEDU_LINUX_GATEWAY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.4 — Variables AD/LDAP consommées par le preseed (D11)
    |--------------------------------------------------------------------------
    | Ces variables sont interpolées dans les fragments preseed via
    | LinuxPreseedService + PreseedPlaceholders. Toutes sont overridables
    | via .env. Les SECRETS (passwd, token) restent vides par défaut pour
    | éviter d'embarquer des credentials dans le repo.
    */
    'ldap_port' => env('SAMBAEDU_LDAP_PORT', 636),
    'ldap_admin_passwd' => env('SAMBAEDU_LDAP_ADMIN_PASSWD', ''), // SECRET
    'ldap_admin_name' => env('SAMBAEDU_LDAP_ADMIN_NAME', 'Administrator'),
    'ldap_base_dn' => env('SAMBAEDU_LDAP_BASE_DN', ''),
    'computers_rdn' => env('SAMBAEDU_COMPUTERS_RDN', 'CN=Computers'),
    'admin_rdn' => env('SAMBAEDU_ADMIN_RDN', 'CN=Users'),
    'admin_passwd' => env('SAMBAEDU_ADMIN_PASSWD', ''), // SECRET
    'samba_domain' => env('SAMBAEDU_SAMBA_DOMAIN', ''),
    'se4ad_name' => env('SAMBAEDU_SE4AD_NAME', ''),
    'se4_pub_key' => env('SAMBAEDU_SE4_PUB_KEY', ''),
    'domain' => env('SAMBAEDU_DOMAIN', ''),

    /*
    |--------------------------------------------------------------------------
    | Story 8.1 — Réseau / DHCP (FR20 + FR22)
    |--------------------------------------------------------------------------
    | Configuration du service DHCP géré nativement par SER. Les chemins sont
    | overridables par .env pour les environnements de test (où l'on ne veut
    | pas écrire dans `/etc/sambaedu`).
    |
    | `reload_command` : script shell legacy `/usr/share/sambaedu/sbin/make_dhcpd_conf.sh`
    | qui régénère `dhcpd.conf` puis recharge `isc-dhcp-server`. **Attention** :
    | le legacy contient le bug `/sh/share/...` (cf. `dhcpd.inc.php:733`)
    | **à ne PAS reproduire**.
    |
    | Sudoers attendu sur la VM (cf. `docs/qa/domains/network.md`) :
    |   www-data ALL=(root) NOPASSWD: /usr/bin/systemctl is-active isc-dhcp-server.service, /usr/share/sambaedu/sbin/make_dhcpd_conf.sh
    */
    'dhcp' => [
        'reservations_file' => env('DHCP_RESERVATIONS_FILE', '/etc/sambaedu/reservations.inc'),
        'leases_file' => env('DHCP_LEASES_FILE', '/var/lib/dhcp/dhcpd.leases'),
        'reload_command' => env('DHCP_RELOAD_COMMAND', '/usr/share/sambaedu/sbin/make_dhcpd_conf.sh'),
        'service_name' => env('DHCP_SERVICE_NAME', 'isc-dhcp-server.service'),
    ],

    'wpkg' => [
        // Chemin de stockage local des applications
        'storage_path' => env('WPKG_STORAGE_PATH', '/var/sambaedu/unattended/install'),

        // Timeout pour le téléchargement des installeurs (secondes)
        'download_timeout' => env('WPKG_DOWNLOAD_TIMEOUT', 300),

        // Timeout pour la synchronisation des dépôts (secondes)
        'sync_timeout' => env('WPKG_SYNC_TIMEOUT', 30),

        // Chemin du fichier packages.xml local
        'packages_xml_path' => env('WPKG_PACKAGES_XML', '/var/sambaedu/unattended/install/wpkg/packages.xml'),

        // Pipeline déploiement WPKG (Story 15.1) — chemins **en dur** : décision
        // 2026-05-03, pas de variables d'env dédiées. Les ops modifient ce
        // fichier de config si une customisation par environnement est
        // nécessaire (cf. docs/wpkg-deploy/architecture.md § Migration .env).
        // Parité legacy : sambaedu/wpkg/log.php:14, depot_accueil.php:90.
        'deploy_path'     => '/var/sambaedu/unattended/install/wpkg',
        'ini_path'        => '/var/sambaedu/unattended/install/wpkg/ini',
        'reports_inbox'   => '/var/sambaedu/unattended/install/wpkg/rapports',
        'reports_archive' => '/var/sambaedu/unattended/install/wpkg/rapports/archive',

        // IPs autorisées à envoyer des rapports (API locale)
        'report_ingestion_allowed_ips' => array_filter(
            explode(',', env('WPKG_ALLOWED_IPS', '127.0.0.1,::1'))
        ),

        // Story 15.5 — Rétention des archives brutes des rapports (en jours).
        // La commande `wpkg:reports:archive:rotate` (schedulée daily 03:45)
        // supprime les fichiers d'archive plus anciens que cette valeur.
        'reports_archive_retention_days' => (int) env('WPKG_REPORTS_ARCHIVE_RETENTION_DAYS', 90),

        // Story 15.5 — Durée de validité d'un ancien secret après rotation
        // (chevauchement). Permet aux postes pas encore mis à jour de
        // continuer à pousser leurs rapports.
        'secret_rotation_overlap_days' => (int) env('WPKG_SECRET_ROTATION_OVERLAP_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Configuration GPO (Epic 16, Story 16.1)
    |--------------------------------------------------------------------------
    |
    | Valeurs **en dur** (pas de env(...)) — décision SM D8 / cohérence Story 15.1
    | AC4.1. Si une customisation par environnement est nécessaire, modifier
    | ce fichier directement (les ops auront un patch isolé à appliquer).
    |
    | Exceptions : GPO_LOG_LEVEL et GPO_LOG_DAYS restent paramétrables par env
    | (config/logging.php — verbosité ajustable sans redeploy en phase de
    | transition Epic 16).
    |
    | Parité legacy : sambaedu/includes/samba-tool.inc.php:69 (bin /usr/bin/samba-tool),
    | gpo.inc.php:1053 (policies temp dir), samba-tool.inc.php:62
    | (--use-kerberos=required).
    */

    'gpo' => [
        // Chemin absolu du binaire samba-tool.
        'bin_path' => '/usr/bin/samba-tool',

        // Chemin SYSVOL local (partage Samba). Utilisé pour lecture/écriture
        // des fichiers .pol / .xml / .ini de policies (Stories 16.3, 16.4).
        'sysvol_path' => '/var/lib/samba/sysvol',

        // Répertoire de travail pour `samba-tool gpo fetch` — parité legacy
        // gpo.inc.php:1053. À garder lisible/écrivable par le user PHP-FPM.
        'policies_temp_path' => '/var/www/sambaedu/temp/policies',

        // Timeout (secondes) appliqué à chaque appel `samba-tool` via
        // SambaToolRunner. Override possible avec ->withTimeout() côté caller.
        'samba_tool_timeout' => 30,

        // Argument d'authentification global passé à toutes les commandes
        // samba-tool. Valeurs possibles :
        //  - `--use-kerberos=required` : strict (parité legacy samba-tool.inc.php:62)
        //    — exige un ccache Kerberos valide pour www-admin (kinit ou keytab).
        //  - `--use-kerberos=desired` : tente Kerberos, fallback NTLM via
        //    `passdb.tdb` (compte machine). C'est le défaut ici car la
        //    plupart des déploiements n'ont pas de keytab/cron kinit
        //    configuré, et passdb.tdb est lisible par www-admin (cf. check
        //    `Samba private files`). Niveau de sécu équivalent en LAN
        //    établissement (legacy SambaEdu utilise déjà NTLM sur certaines
        //    opérations).
        //  - `--use-kerberos=off` : NTLM only (debug / setups dégradés).
        // Overridable via env GPO_KERB_OPTION.
        'kerb_option' => env('GPO_KERB_OPTION', '--use-kerberos=desired'),

        // Story 16.6 — Sous-config WPKG GPO synchronizer (`WpkgGpoSynchronizer`).
        // Toutes les valeurs sont overridables via env() pour permettre un
        // déploiement Ansible / tuning prod sans patcher le code.
        //
        // - `template_path` : path du template officiel `.zip` (parité legacy
        //   `/usr/share/sambaedu/gpo/se4_wpkg.zip`). Overridable pour tests
        //   et installations atypiques.
        // - `bearer_required` : feature flag Phase 2 (Story 15.5). Par défaut
        //   `false` (mode tolérant — TD-16.6-3). Passe à `true` pour bumper
        //   la sévérité Error/Warning quand des postes liés sont sans secret.
        // - `lock_timeout` : TTL du `Cache::lock('gpo:wpkg:sync', N)`. 300 s
        //   par défaut (review fix #10 — 60 s trop court pour absorber un
        //   `import_gpo` lent : extraction + spécialisation + `smbclient put`).
        // - `lock_wait` : délai max d'attente bloquante pour acquérir le lock
        //   (`$lock->block(N)`). 30 s par défaut (review fix #4).
        'wpkg_sync' => [
            'template_path' => env('GPO_WPKG_TEMPLATE_PATH', '/usr/share/sambaedu/gpo/se4_wpkg.zip'),
            'bearer_required' => (bool) env('GPO_WPKG_BEARER_REQUIRED', false),
            'lock_timeout' => (int) env('GPO_WPKG_LOCK_TIMEOUT', 300),
            'lock_wait' => (int) env('GPO_WPKG_LOCK_WAIT', 30),
        ],

        // Story 16.7 — Whitelist des substitutions `###_KEY_###` autorisées
        // dans les templates de scripts applications (`.windows`, `.linux`,
        // `scripts.json`). Décision user D3 (2026-05-12) : config statique.
        //
        // Sécurité (audit F3 audit-gpo-legacy adressé) :
        //  - Whitelist immuable : seules les clés explicitement listées peuvent
        //    être substituées.
        //  - Aucun input user (machine, user, action, uuid…) injectable comme
        //    clé : la map est strictement statique et lue par
        //    `ApplicationScriptsAssembler::applySubstitutions()`.
        //  - Les placeholders hors whitelist restent inchangés (warning log
        //    channel `daily`) → ne casse pas iso-bytes legacy
        //    (`traitement_data.inc.php::write_param()` ne substituait que les
        //    clés de config présentes).
        //
        // Format des specs (sérialisable — compatible `php artisan config:cache`) :
        //  - ['config' => 'path', 'env' => 'VAR', 'default' => 'fallback']
        //    Chaîne de résolution : config() → env() → default. Une valeur
        //    vide ('') est traitée comme manquante (fall-through).
        //  - ['value' => 'static'] : valeur littérale (utilisée pour `TMP_DIR`).
        //  - Une spec qui résout à null est ignorée (placeholder laissé
        //    inchangé dans la sortie).
        //
        // @legacy-port path="sambaedu/includes/traitement_data.inc.php (write_param)"
        // @see \App\Gpo\Services\ApplicationScriptsAssembler::resolveSubstitutionValue
        'applications' => [
            'substitutions' => [
                'whitelist' => [
                    // Identifiant DNS du serveur SE4FS (utilisé dans curl URL des
                    // scripts cmd/bash, cf. legacy `applications.inc.php:399,405,423`).
                    'SE4FS_NAME' => [
                        'config' => 'sambaedu.se4fs_name',
                        'env' => 'SE4FS_NAME',
                    ],

                    // Domaine DNS de l'établissement (suffixe utilisé dans
                    // `applications.inc.php:405,426`).
                    'DOMAIN' => [
                        'config' => 'sambaedu.domain',
                        'env' => 'SE4FS_DOMAIN',
                    ],

                    // Identifiant établissement (UAI / RNE) — utilisé dans header
                    // `cmd` `applications.inc.php:368` (`SET TAG=...`).
                    'UAI' => [
                        'config' => 'sambaedu.uai',
                        'env' => 'SE4FS_UAI',
                    ],

                    // Chemin partage NETLOGON (déploiement scripts/exécutables Windows).
                    'NETLOGON_PATH' => [
                        'config' => 'sambaedu.netlogon_path',
                        'env' => 'NETLOGON_PATH',
                        'default' => '/var/lib/samba/sysvol',
                    ],

                    // URL/base du dépôt WPKG (consommée par `wpkg_scripts` côté legacy).
                    'WPKG_URL' => [
                        'config' => 'sambaedu.wpkg.base_url',
                        'default' => '',
                    ],

                    // Domaine Samba (NetBIOS) — utilisé dans `local_admin_scripts`
                    // pour `net localgroup administrateurs <DOMAIN>\<user>`.
                    'SAMBA_DOMAIN' => [
                        'config' => 'sambaedu.samba_domain',
                        'env' => 'SAMBA_DOMAIN',
                    ],

                    // Dossier temporaire serveur (parité legacy `sys_get_temp_dir()`).
                    'TMP_DIR' => ['value' => '/tmp'],

                    // Nom UI du dossier "Mes Documents" (legacy `applications.inc.php:291`).
                    'CLOUD_PERSO_NAME' => [
                        'config' => 'sambaedu.cloud_perso_name',
                        'default' => 'Mes Documents',
                    ],
                ],
            ],
        ],

        // Story 16.3c — Sous-config Wine (UI admin + Job queue).
        'wine' => [
            // Dossier de base scanné pour lister les conteneurs Wine partagés
            // (`wine-<application>` → option du `<select>` UI). Iso-legacy
            // path = `/var/sambaedu/unattended/install/wine` (cf. `gpo/wine.php:43`).
            'prefix_base' => '/var/sambaedu/unattended/install/wine',

            // Path du script shell exécuté par `GenerateWineImageJob` —
            // documentaire (le script est en dur dans la const du Job pour
            // éviter qu'un override config ouvre une injection).
            'image_script' => '/usr/share/sambaedu/scripts/make_wine_image.sh',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Story 3.6 — Script externe d'extraction ISO Windows (D11)
    |--------------------------------------------------------------------------
    |
    | `install-win-iso.sh` vit sous `/usr/share/sambaedu/scripts/` côté VM
    | (paquet sambaedu). SE5 ne le porte pas — l'invoque via `sudo` depuis
    | le Job `DownloadWindowsIsoJob`. Prérequis sudoers à valider en T0.5 :
    |
    |   # /etc/sudoers.d/sambaedu-iso-install
    |   www-admin ALL=(root) NOPASSWD: /usr/share/sambaedu/scripts/install-win-iso.sh
    |
    | Sans cette règle : le Job échoue avec exit_code 1 + stderr
    | "no tty present and no askpass program specified" — la story 3.6 gère
    | ce cas avec un toast utilisateur clair (cf. AC4.4 + AC4.5).
    |
    | **Pas de wildcard sudo** — la règle DOIT cibler le path strict
    | `/usr/share/sambaedu/scripts/install-win-iso.sh` (defense in depth vs
    | un attaquant qui poserait un script malveillant ailleurs).
    */
    'windows_iso' => [
        // Path absolu du script shell d'extraction. Override possible via
        // env pour les environnements de test (où l'on stubbe le script).
        /* SÉCURITÉ (review post-3.6, #11 / #2 rejeté) : ce path est
         * sudo-allowlisté dans `/etc/sudoers.d/sambaedu-iso-install` (path
         * strict, pas de wildcard). Modifier cette valeur (config ou env
         * `SAMBAEDU_INSTALL_WIN_ISO_SCRIPT`) sans MAJ correspondante du
         * fichier sudoers casse l'install Windows (Job échoue `exit_code 1`
         * + stderr "a password is required") — defense in depth implicite.
         * Tout changement ici DOIT être accompagné d'une coordination Ops. */
        'install_script' => env(
            'SAMBAEDU_INSTALL_WIN_ISO_SCRIPT',
            '/usr/share/sambaedu/scripts/install-win-iso.sh',
        ),

        // User sudoers documentaire (informatif uniquement — le Job ne lit
        // pas ce champ, il est consommé par le runbook QA Section 15).
        'sudoers_user' => env('SAMBAEDU_INSTALL_WIN_ISO_SUDO_USER', 'www-admin'),
    ],
];
