<?php

namespace App\Policies;

use App\Models\AuthUser;
use App\Models\User;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour la gestion des utilisateurs.
 *
 * Story 7.2 (AC7, décisions a/b du 2026-04-23) : `resetPassword` ET `view`
 * sont désormais **scopées classe** pour le rôle `Prof`. Un Prof ne peut
 * réinitialiser le mot de passe / consulter le profil que des users
 * partageant **au moins un `UserGroup` de `type='class'`** avec lui.
 *
 * Ce comportement reproduit le check legacy `sovajon_is_admin` de
 * `sambaedu/annu/people.php:254-255` — plus restrictif et plus sûr que
 * `annu_can_read` (qui était global).
 *
 * Les rôles administratifs globaux (`UserAdmin`, `SuperAdmin`, `EleveAdmin`,
 * `ReferentNumerique`) bypassent le scoping classe (accès tous users).
 */
class UserPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    /**
     * Rôles dont les permissions user.* sont globales (pas de scoping classe).
     *
     * Correction review 7.2 #6 : `eleve-admin` retiré — désormais scopé classe
     * comme `prof` pour reproduire le comportement legacy `sovajon_is_admin`
     * (bits UserPasswordInit|UserRead|UserModify, scoping via `sovajon_is_admin`
     * strictement class-bound).
     */
    public const GLOBAL_USER_ROLES = [
        'super-admin',
        'user-admin',
        'referent-numerique',
    ];

    /**
     * Rôles soumis au scoping classe strict (aucun accès admin global).
     *
     * Correction review 7.2 #6 : `eleve-admin` ajouté — iso-legacy.
     */
    private const CLASS_SCOPED_ROLES = [
        'prof',
        'eleve-admin',
    ];

    /**
     * Définition des gates pour cette policy.
     */
    protected static array $gates = [
        'viewAny-user' => 'viewAny',
        'view-user' => 'view',
        'create-user' => 'create',
        'update-user' => 'update',
        'delete-user' => 'delete',
        'resetPassword-user' => 'resetPassword',
        'manage-users' => 'viewAny',
        'manage-groups' => 'manageGroups',
        'manage-rights' => 'manageRights',
    ];

    public function viewAny(?Authenticatable $user): bool
    {
        return $this->canReadUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut voir un user cible.
     *
     * Story 7.2 — Décision (b) : `Prof` scopé classe. Sans `$target`, on se
     * rabat sur le droit global (liste de résultats → le contrôleur/listing
     * filtre en amont si besoin).
     */
    public function view(?Authenticatable $actor, ?User $target = null): bool
    {
        if (!$this->canReadUsers($actor)) {
            return false;
        }

        // Correction review 7.2 #M1 : en prod, `auth()->user()` = `AuthUser` LDAP.
        // Normaliser vers l'Eloquent User pour que le scoping classe fonctionne.
        $actor = $this->resolveEloquentActor($actor);
        if ($actor === null) {
            return false;
        }

        // Sans target ou avec rôle global : accès autorisé.
        if ($target === null || $this->hasGlobalUserRole($actor)) {
            return true;
        }

        // Si l'acteur est scopé classe (Prof ou EleveAdmin) sans rôle admin
        // global, scoping strict par partage de `UserGroup.type='class'`.
        if ($this->isClassScopedOnly($actor)) {
            return $this->sharesClassWithTarget($actor, $target);
        }

        // Toute autre combinaison (rôle custom avec user.read) → accès global.
        return true;
    }

    public function create(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.modify');
    }

    public function update(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.modify');
    }

    public function delete(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'user.modify');
    }

    /**
     * Story 7.2 (AC7, décision a) — `Prof` scopé classe pour `user.password.init`.
     */
    public function resetPassword(?Authenticatable $actor, ?User $target = null): bool
    {
        if (!$this->hasPermission($actor, 'user.password.init')) {
            return false;
        }

        // Correction review 7.2 #M1 : en prod, `auth()->user()` = `AuthUser` LDAP.
        // Normaliser vers l'Eloquent User pour que le scoping classe fonctionne.
        $actor = $this->resolveEloquentActor($actor);
        if ($actor === null) {
            return false;
        }

        // Sans target (check générique pour masquer/afficher l'action) : droit global.
        if ($target === null) {
            return true;
        }

        // Rôle admin global : pas de scoping classe.
        if ($this->hasGlobalUserRole($actor)) {
            return true;
        }

        // Rôle scopé classe (Prof ou EleveAdmin seul) : scoping classe strict.
        if ($this->isClassScopedOnly($actor)) {
            return $this->sharesClassWithTarget($actor, $target);
        }

        // Autre cas (rôle custom avec user.password.init) : droit global.
        return true;
    }

    public function manageGroups(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    public function manageRights(?Authenticatable $user): bool
    {
        return $this->canAssignRights($user);
    }

    // ========================================================================
    // Helpers privés — scoping classe (décisions a/b Story 7.2)
    // ========================================================================

    /**
     * Normalise l'acteur en `App\Models\User` Eloquent.
     *
     * Correction review 7.2 #M1 : en production, `auth()->user()` retourne un
     * `AuthUser` (provider LDAP custom). Les checks Spatie sont délégués au
     * User Eloquent via `AuthUser::getEloquentUser()`. Sans cette résolution,
     * les helpers `isClassScopedOnly` / `sharesClassWithTarget` renvoient
     * systématiquement `false`, ce qui invalide le scoping classe en prod.
     */
    private function resolveEloquentActor(?Authenticatable $actor): ?User
    {
        if ($actor instanceof User) {
            return $actor;
        }
        if ($actor instanceof AuthUser) {
            return $actor->getEloquentUser();
        }
        return null;
    }

    /**
     * Indique si l'acteur porte au moins un rôle "admin global" parmi
     * `GLOBAL_USER_ROLES`. Ces rôles ne sont pas soumis au scoping classe.
     *
     * Correction review 7.2 #M2 : remplace le dead-code `instanceof Trait`
     * (toujours false en PHP) par un check `class_uses_recursive` propre.
     */
    private function hasGlobalUserRole(?Authenticatable $actor): bool
    {
        if ($actor === null) {
            return false;
        }
        $uses = class_uses_recursive($actor);
        if (!in_array(\Spatie\Permission\Traits\HasRoles::class, $uses, true)) {
            return false;
        }
        return $actor->hasAnyRole(self::GLOBAL_USER_ROLES);
    }

    /**
     * Indique si l'acteur est soumis au scoping classe strict.
     *
     * Porte un rôle `class-scoped` (`prof` ou `eleve-admin`) et aucun rôle
     * admin global. Si un Prof est aussi UserAdmin, il accède à tout via le
     * rôle admin (capturé dans `hasGlobalUserRole`).
     *
     * Correction review 7.2 #6 : renommée depuis `isProfOnly` pour couvrir
     * aussi `eleve-admin` (iso-legacy `sovajon_is_admin`).
     */
    private function isClassScopedOnly(?Authenticatable $actor): bool
    {
        if (!$actor instanceof User) {
            return false;
        }
        return $actor->hasAnyRole(self::CLASS_SCOPED_ROLES) && !$this->hasGlobalUserRole($actor);
    }

    /**
     * Décisions (a) et (b) : deux users partagent une classe si l'intersection
     * de leurs `userGroups()->where('type', 'class')` est non-vide.
     *
     * Le champ `type` de `user_groups` est confirmé par Henri 2026-04-22 :
     * `class` = classe scolaire (pivot `user_group_user`).
     */
    private function sharesClassWithTarget(?Authenticatable $actor, User $target): bool
    {
        if (!$actor instanceof \App\Models\User) {
            return false;
        }

        $actorClassIds = $actor->userGroups()->where('type', 'class')->pluck('user_groups.id');
        if ($actorClassIds->isEmpty()) {
            return false;
        }

        $targetClassIds = $target->userGroups()->where('type', 'class')->pluck('user_groups.id');
        if ($targetClassIds->isEmpty()) {
            return false;
        }

        return $actorClassIds->intersect($targetClassIds)->isNotEmpty();
    }
}
