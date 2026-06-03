<?php

declare(strict_types=1);

namespace App\Auth\Federated;

use Spatie\Permission\Models\Role;

/**
 * Story 20.1 — D-7 / T6 ; RECONÇU par Story 20.3 (pivot Henri 2026-06-03).
 *
 * Résout le nom de rôle ASSÉRÉ par l'IdP externe (claim `role` du JWT,
 * l'intention) vers un rôle EXISTANT de l'instance.
 *
 * ⚠️ PIVOT 20.3 — D-1 : il n'y a PLUS de table de correspondance
 * (`config/federated_auth.role_map` supprimée). Le nom de rôle asséré EST déjà
 * le contrat : SE5 le cherche DIRECTEMENT parmi les rôles Spatie EXISTANTS de
 * l'instance (table `roles`, guard `web`), après normalisation casse/espaces
 * (`trim` + `strtolower`). Aucune couche de traduction locale.
 *
 * CHOIX D'IMPLÉMENTATION (D-5) — lookup sur la table `roles`, PAS sur l'enum
 * `SambaRole` : le modèle est ouvert. Tout rôle existant dans l'instance est
 * demandable, qu'il soit seedé (`SambaRole`) OU créé hors enum (par l'émetteur
 * externe de confiance, cible à terme). Borner au seul enum exclurait ces
 * rôles custom — interdit par D-5. La table `roles` est la source de vérité.
 *
 * GARDE-FOUS conservés (invariant 20.1) :
 *   - Rôle asséré vide/blanc → `null`.
 *   - Rôle asséré sans correspondance en base → `null`.
 *   - AUCUN wildcard / fallback `default` : seule une existence en base résout.
 * Le caller (controller) répond 403 sans ouvrir de session sur `null`, et
 * n'applique (`syncRoles`) qu'un rôle EXISTANT — il n'en crée JAMAIS.
 */
class FederatedRoleMapper
{
    /**
     * @return string|null Le NOM CANONIQUE du rôle existant (tel qu'il est en
     *                     base), ou `null` si le rôle asséré ne correspond à
     *                     aucun rôle existant (→ 403 côté caller).
     */
    public function resolve(string $externalRole): ?string
    {
        $normalized = $this->normalize($externalRole);
        if ($normalized === '') {
            return null;
        }

        // Lookup DIRECT insensible à la casse dans les rôles existants
        // (guard `web`). `LOWER(name)` côté SQL pour rester portable
        // (SQLite/PostgreSQL) ; la normalisation des espaces de bord est faite
        // côté PHP (`$normalized` est déjà trimé + minuscules).
        return Role::query()
            ->where('guard_name', 'web')
            ->whereRaw('LOWER(name) = ?', [$normalized])
            ->value('name');
    }

    /**
     * Normalisation D-3 : trim des espaces de bord + minuscules. Pas de
     * sémantique de fallback — c'est une simple égalité tolérante.
     */
    private function normalize(string $role): string
    {
        return strtolower(trim($role));
    }
}
