<?php

namespace App\Policies;

use App\Models\WorkstationGroup;
use App\Policies\Traits\ChecksPermissions;
use App\Policies\Traits\RegistersGates;
use App\Services\PermissionService;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policy pour la gestion des WorkstationGroups (salles/parcs)
 *
 * Supporte les délégations scopées : un utilisateur peut avoir des droits
 * limités à un WorkstationGroup physique spécifique via le PermissionService.
 */
class WorkstationGroupPolicy
{
    use RegistersGates;
    use ChecksPermissions;

    /**
     * Définition des gates pour cette policy.
     *
     * Story 7.1 : ajout de `manage-workstationGroup` pour les opérations
     * de contrôle (batch actions, schedules, wallpaper...). Reprend
     * `computer.control` — délégation scopée OU droit global — plutôt que
     * `computer.install` (réservé à l'admin au niveau global).
     */
    protected static array $gates = [
        'viewAny-workstationGroup' => 'viewAny',
        'view-workstationGroup' => 'view',
        'create-workstationGroup' => 'create',
        'update-workstationGroup' => 'update',
        'delete-workstationGroup' => 'delete',
        'manage-workstationGroup' => 'manage',
        'manage-workstationGroups' => 'viewAny',
        'assign-wpkg-workstationGroup' => 'assignWpkg',
        'customize-workstationGroup' => 'customize',
    ];

    /**
     * Vérifie si l'utilisateur peut voir la liste des WorkstationGroups.
     *
     * Story 7.1 (QA e2e 2026-04-24) : accepte aussi les délégués scopés.
     * Un user sans droit global `computer.view` mais avec au moins une
     * délégation positive active sur une salle doit pouvoir ouvrir
     * `/app/parc` — le listing est ensuite filtré par `scopedUser()` dans
     * la page Livewire. Sans ça, le middleware `can:` ferme la porte avant
     * même que le scoping n'entre en jeu.
     */
    public function viewAny(?Authenticatable $user): bool
    {
        if ($this->canViewComputers($user)) {
            return true;
        }

        if (!$user instanceof \App\Models\User) {
            return false;
        }

        return app(PermissionService::class)
            ->getAuthorizedWorkstationGroups($user, 'computer.view')
            ->isNotEmpty();
    }

