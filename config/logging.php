<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily'],
            'ignore_exceptions' => false,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
            'tap' => [\App\Logging\ApplyLimitedTraceFormatter::class],
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'replace_placeholders' => true,
            'tap' => [\App\Logging\ApplyLimitedTraceFormatter::class],
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => env('LOG_LEVEL', 'critical'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://'.env('PAPERTRAIL_URL').':'.env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => LOG_USER,
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'legacylog' => [
            'driver' => 'daily',
            'path' => storage_path('logs/legacy-catchall.log'),
            'level' => 'info',
            'days' => 30,
            'replace_placeholders' => true,
        ],

        // Channel dédié pipeline déploiement WPKG (Story 15.1).
        // Niveau ajustable sans redeploy via WPKG_DEPLOY_LOG_LEVEL.
        'wpkg-deploy' => [
            'driver' => 'daily',
            'path' => storage_path('logs/wpkg-deploy/deploy.log'),
            'level' => env('WPKG_DEPLOY_LOG_LEVEL', 'info'),
            'days' => (int) env('WPKG_DEPLOY_LOG_DAYS', 30),
            'replace_placeholders' => true,
        ],

        // Story 8.1 — Channel dédié réseau (DHCP + DNS futur). Aligné sur
        // `wpkg-deploy` (driver daily, rotation 7j par défaut). Niveau
        // ajustable sans redeploy via NETWORK_LOG_LEVEL.
        'network' => [
            'driver' => 'daily',
            'path' => storage_path('logs/network/network.log'),
            'level' => env('NETWORK_LOG_LEVEL', 'debug'),
            'days' => (int) env('NETWORK_LOG_DAYS', 7),
            'replace_placeholders' => true,
        ],

        // Channel dédié module GPO (Story 16.1 — Epic 16).
        // Couvre toutes les actions GPO (lecture, écriture, sync, audit, déploiement)
        // — channel « large » : décision Henri 2026-05-11, pas `gpo-deploy` trop étroit.
        // Verbosité élevée volontaire (`debug` par défaut) en phase de transition
        // Epic 16. Sera bumpée à `info` une fois l'epic stabilisé.
        // Convention de logging par `action_type` documentée dans app/Gpo/README.md.
        'gpo' => [
            'driver' => 'daily',
            'path' => storage_path('logs/gpo/gpo.log'),
            'level' => env('GPO_LOG_LEVEL', 'debug'),
            'days' => (int) env('GPO_LOG_DAYS', 30),
            'replace_placeholders' => true,
        ],

        // Channel dédié plateforme auth v1 — JWT + PKI + middlewares
        // (Story 16.10 — Epic 16 Phase 2). Couvre toutes les actions
        // d'authentification poste↔serveur local : émission/refresh/révocation
        // JWT, init CA, bootstrap, replay detection. Verbosité élevée volontaire
        // (`debug` par défaut) pour faciliter le diagnostic des bascules de
        // postes en Phase 2 ; bumper `info` une fois la migration terminée.
        // Convention de logging par `action_type` documentée dans
        // app/Auth/V1/README.md (catalogue auth.ca.*, auth.bootstrap.*,
        // auth.enroll.*, auth.token.*).
        // ⚠️ AUCUN secret ne doit jamais transiter par ce channel : clé privée,
        // refresh_token clear, JWT signé complet sont INTERDITS. Seuls les
        // identifiants opaques (workstation_uuid, jti, kid, exp, mac, hostname)
        // sont loggables.
        'auth-v1' => [
            'driver' => 'daily',
            'path' => storage_path('logs/auth-v1/auth-v1.log'),
            'level' => env('AUTH_V1_LOG_LEVEL', 'debug'),
            'days' => (int) env('AUTH_V1_LOG_DAYS', 30),
            // Channel sécurité-critique : pas de remplacement de placeholders depuis
            // le context (évite tout risque d'injection si une string contrôlable
            // contenait des `{placeholder}`).
            'replace_placeholders' => false,
        ],

        // Story 16.12 — Channel dédié logs d'exécution scripts centralisés.
        // Couvre : ingestion endpoint, idempotence, archivage job daily,
        // rendu du wrapper. Pas de secret loggé (jamais d'access_token,
        // jamais de stdout/stderr complets — uniquement des counts/metadata).
        // Convention de events documentée dans story 16.12 D8 :
        //  - scriptsos.ingest.success
        //  - scriptsos.ingest.idempotent_skip
        //  - scriptsos.ingest.idempotent_skip_race
        //  - scriptsos.ingest.validation_failed
        //  - scriptsos.wrapper.rendered
        //  - scriptsos.archive.rotated
        //  - scriptsos.archive.failed
        'scriptsos' => [
            'driver' => 'daily',
            'path' => storage_path('logs/scriptsos/scriptsos.log'),
            'level' => env('SCRIPTSOS_LOG_LEVEL', 'debug'),
            'days' => (int) env('SCRIPTSOS_LOG_RETENTION', 30),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],
    ],

];
