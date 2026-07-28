<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration OIDC — SE5 FOURNISSEUR d'identité pour les extensions
|--------------------------------------------------------------------------
|
| Story 55.1 — Epic 55 (SSO, phase 2 du système d'extensions).
|
| SE5 possédait déjà les deux moitiés du problème JWT :
|   - `config/auth_v1.php` : SE5 ÉMETTEUR de JWT RS256 pour les POSTES ;
|   - `app/Auth/Federated/`  : SE5 CONSOMMATEUR de JWT émis par un IdP amont.
| Ce fichier est le troisième pilier : SE5 émetteur d'`id_token` OIDC pour des
| NAVIGATEURS, consommé par des extensions enregistrées (clients confidentiels).
|
| ⚠️ **PAIRE DE CLÉS DÉDIÉE — ne JAMAIS réutiliser celle d'`auth_v1`.**
| La clé « workstation » signe des jetons `tier: workstation` et n'est PUBLIÉE
| NULLE PART ; le JWKS OIDC, lui, est un endpoint PUBLIC. Publier la clé
| workstation élargirait gratuitement sa surface d'exposition, et les rotations
| des deux mondes n'ont aucune raison d'être couplées.
|
| ⚠️ **NE JAMAIS signer un JWT avec `APP_KEY`** (secret symétrique du framework) :
| RS256 exige une paire asymétrique dédiée et isolée — même doctrine que
| `app/Auth/V1/README.md`.
|
| Les clés vivent sous `storage/keys/oidc/` (déjà couvert par `.gitignore`),
| clé privée chmod 0600, clé publique 0644. Elles sont générées par la commande
| artisan IDEMPOTENTE `php artisan oidc:keys:init` (doctrine ops du projet :
| toute opération multi-instance est une commande, jamais une procédure
| manuelle à rejouer).
|
| Toutes les valeurs sont surchargeables par `env()` pour permettre un
| déploiement Ansible / un tuning sans patcher le code.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Issuer — claim `iss` et `issuer` de la discovery
    |--------------------------------------------------------------------------
    |
    | URL publique de l'instance SE5. C'est la valeur que les clients OIDC
    | comparent au claim `iss` de l'id_token ET la racine qu'ils utilisent pour
    | découvrir `/.well-known/openid-configuration`. Fallback `APP_URL`.
    |
    | ⚠️ Un `issuer` qui ne correspond pas à l'URL réellement servie casse la
    | validation côté client (contrôle standard OIDC). Il doit être aligné sur
    | le vhost, sans slash final.
    |
    */

    'issuer' => rtrim((string) env('OIDC_ISSUER', (string) env('APP_URL', 'http://localhost')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Clés de signature RS256 (patron `auth_v1.jwt`)
    |--------------------------------------------------------------------------
    |
    | - `active_kid` : identifiant de la clé ACTIVE (daté — rotation manuelle,
    |   patron `AUTH_V1_JWT_KID`). Il est publié dans le header du JWT ET dans
    |   le JWKS : c'est ce qui permettra une rotation avec période de grâce
    |   (plusieurs entrées dans `keys`, plusieurs entrées au JWKS).
    | - `keys` : map `kid → ['private' => path, 'public' => path]`.
    | - `key_bits` : taille de la clé RSA générée par `oidc:keys:init`.
    |
    */

    'active_kid' => (string) env('OIDC_JWT_KID', '2026-07-28'),

    'keys' => [
        (string) env('OIDC_JWT_KID', '2026-07-28') => [
            'private' => env('OIDC_PRIVATE_KEY_PATH', storage_path('keys/oidc/private.pem')),
            'public' => env('OIDC_PUBLIC_KEY_PATH', storage_path('keys/oidc/public.pem')),
        ],
    ],

    'key_bits' => (int) env('OIDC_KEY_BITS', 2048),

    /*
    |--------------------------------------------------------------------------
    | Durées de vie (NFR1 — TTL courts, aucun en dur dans le code)
    |--------------------------------------------------------------------------
    |
    | - `code_ttl` (60 s)          : le code d'autorisation est échangé
    |   immédiatement par le client (serveur-à-serveur). 60 s couvre largement
    |   une redirection navigateur + un aller-retour HTTP.
    | - `id_token_ttl` (300 s)     : l'id_token est une PREUVE D'AUTHENTIFICATION
    |   consommée une fois par le client pour ouvrir SA session — pas un jeton
    |   d'accès à long terme.
    | - `access_token_ttl` (600 s) : jeton opaque, consommé par `/userinfo`
    |   (Story 55.2). Pas de refresh token : TTL courts + re-SSO transparent.
    | - `leeway` (60 s)            : tolérance d'horloge, alignée sur `auth_v1`.
    |
    */

    'code_ttl' => (int) env('OIDC_CODE_TTL', 60),
    'id_token_ttl' => (int) env('OIDC_ID_TOKEN_TTL', 300),
    'access_token_ttl' => (int) env('OIDC_ACCESS_TOKEN_TTL', 600),
    'leeway' => (int) env('OIDC_LEEWAY', 60),

    /*
    |--------------------------------------------------------------------------
    | Purge opportuniste des codes d'autorisation
    |--------------------------------------------------------------------------
    |
    | Les codes consommés/expirés n'ont aucune valeur passé ce délai. Ils sont
    | supprimés au fil de l'eau (à l'émission d'un nouveau code) plutôt que par
    | une tâche planifiée : volumétrie négligeable, aucune plomberie de cron à
    | déployer sur chaque instance.
    |
    */

    'code_purge_after' => (int) env('OIDC_CODE_PURGE_AFTER', 3600),

    /*
    |--------------------------------------------------------------------------
    | Propriétaire runtime des clés
    |--------------------------------------------------------------------------
    |
    | `oidc:keys:init` est lancée en root (`update.sh`) : sans chown, la clé
    | privée 0600 root:root serait ILLISIBLE par PHP-FPM. Sambaedu utilise un
    | pool `www-admin` (le défaut Debian `www-data` reste valide). Vide = no-op.
    | Iso `auth_v1.pki.web_owner`.
    |
    */

    'web_owner' => env('OIDC_KEY_WEB_OWNER', 'www-admin'),

    /*
    |--------------------------------------------------------------------------
    | Garde-fous runtime
    |--------------------------------------------------------------------------
    |
    | `forbid_test_keys_in_production` : les fixtures RS256 de test
    | (`tests/fixtures/auth-v1/*.pem`) sont COMMITÉES au dépôt — leur clé privée
    | est publique de fait. Si une instance non-`testing`/`local` pointait
    | dessus, N'IMPORTE QUI pourrait forger un id_token valide pour n'importe
    | quel utilisateur. Le garde-fou est appliqué symétriquement à la lecture de
    | la clé privée (signature) ET de la clé publique (JWKS) — calque
    | `WorkstationJwtIssuer::loadPrivateKey()` / `WorkstationJwtVerifier::buildKeyMap()`.
    |
    */

    'safety' => [
        'forbid_test_keys_in_production' => (bool) env('OIDC_FORBID_TEST_KEYS_IN_PROD', true),
    ],
];
