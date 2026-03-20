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
    'legacy_path' => env('SAMBAEDU_LEGACY_PATH', '/var/www/sambaedu'),

    'legacy_base_url' => env('SAMBAEDU_LEGACY_BASE_URL', 'http://127.0.0.1:80'),

    'block_migrated_routes' => env('LEGACY_BLOCK_MIGRATED_ROUTES', true),

    'log_404' => env('LEGACY_LOG_404', true),

    /*
    | Routes legacy bloquées : mapping regex_pattern => URL SER de redirection.
    | Les patterns sont testés avec preg_match() sur le path de la requête.
    | Les routes dans allowed_legacy_routes prennent la priorité.
    */
    'blocked_legacy_routes' => [
        // Exemple : '^gpo/raccourcis' => '/app/shortcuts/list',
    ],

    /*
    | Routes legacy explicitement autorisées (passent même si bloquées).
    | Tableau de patterns regex.
    */
    'allowed_legacy_routes' => [
        // Exemple : '^gpo/shortcuts_out\.php$',
    ],

    'se4ad_ip' => env('SAMBAEDU_SE4AD_IP'),
    'se4ad_etab_ip' => env('SAMBAEDU_SE4AD_ETAB_IP'),
    'strict_local_ad' => env('SAMBAEDU_STRICT_LOCAL_AD', true),

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'legacy_ldap' => [
        'server' => env('LDAP_SERVER', 'localhost'),
        'port' => env('LDAP_PORT', 389),
        'base_dn' => env('LDAP_BASE_DN', ''),
        'bind_dn' => env('LDAP_BIND_DN', ''),
        'bind_password' => env('LDAP_BIND_PASSWORD', ''),
    ],
    
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

    'wpkg' => [
        // Chemin de stockage local des applications
        'storage_path' => env('WPKG_STORAGE_PATH', '/var/sambaedu/unattended/install'),
        
        // Timeout pour le téléchargement des installeurs (secondes)
        'download_timeout' => env('WPKG_DOWNLOAD_TIMEOUT', 300),
        
        // Timeout pour la synchronisation des dépôts (secondes)
        'sync_timeout' => env('WPKG_SYNC_TIMEOUT', 30),
        
        // Chemin du fichier packages.xml local
        'packages_xml_path' => env('WPKG_PACKAGES_XML', '/var/sambaedu/unattended/install/wpkg/packages.xml'),
    ],
]; 