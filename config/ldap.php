<?php

/**
 * Configuration LdapRecord pour SambaEdu
 * 
 * Cette configuration utilise SambaEduConfig pour récupérer les paramètres
 * depuis la configuration SambaEdu (/etc/sambaedu/).
 */
return [
    'default' => env('LDAP_CONNECTION', 'default'),

    'connections' => [
        'default' => [
            // Les valeurs sont récupérées dynamiquement depuis SambaEduConfig
            // lors de l'initialisation de la connexion dans LdapRecordServiceProvider
            'hosts' => [], // Sera rempli dynamiquement
            'port' => 389, // Par défaut, sera remplacé si LDAPS
            'base_dn' => '', // Sera rempli dynamiquement
            'username' => '', // Sera rempli dynamiquement
            'password' => '', // Sera rempli dynamiquement

            'timeout' => 60,
            'use_ssl' => false, // Sera déterminé selon le mode (ldap/ldaps)
            'use_tls' => false,
            'options' => [
                LDAP_OPT_PROTOCOL_VERSION => 3,
                LDAP_OPT_REFERRALS => 0,
                LDAP_OPT_NETWORK_TIMEOUT => 60,
            ],
        ],
    ],

    'logging' => [
        'enabled' => env('LDAP_LOGGING', true),
        'channel' => env('LOG_CHANNEL', 'stack'),
    ],

    'cache' => [
        'enabled' => env('LDAP_CACHE', false),
        'driver' => env('CACHE_DRIVER', 'file'),
    ],
];

