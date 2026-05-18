<?php

declare(strict_types=1);

namespace App\Auth\V1\Services;

use Illuminate\Support\Facades\Log;

/**
 * Story 16.10 — AC4.2 / T5.1.
 * Story 16.11 — AC1.1 (durcissement couple token↔UUID, rétrocompat).
 *
 * Valide le `X-Bootstrap-Token` md5 transitoire fourni par les postes lors
 * de leur première bascule v1 (Phase 2).
 *
 * **Source du token** :
 *
 *  - Le token est un md5 (32 chars hex) **posé par le serveur legacy
 *    `gpo/applications.php`** au moment où le poste appelle un endpoint
 *    runtime legacy (cf. `app/Services/AppCustomization/ApcuAppContextWriter.php`
 *    Story 16.7). Le serveur fait `apcu_store('apps.' . $id, $context, 1800)`
 *    où `$id` est le md5 d'un payload signé `md5("$action $hostname $login $time")`.
 *
 *  - Le poste capte ce md5 (dans la réponse du serveur ou via une logique
 *    de matching côté client) et le présente comme `X-Bootstrap-Token` à
 *    `POST /api/v1/agent/enroll`. Le serveur valide en faisant
 *    `apcu_fetch('apps.' . $token)` — si succès = token valide (= un serveur
 *    legacy a bien posé ce contexte récemment, dans les 1800s).
 *
 * **TTL APCu legacy = 1800s** (cf. ApcuAppContextWriter). Le validator
 * **NE consomme PAS le token** (pas `apcu_delete`) :
 *
 *  - Évite race condition sur retry réseau (poste qui ré-appelle enroll
 *    parce que le premier call a timeout côté client).
 *  - Le TTL APCu de 1800s gère naturellement la fenêtre — si le poste ne
 *    s'enrôle pas dans les 30 min, il devra ré-appeler le legacy
 *    `gpo/applications.php` pour obtenir un nouveau token.
 *
 * **Dégradation gracieuse** : si APCu indisponible (CLI sans extension),
 * `isValid()` retourne `false` (= bootstrap_token.invalid). Le validator ne
 * crash pas — c'est au caller de comprendre l'absence APCu côté infra.
 *
 * **Story 16.11 — couple token↔UUID (mitigation fixation UUID)** :
 *
 *  - La signature `isValid()` est étendue avec un 2e argument optionnel
 *    `?string $declaredUuid = null` — **rétrocompatible**, les appelants
 *    sans 2e arg gardent le comportement 16.10.
 *  - Si `$declaredUuid` fourni, le validator vérifie que le contexte APCu
 *    `apps.<token>` contient une clé `uuid` strictement égale à
 *    `$declaredUuid` (`===`). Sinon → retour `false` + log warning
 *    `auth.bootstrap.uuid_mismatch`.
 *  - Cas marginal : si le payload APCu n'a pas la clé `uuid` (ne devrait
 *    pas arriver — `ApplicationsScriptsController:135` pose toujours `uuid`),
 *    le validator retourne `false` **fail-closed** + log warning
 *    `auth.bootstrap.context_missing_uuid` (cas inattendu).
 *  - Méthode dédiée `checkMismatch()` qui retourne `true` uniquement quand
 *    on a effectivement détecté un mismatch (APCu présent + uuids
 *    différents) — utile au middleware pour distinguer 401 `invalid` (token
 *    non trouvé) vs 401 `uuid_mismatch` (token OK mais uuid divergent).
 */
class LegacyBootstrapTokenValidator
{
    /**
     * Vrai si le token correspond à une entrée APCu legacy `apps.<token>`
     * encore présente (non expirée).
     *
     * Si `$declaredUuid` est fourni, le validator vérifie en plus que la
     * clé `uuid` du contexte APCu vaut `$declaredUuid` (`===`, strict).
     *
     * @param string      $token        Bootstrap token md5 fourni par le poste.
     * @param string|null $declaredUuid UUID v4 du poste (déclaré dans le body
     *                                  de la requête enroll). Si non `null`,
     *                                  active le check token↔UUID 16.11.
     */
    public function isValid(string $token, ?string $declaredUuid = null): bool
    {
        // Validation format (md5 hex 32 chars) — défense en profondeur.
        $regex = (string) config('auth_v1.bootstrap_token.token_regex', '/^[a-f0-9]{32}$/i');
        if ($token === '' || ! preg_match($regex, $token)) {
            return false;
        }

        if (! $this->apcuAvailable()) {
            return false;
        }

        $prefix = (string) config('auth_v1.bootstrap_token.apcu_prefix', 'apps.');
        $cacheKey = $prefix . $token;

        $success = false;
        // @phpstan-ignore-next-line — apcu_fetch est dispo si apcuAvailable() = true
        $payload = apcu_fetch($cacheKey, $success);

        if ($success !== true || $payload === false) {
            return false;
        }

        // 16.10 rétrocompat — sans uuid déclaré, présence APCu suffit.
        if ($declaredUuid === null) {
            return true;
        }

        // 16.11 — vérifier le couple token↔UUID.
        return $this->payloadMatchesUuid($payload, $declaredUuid, $token);
    }