    /**
     * Vérifie si l'utilisateur peut voir un WorkstationGroup spécifique.
     * Supporte les délégations scopées.
     *
     * Scoping intentionnel (Story 7.1, décision Henri 2026-04-23) :
     *  - Groupes physiques : délégation scopée via `canOnWorkstationGroup`.
     *  - Groupes logiques (conteneurs hiérarchiques non-physical) : accès
     *    UNIQUEMENT via droit global `computer.view`. Un délégué sur un
     *    groupe physique NE VOIT PAS les groupes logiques parents, même s'il
     *    pourrait naviguer via breadcrumb. Ce comportement est volontaire :
     *    les salles physiques ont des hiérarchies mais les groupes logiques
     *    de rangement ne sont pas cible de délégation en 7.1.
     *  - Cf. `docs/domains/rights-management.md` (section Limitations).
     */
    public function view(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        if ($group !== null && $this->canCheckDelegation($user, $group)) {
            return app(PermissionService::class)
                ->canOnWorkstationGroup($user, 'computer.view', $group);
        }

        return $this->canViewComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut créer un WorkstationGroup
     */
    public function create(?Authenticatable $user): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut modifier un WorkstationGroup
     */
    public function update(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Vérifie si l'utilisateur peut supprimer un WorkstationGroup
     */
    public function delete(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        return $this->canAdminComputers($user);
    }

    /**
     * Story 7.1 — Vérifie si l'utilisateur peut gérer un WorkstationGroup
     * (actions batch, schedules, …).
     *
     * Autorise si :
     *  - l'utilisateur a le droit global `computer.control` via Spatie ;
     *  - OU il a une délégation scopée `computer.control` active sur ce group.
     *
     * Sans group en paramètre → se rabat sur le droit global.
     */
    public function manage(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        if ($group !== null && $this->canCheckDelegation($user, $group)) {
            return app(PermissionService::class)
                ->canOnWorkstationGroup($user, 'computer.control', $group);
        }

        return $this->hasPermission($user, 'computer.control');
    }

    /**
     * Story 29.1 — Vérifie si l'utilisateur peut assigner des applications WPKG
     * (profils/applications/options) sur un WorkstationGroup.
     *
     * Calqué à l'identique sur {@see manage()} : on remplace le Gate GLOBAL
     * `wpkg.assign` (Spatie non scopé, aveugle au périmètre) par une enveloppe
     * de policy scopée par salle physique.
     *
     * Autorise si :
     *  - groupe physique → délégation scopée `wpkg.assign` active sur ce group
     *    via {@see PermissionService::canOnWorkstationGroup()} ;
     *  - OU (fallback) droit global Spatie `wpkg.assign` (admin/technicien).
     *
     * Sans group (`null`, ex. poste nomade sans salle physique) → se rabat sur
     * le droit global UNIQUEMENT : pas de fausse ouverture, seul l'admin global
     * passe (AC #5).
     *
     * NOTE : l'exclusion négative active (qui prévaut même sur le droit global)
     * et l'expiration (`->active()`) sont DÉJÀ honorées par
     * `canOnWorkstationGroup` — ne pas les réimplémenter ici (AC #3, #4). Le
     * fallback global préserve la non-régression admin/technicien (AC #2).
     */
    public function assignWpkg(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        if ($group !== null && $this->canCheckDelegation($user, $group)) {
            return app(PermissionService::class)
                ->canOnWorkstationGroup($user, 'wpkg.assign', $group);
        }

        return $this->hasPermission($user, 'wpkg.assign');
    }

    /**
     * Story 29.6 — Vérifie si l'utilisateur peut écrire un override de capacité
     * (onglet « Options / Capacités ») sur un WorkstationGroup.
     *
     * Jumelle EXACTE de {@see assignWpkg()} (patron 29.1) : on remplace le droit
     * Spatie GLOBAL `app.customize` (aveugle au périmètre, qui ouvrait l'écriture
     * d'overrides sur n'importe quel parc dès lors que `groupId` était falsifiable
     * côté client) par une enveloppe de policy scopée par salle physique.
     *
     * Autorise si :
     *  - groupe physique → délégation scopée `app.customize` active sur ce group
     *    via {@see PermissionService::canOnWorkstationGroup()} ;
     *  - OU (fallback) droit global Spatie `app.customize` (admin/technicien).
     *
     * Sans group (`null`, ex. `WorkstationGroup::find()` sur un id inexistant) → se
     * rabat sur le droit global UNIQUEMENT : pas de fausse ouverture, seul l'admin
     * global passe.
     *
     * NOTE : l'exclusion négative active (qui prévaut même sur le droit global) et
     * l'expiration (`->active()`) sont DÉJÀ honorées par `canOnWorkstationGroup` —
     * ne pas les réimplémenter ici (AC #3, #4). Le fallback global préserve la
     * non-régression admin/technicien (AC #2). La convention physique→délégation /
     * logique→global (`canCheckDelegation`) est volontaire — à porter telle quelle,
     * comme view/manage/assignWpkg (AC #5).
     */
    public function customize(?Authenticatable $user, ?WorkstationGroup $group = null): bool
    {
        if ($group !== null && $this->canCheckDelegation($user, $group)) {
            return app(PermissionService::class)
                ->canOnWorkstationGroup($user, 'app.customize', $group);
        }

        return $this->hasPermission($user, 'app.customize');
    }

    /**
     * Vérifie si on peut utiliser le système de délégation pour cet utilisateur/groupe
     */
    private function canCheckDelegation(?Authenticatable $user, WorkstationGroup $group): bool
    {
        return $user instanceof \App\Models\User && $group->is_physical;
    }
}
