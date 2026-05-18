<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration Auth v1 — Plateforme HTTPS + JWT poste ↔ serveur local
|--------------------------------------------------------------------------
|
| Story 16.10 — Epic 16 Phase 2.
|
| Centralise la configuration de la plateforme d'authentification v1 :
|   - paramètres JWT (algorithme, TTL, clés actives via `kid`)
|   - chemins PKI locale (CA root, cert serveur, paire RS256)
|   - configuration du cache de révocation (driver, TTL)
|   - URL serveur (utilisée par les controllers pour `server_base_url`)
|
| Toutes les valeurs sont overridables via env() pour permettre un
| déploiement Ansible / tuning sans patcher le code. Décision D3 (story
| 16.10) : pas de Vault Phase 2, les clés vivent sous `storage/keys/`
| chmod 0600 (cf. `.gitignore`).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | JWT — Configuration de signature et vérification (D2 + D9)
    |--------------------------------------------------------------------------
    |
    | - `algorithm` : RS256 imposé (asymétrique, lib `firebase/php-jwt:^6.10`).
    | - `access_ttl`  : TTL access token (10h — révision review 16.10, couvre
    |                   journée scolaire avec marge ; ancien 24h fenêtre trop
    |                   large vs limitation `workstation:revoke` cache 60s).
    | - `refresh_ttl` : TTL refresh token (30j).
    | - `active_kid` : identifiant de la clé active utilisée par
    |                  `WorkstationJwtIssuer` lors de la signature.
    | - `keys`        : map `kid → ['private' => path, 'public' => path]`.
    |                   Le verifier lookup le `kid` du header JWT pour trouver
    |                   la public key correspondante. Un `kid` inconnu = rejet
    |                   `jwt.signature_invalid` (D9 — design extensible).
    | - `issuer`     : claim `iss` du JWT. Fallback `sambaedu.se4fs_name`.
    |
    | ⚠️ NE JAMAIS réutiliser `APP_KEY` pour signer un JWT — RS256 nécessite
    |    une paire de clés dédiée et isolée du `APP_KEY` symétrique.
    |
    */

    'jwt' => [
        'algorithm' => 'RS256',

        // Access TTL : 10h (révision review 16.10 — Henri 2026-05-16).
        // Couvre une journée scolaire (8h + marge) avec 1 refresh entre 2 sessions.
        // Réduit la fenêtre d'exposition après compromission de 24h → 10h.
        'access_ttl' => (int) env('AUTH_V1_JWT_ACCESS_TTL', 36000),

        // Refresh TTL : 30j (cf. Tech Spec §5.2).
        'refresh_ttl' => (int) env('AUTH_V1_JWT_REFRESH_TTL', 2592000),

        // kid actif — date d'émission de la paire (D9 — rotation manuelle
        // Phase 2 + extensible Phase 3+).
        'active_kid' => env('AUTH_V1_JWT_KID', '2026-05-16'),

        // Map kid → chemins. Aujourd'hui une seule entrée ; Phase 3+ pourra en
        // ajouter pour rotation avec période de grâce (D9).
        'keys' => [
            env('AUTH_V1_JWT_KID', '2026-05-16') => [
                'private' => env('AUTH_V1_JWT_PRIVATE_KEY_PATH', storage_path('keys/jwt/private.pem')),
                'public' => env('AUTH_V1_JWT_PUBLIC_KEY_PATH', storage_path('keys/jwt/public.pem')),
            ],
        ],

        // Issuer (claim iss). Fallback dynamique sur sambaedu.se4fs_name si vide.
        'issuer' => env('AUTH_V1_JWT_ISSUER', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | PKI locale — CA root + cert serveur HTTPS (D1 + D9)
    |--------------------------------------------------------------------------
    |
    | Chaque serveur local Sambaedu génère son propre CA root (D9 — Tech Spec).
    | Validités longues (5 ans CA, 1 an cert serveur) pour limiter le churn
    | en environnement scolaire.
    |
    | Le `subject_organization` est utilisé dans le `O=` du Distinguished
    | Name du CA root et du cert serveur. `subject_country` et `subject_locality`
    | sont optionnels mais documentés pour homogénéiser le rendu côté postes
    | qui inspectent le cert (`openssl x509 -text`).
    |
    */

    'pki' => [
        'ca_root_key' => env('AUTH_V1_PKI_CA_KEY', storage_path('keys/pki/ca-root.key')),
        'ca_root_crt' => env('AUTH_V1_PKI_CA_CRT', storage_path('keys/pki/ca-root.crt')),
        'server_key' => env('AUTH_V1_PKI_SERVER_KEY', storage_path('keys/pki/server.key')),
        'server_crt' => env('AUTH_V1_PKI_SERVER_CRT', storage_path('keys/pki/server.crt')),

        // Validités (jours).
        'ca_validity_days' => (int) env('AUTH_V1_PKI_CA_DAYS', 1825), // 5 ans
        'server_validity_days' => (int) env('AUTH_V1_PKI_SERVER_DAYS', 365), // 1 an

        // Bits de clé (CA = 4096 robustesse longue durée, server = 2048 perf).
        'ca_key_bits' => (int) env('AUTH_V1_PKI_CA_BITS', 4096),
        'server_key_bits' => (int) env('AUTH_V1_PKI_SERVER_BITS', 2048),
        'jwt_key_bits' => (int) env('AUTH_V1_JWT_KEY_BITS', 2048),

        // Subject metadata.
        'subject_organization' => env('AUTH_V1_PKI_ORG', 'SambaEdu'),
        'subject_country' => env('AUTH_V1_PKI_COUNTRY', 'FR'),
        'subject_locality' => env('AUTH_V1_PKI_LOCALITY', ''),

        // Propriétaire web auquel les fichiers PKI lisibles par le serveur HTTP
        // doivent appartenir (server.crt, server.key, ca-root.crt, jwt private/public).
        // Sambaedu utilise un pool PHP-FPM custom `www-admin`. Le défaut Debian
        // `www-data` reste valide pour les installs standard.
        // Set to empty string pour désactiver le chown (env testing / Mac dev).
        'web_owner' => env('AUTH_V1_PKI_WEB_OWNER', 'www-admin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Révocation — Double couche cache + DB (D4)
    |--------------------------------------------------------------------------
    |
    | Le `WorkstationJwtRevocationChecker` interroge d'abord le cache APCu
    | (TTL court 60s — alignement Tech Spec §7), puis fallback DB
    | `workstation_jwt_revocations` si miss. Pas de Redis Phase 2.
    |
    | La commande `php artisan workstation:revoke <uuid>` push un flag cache
    | (`jwt:revoked:<jti>`, TTL 3600s) pour invalidation rapide multi-workers,
    | en plus de l'écriture DB.
    |
    */

    'revocation' => [
        'cache_ttl' => (int) env('AUTH_V1_REVOCATION_CACHE_TTL', 60),

        // Driver Laravel : `apc` en prod (APCu, parité legacy), `array` en
        // tests (isolation entre tests, cf. phpunit.xml CACHE_DRIVER=array).
        'cache_store' => env('AUTH_V1_REVOCATION_CACHE_STORE', 'apc'),

        // Prefix des clés cache (évite collisions avec d'autres consommateurs
        // APCu legacy `apps.<md5>` Story 16.7).
        'cache_prefix' => env('AUTH_V1_REVOCATION_CACHE_PREFIX', 'jwt:revoked:'),

        // TTL du flag cache poussé par la commande `workstation:revoke`.
        'manual_revoke_cache_ttl' => (int) env('AUTH_V1_REVOCATION_MANUAL_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bootstrap token legacy — Validation du `X-Bootstrap-Token` md5/APCu
    |--------------------------------------------------------------------------
    |
    | L'endpoint `/api/v1/agent/enroll` accepte temporairement le token md5
    | APCu legacy (clé `apps.<md5>` posée par `gpo/applications.php` legacy)
    | comme preuve d'enrôlement transitoire — cf. `LegacyBootstrapTokenValidator`.
    |
    | TTL APCu legacy = 1800s (cf. `ApcuAppContextWriter`). Le middleware
    | NE consomme PAS le token (pas `apcu_delete`) — race condition retry
    | réseau. Le TTL APCu gère naturellement la fenêtre.
    |
    | `cache_prefix` : préfixe attendu sur les clés APCu legacy (parité
    | `ApcuAppContextWriter::write` → `apcu_store('apps.' . $id, ...)`).
    |
    */

    'bootstrap_token' => [
        // Préfixe APCu legacy (cf. ApcuAppContextWriter::write — Story 16.7).
        'apcu_prefix' => env('AUTH_V1_BOOTSTRAP_APCU_PREFIX', 'apps.'),

        // Pattern de validation du token (md5 hex 32 chars, parité legacy).
        'token_regex' => '/^[a-f0-9]{32}$/i',
    ],

    /*
    |--------------------------------------------------------------------------
    | Server base URL — Renvoyé dans la réponse enroll
    |--------------------------------------------------------------------------
    |
    | Le poste qui s'enrôle reçoit `server_base_url` (`https://<host>`) pour
    | savoir où adresser les requêtes futures `/api/v1/*`. Fallback dynamique
    | sur `sambaedu.se4fs_name` + `app.url` scheme HTTPS si vide.
    |
    */

    'server' => [
        'base_url' => env('AUTH_V1_SERVER_BASE_URL', ''),
        'host_suffix' => env('AUTH_V1_SERVER_HOST_SUFFIX', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Garde-fous runtime
    |--------------------------------------------------------------------------
    |
    | `forbid_test_keys_in_production` : si `true` (recommandé), le
    | `WorkstationJwtIssuer` refuse de signer si `APP_ENV` non-`testing` et
    | que la clé pointe sur `tests/fixtures/auth-v1/*`. Garde-fou explicite.
    |
    */

    'safety' => [
        'forbid_test_keys_in_production' => (bool) env('AUTH_V1_FORBID_TEST_KEYS_IN_PROD', true),
    ],
];
