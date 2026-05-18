<?php

declare(strict_types=1);

namespace App\Auth\V1\Services;

/**
 * Story 16.10 — AC4.2 / T5.1.
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
 */
class LegacyBootstrapTokenValidator
{
    /**
     * Vrai si le token correspond à une entrée APCu legacy `apps.<token>`
     * encore présente (non expirée).
     */
    public function isValid(string $token): bool
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

        return $success === true && $payload !== false;
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
