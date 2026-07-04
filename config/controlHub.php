<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ControlHub Base Configuration
    |--------------------------------------------------------------------------
    */
    /*
    |--------------------------------------------------------------------------
    | SE4FS Instance Configuration
    |--------------------------------------------------------------------------
    */
    'se4fs' => [
        'instance_id' => env('SE4FS_INSTANCE_ID', (string) \Illuminate\Support\Str::uuid()),
        'instance_api_key' => env('SE4FS_INSTANCE_API_KEY', 'se4fs_instance_' . bin2hex(random_bytes(16))),
    ],

    /*
    |--------------------------------------------------------------------------
    | ControlHub API Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'handshake' => '/api/sambaedu/handshake',
        'discovery' => '/api/sambaedu/discovery',
        'heartbeat' => '/api/sambaedu/heartbeat',
        'users' => '/api/sambaedu/users',
        'webhook' => '/api/sambaedu/webhook',
        'token_renewal' => '/api/sambaedu/token/renew',
        'disconnect' => '/api/sambaedu/unregister/{instance_id}',
        // Story 39.2 (canal ③) — émission SE5 → amont du rapport de conformité
        // état-intégral `se5-contract-compliance/v1`. Chemin figé côté amont mais
        // NON ratifié (SE5-CONTRACT-COMPLIANCE-V1.md) — point de coordination R2.
        'contract_compliance' => '/api/sambaedu/contract-compliance/{instance_id}',
    ],

    /*
    |--------------------------------------------------------------------------
    | Compliance Reporting Configuration (Story 39.2 — canal ③)
    |--------------------------------------------------------------------------
    | Cadence FIXE de l'émetteur de conformité (pas de piggyback heartbeat, pas
    | de colonne BDD de throttle). L'amont applique de toute façon sa propre garde
    | de fraîcheur `reported_at` (rejeu = no-op) — une cadence configurable suffit.
    */
    'compliance' => [
        'enabled' => env('CONTROLHUB_COMPLIANCE_ENABLED', true),
        'interval' => env('CONTROLHUB_COMPLIANCE_INTERVAL', 15), // minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | API Configuration
    |--------------------------------------------------------------------------
    */
    'api' => [
        'timeout' => env('CONTROLHUB_API_TIMEOUT', 30),
        'connect_timeout' => env('CONTROLHUB_API_CONNECT_TIMEOUT', 10),
        'retries' => env('CONTROLHUB_API_RETRIES', 3),
        'retry_delay' => env('CONTROLHUB_API_RETRY_DELAY', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Heartbeat Configuration
    |--------------------------------------------------------------------------
    */
    'heartbeat' => [
        'enabled' => env('CONTROLHUB_HEARTBEAT_ENABLED', true),
        'interval' => env('CONTROLHUB_HEARTBEAT_INTERVAL', 5), // minutes
        'timeout' => env('CONTROLHUB_HEARTBEAT_TIMEOUT', 30),
        'max_retries' => env('CONTROLHUB_HEARTBEAT_MAX_RETRIES', 3),
        'max_failures' => env('CONTROLHUB_HEARTBEAT_MAX_FAILURES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Configuration
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'ttl' => env('CONTROLHUB_CACHE_TTL', 3600), // 1 heure
        'prefix' => 'controlHub_',
    ],
];