    /**
     * Story 16.11 — détecte un mismatch uuid explicite (APCu présent +
     * uuid différent OU payload sans uuid). Retourne `true` UNIQUEMENT
     * dans ces cas — permet au middleware de discriminer le code d'erreur
     * `uuid_mismatch` vs `invalid`.
     *
     * Pré-condition usage : le caller a déjà confirmé que `isValid($token)`
     * (sans uuid) retourne `true` — sinon `checkMismatch` retourne `false`
     * (token déjà invalid, on n'a pas besoin de discriminer).
     */
    public function checkMismatch(string $token, string $declaredUuid): bool
    {
        // Token doit déjà être valide format + APCu (sinon pas de mismatch
        // à détecter — le caller doit appeler isValid($token) en amont).
        if (! $this->isValid($token)) {
            return false;
        }

        $prefix = (string) config('auth_v1.bootstrap_token.apcu_prefix', 'apps.');
        $cacheKey = $prefix . $token;

        $success = false;
        // @phpstan-ignore-next-line — apcu_fetch dispo (isValid a check)
        $payload = apcu_fetch($cacheKey, $success);

        if ($success !== true || $payload === false) {
            // Race : token expiré entre les 2 appels — pas de mismatch
            // confirmable, on laisse le caller traiter comme invalid.
            return false;
        }

        return ! $this->payloadMatchesUuid($payload, $declaredUuid, $token);
    }

    /**
     * Vrai si le payload APCu contient une clé `uuid` strictement égale à
     * `$declaredUuid`. Log warning si absence/mismatch.
     *
     * @param mixed $payload Payload brut renvoyé par apcu_fetch.
     */
    private function payloadMatchesUuid(mixed $payload, string $declaredUuid, string $token): bool
    {
        $tokenHashPrefix = substr(hash('sha256', $token), 0, 8);

        if (! is_array($payload)) {
            Log::channel('auth-v1')->warning(
                '[LegacyBootstrapTokenValidator] auth.bootstrap.context_invalid_payload',
                [
                    'action_type' => 'auth.bootstrap.context_invalid_payload',
                    'token_hash_prefix' => $tokenHashPrefix,
                ],
            );

            return false;
        }

        if (! array_key_exists('uuid', $payload)) {
            Log::channel('auth-v1')->warning(
                '[LegacyBootstrapTokenValidator] auth.bootstrap.context_missing_uuid',
                [
                    'action_type' => 'auth.bootstrap.context_missing_uuid',
                    'token_hash_prefix' => $tokenHashPrefix,
                ],
            );

            return false;
        }

        $contextUuid = strtolower((string) $payload['uuid']);
        $declaredUuid = strtolower($declaredUuid);

        if ($contextUuid === '' || $contextUuid !== $declaredUuid) {
            Log::channel('auth-v1')->warning(
                '[LegacyBootstrapTokenValidator] auth.bootstrap.uuid_mismatch',
                [
                    'action_type' => 'auth.bootstrap.uuid_mismatch',
                    'token_hash_prefix' => $tokenHashPrefix,
                    'declared_uuid_prefix' => substr(hash('sha256', $declaredUuid), 0, 8),
                    'context_uuid_prefix' => $contextUuid === ''
                        ? '(empty)'
                        : substr(hash('sha256', $contextUuid), 0, 8),
                ],
            );

            return false;
        }

        return true;
    }

    /**
     * Vrai si l'extension APCu est chargée et activée.
     */
    private function apcuAvailable(): bool
    {
        return function_exists('apcu_fetch')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }
}
