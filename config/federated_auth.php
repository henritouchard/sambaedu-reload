<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration Auth fédérée — IdP externe de confiance (Epic 20)
|--------------------------------------------------------------------------
|
| Story 20.1 — « Login fédéré : validation du JWT & ouverture de session
| externe ».
|
| Calquée sur `config/auth_v1.php` (Story 16.10, tier « workstation »). Cette
| config décrit un NOUVEAU tier d'identité : « utilisateur fédéré » — un
| acteur humain (technicien flotte) authentifié par un IdP externe de
| confiance qui forge un JWT signé RS256.
|
| Principe directeur — DOMAIN-NEUTRAL : aucun littéral « controlHub » /
| « central » dans le code ni dans cette config. L'émetteur est identifié
| par `expected_iss` ; controlHub n'est QU'UNE instance d'IdP externe.
|
| Sécurité (durcissement IR H1/M1/M4 — section archi « Sécurité du jeton
| JWT ») :
|   - `RS256` pinné (asymétrique : l'IdP signe avec sa privée, SE5 vérifie
|     avec la publique). La liste d'algos est passée EXPLICITEMENT à la lib
|     firebase/php-jwt — on ne déduit JAMAIS l'algo du header (faille de
|     confusion d'algorithme).
|   - `aud` lie le jeton à CETTE instance (`expected_aud`) → un JWT forgé
|     pour le collège A ne passe pas sur le collège B (anti-rejeu inter-
|     instance sur la flotte).
|   - `jti` à usage unique (cache + DB), `leeway` ±60 s.
|
| ⚠️ La clé publique PEM de l'émetteur doit être lisible par le user
| PHP-FPM (`www-admin`, uid 599 — pool custom Sambaedu).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | JWT — Vérification de signature et claims (H1)
    |--------------------------------------------------------------------------
    |
    | - `algorithm` : RS256 imposé (lib `firebase/php-jwt`). Aucun algo
    |   symétrique ni `none` n'est accepté — le verifier ne construit QUE des
    |   `Key(..., 'RS256')` et ne passe que cet algo à la lib.
    | - `keys` : map `kid → ['public' => path]`. Le verifier lookup le `kid`
    |   du header JWT pour trouver la clé publique correspondante. Un `kid`
    |   inconnu = rejet `jwt.signature_invalid`. Support multi-kid pour la
    |   rotation sans coupure (M1 : ancienne + nouvelle clé valides pendant
    |   le recouvrement).
    | - `leeway` : tolérance d'horloge ±60 s sur `exp`/`nbf`/`iat` (M4 — le
    |   TTL court rend le clock-skew sensible).
    |
    | ⚠️ Pas de clé PRIVÉE ici : SE5 ne signe jamais un JWT fédéré, il ne
    |    fait que vérifier. La signature est l'affaire de l'IdP externe.
    |
    */

    'jwt' => [
        'algorithm' => 'RS256',

        // kid actif (informatif côté SE5 — le verifier accepte tout kid
        // présent dans `keys`). Côté IdP, ce kid figure dans le header JWT.
        'active_kid' => env('FEDERATED_AUTH_JWT_KID', ''),

        // Map kid → chemin de la clé PUBLIQUE PEM de l'émetteur.
        // Aujourd'hui une seule entrée ; la rotation par kid (M1) en ajoute
        // une seconde le temps du recouvrement.
        'keys' => array_filter([
            env('FEDERATED_AUTH_JWT_KID', '') => array_filter([
                'public' => env('FEDERATED_AUTH_JWT_PUBLIC_KEY_PATH', ''),
            ]),
        ], static fn ($paths, $kid): bool => $kid !== '' && ($paths['public'] ?? '') !== '', ARRAY_FILTER_USE_BOTH),

        // Tolérance d'horloge en secondes (Firebase\JWT\JWT::$leeway). ±60s.
        'leeway' => (int) env('FEDERATED_AUTH_JWT_LEEWAY', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Émetteur de confiance + liaison à l'instance (H1)
    |--------------------------------------------------------------------------
    |
    | - `expected_iss` : claim `iss` attendu (= identité de l'IdP externe
    |   configuré). Domain-neutral : c'est une string opaque, pas « central ».
    | - `expected_aud` : claim `aud` attendu = identifiant de CETTE instance
    |   SE5. Un JWT dont `aud` ≠ cette valeur est rejeté (anti-rejeu inter-
    |   instance). Fallback dynamique sur `sambaedu.se4fs_name` si vide.
    | - `expected_tier` : claim `tier` attendu (défense en profondeur : un
    |   JWT « workstation » ne doit pas pouvoir ouvrir une session humaine).
    |
    */

    'expected_iss' => env('FEDERATED_AUTH_EXPECTED_ISS', ''),
    'expected_aud' => env('FEDERATED_AUTH_EXPECTED_AUD', ''),
    'expected_tier' => env('FEDERATED_AUTH_EXPECTED_TIER', 'federated-user'),

    /*
    |--------------------------------------------------------------------------
    | Mapping de rôle (D-7)
    |--------------------------------------------------------------------------
    |
    | Table `rôle-externe (claim `role`) → SambaRole::value` (rôle Spatie
    | local). Le JWT transporte un NOM DE RÔLE (l'intention), jamais une
    | liste de permissions (le mécanisme). SE5 mappe puis réutilise les
    | Policies/Gates Spatie existants.
    |
    | ⚠️ GARDE-FOU : un rôle externe ABSENT de cette table → refus explicite
    |    403, aucune session (jamais de fallback vers un rôle privilégié).
    |
    | Défaut (D-7) : `technicien → SambaRole::Technicien`. L'outillage
    | d'admin/config riche = Story 20.3.
    |
    */

    'role_map' => [
        'technicien' => \App\Enums\SambaRole::Technicien->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Anti-rejeu jti — Double couche cache + DB (D-6)
    |--------------------------------------------------------------------------
    |
    | Calqué sur `auth_v1.revocation`. Un `jti` déjà consommé est refusé :
    | un jeton d'entrée fédéré n'est consommable QU'UNE FOIS. Le TTL du cache
    | couvre la durée de vie du jeton (TTL court côté IdP).
    |
    | - `cache_store` : `apc` (APCu) en prod, `array` en tests (isolation).
    | - `cache_ttl`   : durée de mémorisation d'un jti consommé (= TTL max du
    |   jeton fédéré + marge ; 900s = 15 min par défaut, cf. archi ③).
    | - `cache_prefix`: préfixe distinct de `auth_v1` pour éviter toute
    |   collision avec le tier workstation.
    |
    */

    'replay' => [
        'cache_store' => env('FEDERATED_AUTH_REPLAY_CACHE_STORE', 'apc'),
        'cache_ttl' => (int) env('FEDERATED_AUTH_REPLAY_CACHE_TTL', 900),
        'cache_prefix' => env('FEDERATED_AUTH_REPLAY_CACHE_PREFIX', 'federated:jti:'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Garde-fous runtime
    |--------------------------------------------------------------------------
    |
    | `forbid_test_keys_in_production` : si `true` (recommandé), le verifier
    | refuse une clé pointant sur `tests/fixtures/*` hors env testing/local.
    | Garde-fou symétrique à `auth_v1.safety`.
    |
    */

    'safety' => [
        'forbid_test_keys_in_production' => (bool) env('FEDERATED_AUTH_FORBID_TEST_KEYS_IN_PROD', true),
    ],
];
