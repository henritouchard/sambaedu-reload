<?php

use App\Components\Traits\WithToasts;
use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\UserGroupService;
use App\Support\RoleCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use App\Components\Traits\WithReturnBack;

new #[Title('Groupe utilisateur')] class extends Component {
    use WithReturnBack;
    use WithToasts;

    private UserGroupService $userGroupService;

    // Onglet d'origine (URL relative) pour le bouton retour — voir WithReturnBack.
    #[Url]
    public ?string $from = null;

    /** URL de retour : provenance dynamique, repli sur l'onglet Groupes. */
    public function backUrl(): string
    {
        return $this->resolveBack(route('app.users', ['tab' => 'groups']));
    }

    public int $groupId;
    public string $name = '';
    public string $displayName = '';
    public string $type = 'custom';
    public array $selectedUserIds = [];
    public bool $editing = false;

    /**
     * Story 42.3 (T3.2/D5) — map `userId => rôle choisi` pour les membres
     * NOUVELLEMENT cochés dans l'edit-form (jamais pour les membres déjà
     * existants). Initialisée au défaut dérivé par `toggleUser`, purgée à
     * `cancelEditing`/`save`. Validée exclusivement via `setPendingRole()`.
     */
    public array $pendingRoles = [];

    public function boot(UserGroupService $userGroupService): void
    {
        $this->userGroupService = $userGroupService;
    }

    public function mount(int $id): void
    {
        $group = $this->userGroupService->getById($id);

        if ($group === null) {
            abort(404, 'Groupe introuvable');
        }

        $this->groupId = $group->id;
        $this->name = $group->name;
        $this->displayName = $group->display_name ?? '';
        $this->type = $group->type;
        $this->selectedUserIds = $group->users->pluck('id')->map(fn(mixed $v): int => (int) $v)->values()->all();
    }

    #[Computed]
    public function members(): Collection
    {
        $group = $this->userGroupService->getById($this->groupId);

        // Story 60.2 — le libellé du rôle d'arête dépend du TYPE de groupe
        // (« Enseignant » en classe, « Porteur » en projet, « Référent » en
        // équipe). On lit le type en BASE et non `$this->type` : cette propriété
        // Livewire publique est ré-hydratée depuis le client, donc forgeable.
        $groupType = is_string($group?->type) ? (string) $group->type : null;

        return $group?->users?->map(function ($user) use ($groupType): array {
            $label = $user->fullname ?: trim((string) (($user->firstname ?? '') . ' ' . ($user->lastname ?? '')));
            if ($label === '') {
                $label = $user->login;
            }

            // Rôle métier : prof / eleve / autre. On lit la colonne SQL `role`
            // (déjà en mémoire via `with('users')`). Les helpers `User::isProf()`/
            // `isEleve()` qui interrogeaient le LDAP « d'abord » — 1 round-trip
            // réseau par membre au render, pour une info déjà présente en base —
            // ont été SUPPRIMÉS par la Story 49.2 (FR-R3). SQL = source de vérité
            // côté SE5 (alignée par syncFromAd).
            $role = $user->role === 'prof' ? 'prof' : ($user->role === 'eleve' ? 'eleve' : 'autre');

            // Story 42.3 (D1/T1.1) — rôle D'ARÊTE (`user_group_user.role`),
            // exposé sous des clés NOUVELLES `edge_role`/`edge_role_label` :
            // `'role'` ci-dessus reste le rôle GLOBAL (pilote onglets + badge
            // PP), AUCUNE collision de nom (piège 42.1 #5). Arête vide/hors
            // vocabulaire (donnée sale) → affichée « Élève » (D1).
            $edgeRoleRaw = (string) ($user->pivot->role ?? '');
            $edgeRole = in_array($edgeRoleRaw, UserGroupUserPivot::roles(), true)
                ? $edgeRoleRaw
                : UserGroupUserPivot::ROLE_MEMBER;

            return [
                'id' => $user->id,
                'login' => $user->login,
                'label' => $label,
                'role' => $role,
                // Badge PP : porté par le RÔLE d'arête (`role === 'owner'`,
                // story 42.1 — bascule de lecture depuis `is_head_teacher`). La
                // CLÉ de view-model reste `'is_head_teacher'` : le `'role'`
                // ci-dessus est le rôle GLOBAL (prof/eleve/autre), ne PAS
                // introduire de collision de nom (l'UI rôle d'arête = 42.3).
                'is_head_teacher' => (($user->pivot->role ?? null) === UserGroupUserPivot::ROLE_OWNER),
                'edge_role' => $edgeRole,
                // Story 42.3 (D1) — aucune valeur technique (`member|manager|
                // owner`) n'est rendue comme texte visible. Story 60.2 — le
                // libellé vient de la table CANONIQUE par type de groupe, plus
                // d'un `match` local écrit pour le seul cas scolaire.
                'edge_role_label' => RoleCatalog::label($groupType, $edgeRole),
            ];
        }) ?? collect();
    }

    /**
     * Story 42.3 (AC2/D4/D7) — édition UNITAIRE du rôle d'arête d'un membre
     * depuis la colonne « Rôle » de la table des membres. Write Eloquent
     * direct : l'observer pivot (42.2, `updated()`) reprojette l'AD si le
     * rôle change réellement (dirty) — AUCUN appel `updateGroup`/
     * `resyncGroupAdProjection` explicite ici (contrat 42.2 #4, volet
     * unitaire — c'est le volet MASSE de `save()` qui l'exige).
     */
    public function updateMemberRole(int $userId, string $role): void
    {
        // Double guard — pattern removeMember/head-teacher-section (D7).
        Gate::authorize('update-group');

        try {
            UserGroupUserPivot::assertValidRole($role);
        } catch (InvalidArgumentException) {
            // Piège n°8 — valeur reçue non constante (client) : jamais de 500.
            $this->toastError('Rôle invalide.');
            return;
        }

        $group = $this->userGroupService->getById($this->groupId);
        if ($group === null || !$group->users()->whereKey($userId)->exists()) {
            $this->toastError('Ce membre n\'appartient plus au groupe.');
            return;
        }

        // D3 — « Prof principal » (owner) uniquement pour les groupes classe,
        // y compris contre un payload forgé. Review 42.3 #3 : garde sur le type
        // DB ($group->type), jamais $this->type — propriété Livewire publique
        // ré-hydratée du client, donc forgeable.
        if ($role === UserGroupUserPivot::ROLE_OWNER && $group->type !== 'classe') {
            $this->toastError(sprintf(
                'Le rôle « %s » n\'est disponible que pour les classes.',
                RoleCatalog::label('classe', UserGroupUserPivot::ROLE_OWNER),
            ));
            return;
        }

        $group->users()->updateExistingPivot($userId, ['role' => $role]);

        unset($this->members, $this->students, $this->teachers);

        // D6 — toast factuel, aucune promesse de persistance (limite
        // transitoire read-back documentée en doc QA §17).
        $this->toastSuccess('Rôle mis à jour.');
    }

    /**
     * Rafraîchit la liste des membres après désignation d'un PP dans la modale
     * (event émis par head-teacher-section) — l'icône PP suit le pivot.
     */
    #[On('head-teachers-updated')]
    public function refreshMembers(): void
    {
        unset($this->members, $this->students, $this->teachers);
    }

    /** Membres « élèves » de la classe (onglet Élèves). */
    #[Computed]
    public function students(): Collection
    {
        return $this->members->where('role', 'eleve')->values();
    }

    /**
     * Membres « profs » (onglet Profs). On y range aussi les rôles « autre »
     * (admin/personnel) : tout ce qui n'est pas un élève est un encadrant.
     */
    #[Computed]
    public function teachers(): Collection
    {
        return $this->members->where('role', '!=', 'eleve')->values();
    }

    #[Computed]
    public function availableUsers(): Collection
    {
        $memberIds = $this->userGroupService->getById($this->groupId)?->users?->pluck('id')->map(fn(mixed $v): int => (int) $v)->all() ?? [];

        $candidates = $this->userGroupService
            ->getAssignableUsers()
            ->reject(fn($user): bool => in_array((int) $user->id, $memberIds, true));

        // Story 42.3 (T3.1) — `getAssignableUsers()` (app/Services, non modifiable
        // — AC8) ne sélectionne pas `role` : lecture SQL complémentaire, ZÉRO LDAP
        // (project_isprof_iseleve_ldap_first_cost), pour dériver le rôle par
        // défaut proposé au rattachement.
        $globalRolesById = User::query()
            ->whereIn('id', $candidates->pluck('id')->all())
            ->pluck('role', 'id');

        return $candidates
            ->map(function ($user) use ($globalRolesById): array {
                $label = $user->fullname ?: trim((string) (($user->firstname ?? '') . ' ' . ($user->lastname ?? '')));
                if ($label === '') {
                    $label = $user->login;
                }

                return [
                    'value' => $user->id,
                    'label' => $label,
                    'hint' => $user->login,
                    'disabled' => false,
                    // D5/T3.1 — défaut dérivé, jamais owner.
                    'default_role' => UserGroupUserPivot::defaultRoleForGlobalRole($globalRolesById[$user->id] ?? null),
                ];
            })
            ->values();
    }

    public function removeMember(int $userId): void
    {
        // Mutation : la route n'exige que `user.read`, on garde donc le double
        // guard serveur (UI `@can('update-group')` + autorisation ici) aligné
        // sur head-teacher-section. `update-group` → GroupPolicy::update.
        Gate::authorize('update-group');

        $this->selectedUserIds = array_values(array_filter($this->selectedUserIds, fn(int $id): bool => $id !== $userId));

        $this->userGroupService->updateGroup($this->groupId, [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'type' => $this->type,
            'user_ids' => $this->selectedUserIds,
        ]);

        unset($this->members, $this->students, $this->teachers);

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'Membre retiré',
            'message' => 'Le membre a été retiré du groupe.',
        ]);
    }

    public function toggleUser(int $userId): void
    {
        if (in_array($userId, $this->selectedUserIds, true)) {
            $this->selectedUserIds = array_values(array_filter($this->selectedUserIds, fn(int $id): bool => $id !== $userId));
            unset($this->pendingRoles[$userId]);
        } else {
            $this->selectedUserIds[] = $userId;
            // D5/T3.2 — un user coché ici est TOUJOURS un candidat non-membre
            // (availableUsers exclut déjà les membres actuels) : propose le
            // défaut dérivé, surchargeable via setPendingRole().
            $option = collect($this->availableUsers)->firstWhere('value', $userId);
            $this->pendingRoles[$userId] = $option['default_role'] ?? UserGroupUserPivot::ROLE_MEMBER;
        }
    }

    /**
     * Story 42.3 (T3.2/D5) — surcharge du rôle proposé pour un candidat
     * NOUVELLEMENT coché de l'edit-form. Options UI limitées à Élève/Prof
     * (jamais owner) ; validation serveur systématique (D7 — un payload
     * forgé ne doit jamais atteindre le pivot).
     */
    public function setPendingRole(int $userId, string $role): void
    {
        if (!array_key_exists($userId, $this->pendingRoles)) {
            // Id inconnu/forgé (pas un candidat nouvellement coché) : ignoré.
            return;
        }

        try {
            UserGroupUserPivot::assertValidRole($role);
        } catch (InvalidArgumentException) {
            $this->toastError('Rôle invalide.');
            return;
        }

        if ($role === UserGroupUserPivot::ROLE_OWNER) {
            // D5 — jamais owner au rattachement, même payload forgé.
            $this->toastError(sprintf(
                'Le rôle « %s » ne peut pas être choisi au rattachement.',
                RoleCatalog::label('classe', UserGroupUserPivot::ROLE_OWNER),
            ));
            return;
        }

        $this->pendingRoles[$userId] = $role;
    }

    public function startEditing(): void
    {
        $this->editing = true;
    }

    public function cancelEditing(): void
    {
        $group = $this->userGroupService->getById($this->groupId);

        if ($group !== null) {
            $this->name = $group->name;
            $this->displayName = $group->display_name ?? '';
            $this->type = $group->type;
            $this->selectedUserIds = $group->users->pluck('id')->map(fn(mixed $v): int => (int) $v)->values()->all();
        }

        $this->pendingRoles = [];
        $this->resetValidation();
        $this->editing = false;
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'displayName' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'max:50'],
            'selectedUserIds' => ['array'],
            'selectedUserIds.*' => ['integer', 'exists:users,id'],
        ]);

        // Snapshot AVANT `updateGroup` (AD-first) — sert à isoler les ids
        // RÉELLEMENT nouveaux (piège n°3 : les arêtes existantes ne sont
        // JAMAIS réécrites par ce chemin, D5).
        $existingMemberIds = $this->userGroupService->getById($this->groupId)
            ?->users
            ?->pluck('id')
            ->map(fn(mixed $v): int => (int) $v)
            ->all() ?? [];

        $updatedGroup = $this->userGroupService->updateGroup($this->groupId, [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'type' => $this->type,
            'user_ids' => $this->selectedUserIds,
        ]);

        // T3.4 (D5) — surcharges de rôle pour les SEULS membres réellement
        // nouveaux, calculées sur l'état FRAIS posé par le read-back AD-first
        // d'`updateGroup` (piège n°3 : jamais un état pré-save).
        $newMemberIds = array_values(array_diff($this->selectedUserIds, $existingMemberIds));

        if ($newMemberIds !== []) {
            $freshRoles = [];
            foreach (
                $updatedGroup->users()->whereIn('users.id', $newMemberIds)->pluck('user_group_user.role', 'users.id')
                as $uid => $freshRole
            ) {
                $freshRoles[(int) $uid] = (string) $freshRole;
            }

            $overrides = [];
            foreach ($newMemberIds as $uid) {
                $chosen = $this->pendingRoles[$uid] ?? null;
                if ($chosen === null || $chosen === ($freshRoles[$uid] ?? null)) {
                    continue;
                }

                // Défense en profondeur (D7) — `setPendingRole()` a déjà
                // validé/refusé owner, mais on ne fait jamais confiance à un
                // état client sans revalider au point d'écriture pivot.
                try {
                    UserGroupUserPivot::assertValidRole($chosen);
                } catch (InvalidArgumentException) {
                    continue;
                }
                if ($chosen === UserGroupUserPivot::ROLE_OWNER) {
                    continue;
                }

                $overrides[$uid] = $chosen;
            }

            if ($overrides !== []) {
                // Contrat review 42.2 #4 — édition EN MASSE : suspendre le
                // resync AD unitaire par write, puis UNE SEULE reprojection
                // explicite (piège n°2 : sinon K writes = K tempêtes LDAP).
                UserGroupUserPivotObserver::disableAdResync();
                try {
                    foreach ($overrides as $uid => $overrideRole) {
                        $updatedGroup->users()->updateExistingPivot($uid, ['role' => $overrideRole]);
                    }
                } finally {
                    UserGroupUserPivotObserver::enableAdResync();
                }
                $this->userGroupService->resyncGroupAdProjection($updatedGroup);
            }
        }

        $this->pendingRoles = [];
        unset($this->members, $this->students, $this->teachers);

        session()->flash('toast', [
            'type' => 'success',
            'title' => 'Groupe mis à jour',
            'message' => 'Les modifications ont été enregistrées.',
        ]);

        $this->editing = false;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            'classe' => 'Classe',
            'cours' => 'Cours',
            'matiere' => 'Matière',
            'matiere_classe' => 'Matière / Classe',
            'projet' => 'Projet',
            'equipe' => 'Équipe',
            'custom' => 'Personnalisé',
            'other_group' => 'Autre',
            default => ucfirst($this->type),
        };
    }

    public function typeBadgeClass(): string
    {
        return match ($this->type) {
            'classe' => 'badge-info',
            'cours' => 'badge-success',
            'matiere', 'matiere_classe' => 'badge-warning',
            'projet' => 'badge-secondary',
            'equipe' => 'badge-accent',
            default => 'badge-ghost',
        };
    }
};
?>

