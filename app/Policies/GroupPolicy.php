<?php

namespace App\Policies;

use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour la gestion des groupes
 *
 * Contrôle l'accès via les permissions SQL (Spatie).
 */
class GroupPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    /**
     * Définition des gates pour cette policy
     */
    protected static array $gates = [
        'viewAny-group' => 'viewAny',
        'view-group' => 'view',
        'create-group' => 'create',
        'update-group' => 'update',
        'delete-group' => 'delete',
        'manage-groups' => 'viewAny',
        'addMember-group' => 'addMember',
        'removeMember-group' => 'removeMember',
        'customize-userGroup' => 'customize',
    ];

    /**
     * Vérifie si l'utilisateur peut voir la liste des groupes
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->canReadUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut voir un groupe
     */
    public function view(?Authenticatable $user): bool
    {
        return $this->canReadUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut créer un groupe
     */
    public function create(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut modifier un groupe
     */
    public function update(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut supprimer un groupe
     */
    public function delete(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut ajouter un membre à un groupe
     */
    public function addMember(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Vérifie si l'utilisateur peut retirer un membre d'un groupe
     */
    public function removeMember(?Authenticatable $user): bool
    {
        return $this->canAdminUsers($user);
    }

    /**
     * Story 35.4 — Vérifie si l'utilisateur peut écrire un override de capacité
     * (section « Capacités » de la page d'un groupe d'utilisateurs).
     *
     * Exige la permission capacités EXISTANTE `app.customize` (la MÊME que la
     * surface parc — aucune permission nouvelle). Contrairement à
     * {@see WorkstationGroupPolicy::customize()}, ce gate est INSTANCE-WIDE (pas de
     * `?UserGroup` en second paramètre, pas d'enveloppe scopée) — et c'est
     * VOULU :
     *
     *  1. **Aucune délégation par-UserGroup n'existe dans le modèle.** Toute
     *     l'infrastructure de délégation du projet est par-`WorkstationGroup`
     *     (`Delegation.workstation_group_id`, `PermissionService::canOnWorkstationGroup()`).
     *     Il n'y a AUCUNE délégation par-groupe d'utilisateurs ni par-établissement.
     *  2. **Refus ASSUMÉ du délégué par-salle (anti-piège WPKG 29.1, inversé).** Un
     *     refnum qui ne détient `app.customize` QUE par délégation scopée sur une
     *     salle (aucun droit Spatie global) est REFUSÉ ici : `hasPermission()` lit le
     *     droit GLOBAL Spatie (`$user->can('app.customize')`), qui est FAUX pour un
     *     délégué par-salle. Sans ce refus, la délégation par-salle fuiterait sur
     *     TOUTE la population d'utilisateurs de l'établissement — exactement le piège
     *     WPKG 29.1 à l'envers.
     *  3. **« Scopé à l'établissement du groupe » satisfait par construction.** La
     *     base SE5 est PAR-ÉTABLISSEMENT (un serveur = un établissement ;
     *     `Etablissement` n'existe qu'en config/LDAP ; `user_groups` n'a pas de
     *     colonne etab). Le droit GLOBAL sur CETTE instance EST donc le droit sur
     *     l'établissement du groupe.
     *
     * ⚙️ **Point d'extension unique.** Si une délégation par-UserGroup naît un jour,
     * c'est ICI (ajouter un `?UserGroup $group` + une enveloppe scopée sur le modèle
     * de {@see WorkstationGroupPolicy::customize()}) — nulle part ailleurs.
     */
    public function customize(?Authenticatable $user): bool
    {
        return $this->hasPermission($user, 'app.customize');
    }
}