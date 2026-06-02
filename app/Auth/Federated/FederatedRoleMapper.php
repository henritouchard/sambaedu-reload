<?php

declare(strict_types=1);

namespace App\Auth\Federated;

use App\Enums\SambaRole;

/**
 * Story 20.1 — D-7 / T6.
 *
 * Résout un nom de rôle EXTERNE (claim `role` du JWT, l'intention) vers un
 * `SambaRole` local (le mécanisme Spatie). La table `role_map` vit dans
 * `config/federated_auth.php`.
 *
 * ⚠️ GARDE-FOU : un rôle externe absent de la table → `null`. Le caller
 * (controller) DOIT alors répondre 403 sans ouvrir de session — JAMAIS de
 * fallback vers un rôle privilégié.
 *
 * L'outillage d'admin/UI du mapping (richesse, édition) = Story 20.3.
 */
class FederatedRoleMapper
{
    /**
     * @return SambaRole|null Le rôle local mappé, ou `null` si le rôle externe
     *                        est inconnu de la config.
     */
    public function resolve(string $externalRole): ?SambaRole
    {
        if ($externalRole === '') {
            return null;
        }

        $map = config('federated_auth.role_map', []);
        if (! is_array($map) || ! array_key_exists($externalRole, $map)) {
            return null;
        }

        $localRoleValue = $map[$externalRole];
        if (! is_string($localRoleValue) || $localRoleValue === '') {
            return null;
        }

        return SambaRole::tryFrom($localRoleValue);
    }
}