<x-organisms.page :title="$editing ? 'Modifier le groupe' : ($displayName ?: $name)" :description="$editing ? 'Éditez les informations et les membres du groupe' : null" :backUrl="!$editing ? $this->backUrl() : null" backText="Retour">
    <x-slot:actions>
        <div class="flex items-center gap-2">
            @if ($editing)
                <button type="button" class="btn btn-ghost" wire:click="cancelEditing">
                    <i class="fa-solid fa-xmark"></i>
                    Annuler
                </button>
            @else
                <div class="dropdown dropdown-left">
                    <label tabindex="0" class="btn btn-primary gap-2 min-w-32">
                        <i class="fa-solid fa-pen-to-square"></i>
                        Actions
                        <i class="fa-solid fa-chevron-down text-xs"></i>
                    </label>
                    <ul tabindex="0"
                        class="dropdown-content menu p-2 shadow-lg bg-base-100 rounded-box w-64 border border-base-300">
                        <li>
                            <button type="button" class="flex items-center gap-3 w-full" wire:click="startEditing"
                                @click="document.activeElement.blur()">
                                <i class="fa-solid fa-pen-to-square w-4"></i>
                                <div class="flex flex-col items-start">
                                    <span class="font-medium">Modifier le groupe</span>
                                    <span class="text-xs opacity-70">Nom, type et membres</span>
                                </div>
                            </button>
                        </li>
                        @if ($type === 'classe')
                            @can('update-group')
                                <li>
                                    <button type="button" class="flex items-center gap-3 w-full"
                                        @click="Livewire.dispatch('open-head-teacher-modal'); document.activeElement.blur();">
                                        <i class="fa-solid fa-chalkboard-user w-4"></i>
                                        <div class="flex flex-col items-start">
                                            <span class="font-medium">Nommer un professeur principal</span>
                                            <span class="text-xs opacity-70">Désigner le(s) PP de la classe</span>
                                        </div>
                                    </button>
                                </li>
                            @endcan
                        @endif
                        @can('user.password.init')
                            <li>
                                <button type="button" class="flex items-center gap-3 w-full"
                                    @click="Livewire.dispatch('open-password-reset-modal', { users: [], groups: [{{ $groupId }}] }); document.activeElement.blur();">
                                    <i class="fa-solid fa-key w-4"></i>
                                    <div class="flex flex-col items-start">
                                        <span class="font-medium">Réinitialiser les mdp du groupe</span>
                                        <span class="text-xs opacity-70">Membres directs uniquement</span>
                                    </div>
                                </button>
                            </li>
                        @endcan
                    </ul>
                </div>
            @endif
        </div>
    </x-slot:actions>

    @if ($editing)
        @include('pages.users.groups.[id]._partials.edit-form')
    @else
        <div class="space-y-6">
            @include('pages.users.groups.[id]._partials.group-header')
            @include('pages.users.groups.[id]._partials.members-list')

            {{-- Story 5.2 — Section Partage de classe (Livewire SFC).
                 Visible UNIQUEMENT si $type === 'classe' (le SFC fait aussi
                 son propre check en mount + retourne un div vide sinon).
                 Position : entre members-list et group-quota-section. --}}
            @if ($type === 'classe')
                @livewire('pages::users.groups.[id]._partials.class-share-section', ['groupId' => $groupId], key('class-share-' . $groupId))

                {{-- Story 4.15 (refonte UI) — MODALE « Professeur principal »
                     (Livewire SFC, rendue masquée). Déclenchée par l'action
                     « Nommer un professeur principal » du menu Actions
                     (event open-head-teacher-modal). Désigne le(s) PP via le
                     pivot is_head_teacher → écriture SQL→AD 3e cible PP_<base> ;
                     l'état se lit dans la liste via l'icône après le nom.
                     Directive @livewire(...) (les crochets [id] cassent la
                     tag-syntax). --}}
                @livewire('pages::users.groups.[id]._partials.head-teacher-section', ['groupId' => $groupId], key('head-teacher-' . $groupId))
            @endif

            {{-- Story 5.1c — Section Quota groupe (Livewire SFC).
                 Section verticale (pas d'onglets). Visible en lecture pour tout user, modifiable
                 uniquement par server.admin (double guard UI + serveur).
                 NB : on utilise la directive @livewire(...) plutôt que la tag-syntax
                 car les crochets `[id]` du chemin SFC cassent le parsing Blade. --}}
            @livewire('pages::users.groups.[id]._partials.group-quota-section', ['groupId' => $groupId], key('group-quota-' . $groupId))

            {{-- Story 35.4 — Section « Capacités » du groupe d'utilisateurs (Livewire
                 SFC). Arme une capacité par groupe (override de valeur, maille
                 UserGroup). Visible pour TOUS les types de groupes (les cibles CD95
                 sont « élèves » = classes ET « direction/vie scolaire » = groupes
                 custom), gatée par le gate instance-wide `customize-userGroup`
                 (droit global `app.customize`) — le SFC re-garde 403 en mount +
                 chaque mutation. Directive @livewire(...) car les crochets `[id]`
                 du chemin SFC cassent le parsing Blade. --}}
            @can('customize-userGroup')
                @livewire('pages::users.groups.[id]._partials.capabilities-section', ['groupId' => $groupId], key('capabilities-' . $groupId))
            @endcan
        </div>
    @endif

    <livewire:components::organisms.password-reset-modal />
</x-organisms.page>
