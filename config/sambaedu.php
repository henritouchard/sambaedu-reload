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
        // samba-tool (parité legacy samba-tool.inc.php:62).
        // Sera enrichi quand on supportera l'authentification par compte stocké.
        'kerb_option' => '--use-kerberos=required',
    ],
];
