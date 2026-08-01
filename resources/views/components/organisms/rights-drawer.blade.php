<?php

use Livewire\Component;
use Livewire\Attributes\On;
use App\Enums\SambaPermission;
use App\Enums\SambaRole;
use App\Repositories\UserRepository;
use App\Services\GroupRightsProfileService;
use Illuminate\Support\Facades\Gate;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * Drawer de gestion des rôles et permissions Spatie d'un utilisateur.
 *
 * Story 7.3 — Refactor UI Spatie (2026-04-25) :
 *   La source de données est désormais Spatie (rôles + permissions effectives)
 *   au lieu du bitmask hex LDAP. L'UX globale (drawer, structure, fermeture,
 *   header) est préservée — c'est une refonte de la source et du rendu
 *   cellule par cellule.
 *
 *   Affichage :
 *     - Liste des rôles disponibles avec toggle (source = `SpatieRole` DB,
 *       seedés `SambaRole::isSeeded()` + custom rapatriés 7.2).
 *     - Pour chaque rôle : label lisible + permissions associées sous forme
 *       de badges (labels FR depuis `SambaPermission::label()`).
 *     - Permissions directes effectives de l'utilisateur (hors rôles) en
 *       section dédiée.
 *
 *   Sauvegarde :
 *     - Toggle d'un rôle → `$user->assignRole()` / `$user->removeRole()` sur
 *       la table Spatie `model_has_roles`. Les permissions individuelles ne
 *       sont pas modifiées ici — elles passent par le drawer délégations.
 *     - Post-save : reload de la page pour refléter les nouvelles permissions.
 *
 * Plus aucune lecture LDAP runtime ni de bitmask hex affiché.
 */
new class extends Component {
    public bool $isOpen = false;
    public bool $isLoading = false;

    // Utilisateur ciblé
    public string $targetLogin = '';

    // État des rôles : roleName => bool (assigné/non)
    public array $rolesState = [];
    public array $initialRolesState = [];

    // Métadonnées pour le rendu : roleName => ['label', 'is_seeded', 'permissions' => [['name','label']]]
    public array $rolesMeta = [];

    // Permissions directes effectives de l'utilisateur (hors rôles).
    public array $directPermissions = [];

    private UserRepository $userRepository;

    public function boot(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    #[On('open-rights-drawer')]
    public function open(string $login): void
    {
        if (!Gate::allows('manage-rights')) {
            ToastMagic::error('Vous n\'avez pas les droits pour gérer les permissions');
            return;
        }

        $this->targetLogin = $login;
        $this->isOpen = true;
        $this->isLoading = true;

        $this->loadRightsData();

        $this->isLoading = false;
    }

    public function close(): void
    {
        $this->isOpen = false;
    }

    private function loadRightsData(): void
    {
        // On cherche le User Eloquent par login.
        $user = \App\Models\User::where('login', $this->targetLogin)->first();
        if ($user === null) {
            ToastMagic::error('Utilisateur introuvable dans la base SER');
            $this->isOpen = false;
            return;
        }

        $assignedRoleNames = $user->roles()->pluck('name')->toArray();

        $this->rolesState = [];
        $this->rolesMeta = [];

        // Story 49.1 (AC8) — état « porté » DÉRIVÉ en lecture (aucune
        // persistance) : un profil porté par au moins un groupe est affiché
        // mais ni attribuable ni décochable ici.
        $carriers = app(GroupRightsProfileService::class)->carriersByRoleId();

        // Tous les rôles DB (seedés + custom rapatriés 7.2).
        $allRoles = SpatieRole::where('guard_name', 'web')->orderBy('name')->get();
        foreach ($allRoles as $role) {
            $isSeeded = SambaRole::isSeeded($role->name);
            $label = $isSeeded
                ? (SambaRole::tryFrom($role->name)?->label() ?? $role->name)
                : $role->name;

            $rolePermissions = $role->permissions()->pluck('name')->toArray();
            $permsWithLabels = array_map(function (string $permName) {
                $perm = SambaPermission::tryFrom($permName);
                return [
                    'name' => $permName,
                    'label' => $perm?->label() ?? $permName,
                ];
            }, $rolePermissions);

            $this->rolesMeta[$role->name] = [
                'label' => $label,
                'is_seeded' => $isSeeded,
                'permissions' => $permsWithLabels,
                // Story 49.1 (AC8) — groupes portant ce profil (vide = délégation
                // libre, comportement inchangé).
                'carried_by' => $carriers[(int) $role->id] ?? [],
            ];
            $this->rolesState[$role->name] = in_array($role->name, $assignedRoleNames, true);
        }

        $this->initialRolesState = $this->rolesState;

        // Permissions directes effectives (hors rôles) — affichées en lecture seule.
        $direct = $user->getDirectPermissions()->pluck('name')->toArray();
        $this->directPermissions = array_map(function (string $permName) {
            $perm = SambaPermission::tryFrom($permName);
            return [
                'name' => $permName,
                'label' => $perm?->label() ?? $permName,
            ];
        }, $direct);
    }

    public function toggleRole(string $roleName): void
    {
        // Story 49.1 (AC8) — un profil PORTÉ par un groupe n'est pas
        // basculable ici : l'appartenance au groupe l'attribue, et le drawer
        // le rendrait mensonger (la réconciliation le re-poserait / re-retirerait).
        if (!empty($this->rolesMeta[$roleName]['carried_by'] ?? [])) {
            return;
        }

        if (isset($this->rolesState[$roleName])) {
            $this->rolesState[$roleName] = !$this->rolesState[$roleName];
        }
    }

    public function saveChanges(): void
    {
        if (!Gate::allows('manage-rights')) {
            ToastMagic::error('Droits insuffisants');
            return;
        }

        $user = \App\Models\User::where('login', $this->targetLogin)->first();
        if ($user === null) {
            ToastMagic::error('Utilisateur introuvable');
            return;
        }

        $this->isLoading = true;
        $added = 0;
        $removed = 0;
        $protectedSkipped = 0;
        $carriedBlocked = [];

        // Story 49.1 (AC8 / D8) — les groupes porteurs sont relus EN BASE ici
        // (et non depuis `rolesMeta`, qui vient de l'état Livewire et pourrait
        // être forgé) : defense in depth, un payload forgé ne doit pas écrire.
        $carriers = app(GroupRightsProfileService::class)->carriersByRoleId();
        $carriedNames = [];
        foreach (SpatieRole::where('guard_name', 'web')->get(['id', 'name']) as $role) {
            if (!empty($carriers[(int) $role->id])) {
                $carriedNames[$role->name] = $carriers[(int) $role->id];
            }
        }

        foreach ($this->rolesState as $roleName => $isAssigned) {
            $wasAssigned = $this->initialRolesState[$roleName] ?? false;
            if ($isAssigned === $wasAssigned) {
                continue;
            }

            // Garde SERVEUR symétrique : ni assign ni remove sur un profil porté.
            if (isset($carriedNames[$roleName])) {
                $carriedBlocked[] = "{$roleName} (porté par : " . implode(', ', $carriedNames[$roleName]) . ')';
                continue;
            }

            if ($isAssigned) {
                $user->assignRole($roleName);
                $added++;
            } elseif ($user->isProtectedAdmin()) {
                // Le modèle refuse le retrait sur ce compte ; comptabilisé pour
                // porter un message explicite.
                $protectedSkipped++;
            } else {
                $user->removeRole($roleName);
                $removed++;
            }
        }

        // Invalider le cache Spatie pour que les changements soient visibles
        // dès la prochaine requête.
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->userRepository->invalidateCache($this->targetLogin);

        $this->isLoading = false;

        $total = $added + $removed;
        if (!empty($carriedBlocked)) {
            ToastMagic::error(
                'Profil(s) porté(s) par un groupe, non modifiable(s) ici : '
                . implode(' ; ', $carriedBlocked)
                . ' — pour donner ce profil, ajoutez l\'utilisateur au groupe.'
            );
        }
        if ($protectedSkipped > 0) {
            ToastMagic::warning(
                "Le compte d'administration « {$this->targetLogin} » est protégé : "
                . "{$protectedSkipped} retrait(s) de rôle ignoré(s)."
            );
        } elseif ($total === 0) {
            ToastMagic::info('Aucune modification à enregistrer');
        } else {
            ToastMagic::success("{$total} rôle(s) modifié(s) pour {$this->targetLogin}");
        }

        $this->isOpen = false;
        $this->js('window.location.reload()');
    }
};
?>

<div>
    <dialog class="modal" x-data="{ open: @entangle('isOpen') }" :class="{ 'modal-open': open }" x-cloak>
        <div class="modal-box w-11/12 max-w-2xl">
            {{-- Header --}}
            <div class="flex items-center justify-between mb-4">
                <div>
                    <h3 class="text-lg font-bold flex items-center gap-2">
                        <svg class="w-5 h-5 text-warning" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z">
                            </path>
                        </svg>
                        Gestion des permissions
                    </h3>
                    <p class="text-sm text-base-content/60 mt-1">
                        Utilisateur : <span class="font-mono font-semibold text-primary">{{ $targetLogin }}</span>
                    </p>
                </div>
                <button type="button" wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>

            @if ($isLoading && empty($rolesMeta))
                <div class="flex items-center justify-center py-12">
                    <span class="loading loading-spinner loading-lg text-warning"></span>
                </div>
            @else
                {{-- Liste des rôles Spatie avec toggles --}}
                <div class="space-y-1 max-h-[50vh] overflow-y-auto pr-1">
                    @foreach ($rolesMeta as $roleName => $meta)
                        @php
                            // Story 49.1 (AC8) — profil porté par un groupe :
                            // affiché, mais ni attribuable ni décochable ici.
                            $carriedBy = $meta['carried_by'] ?? [];
                            $isCarried = !empty($carriedBy);
                        @endphp
                        <div
                            class="flex items-center justify-between p-3 rounded-xl transition-colors border border-transparent
                                {{ $isCarried ? 'opacity-70' : 'hover:bg-base-200/50 hover:border-base-300' }}">
                            <div class="flex-1 min-w-0 mr-4">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-medium text-sm">{{ $meta['label'] }}</span>
                                    @if ($meta['is_seeded'])
                                        <span class="badge badge-xs badge-info">prédéfini</span>
                                    @else
                                        <span class="badge badge-xs badge-ghost">personnalisé</span>
                                    @endif
                                    @if ($isCarried)
                                        <span class="badge badge-xs badge-warning">porté par un groupe</span>
                                    @endif
                                    <code class="text-xs text-base-content/40">{{ $roleName }}</code>
                                </div>
                                @if ($isCarried)
                                    <div class="text-xs text-warning mt-1">
                                        Porté par le(s) groupe(s) {{ implode(', ', $carriedBy) }} — pour donner
                                        ce profil, ajoutez l'utilisateur au groupe.
                                    </div>
                                @endif
                                @if (!empty($meta['permissions']))
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        @foreach ($meta['permissions'] as $perm)
                                            <span
                                                class="badge badge-xs badge-warning badge-outline"
                                                title="{{ $perm['name'] }}">{{ $perm['label'] }}</span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                            <input type="checkbox" wire:click="toggleRole('{{ $roleName }}')"
                                @disabled($isCarried)
                                @checked($rolesState[$roleName] ?? false) class="toggle toggle-warning" />
                        </div>
                    @endforeach
                </div>

                @if (empty($rolesMeta))
                    <div class="text-center py-8 text-base-content/50">
                        <i class="fa-solid fa-shield-halved text-3xl mb-2"></i>
                        <p>Aucun rôle disponible</p>
                    </div>
                @endif

                {{-- Permissions directes (lecture seule) --}}
                @if (!empty($directPermissions))
                    <div class="divider my-3 text-xs text-base-content/50">Permissions directes</div>
                    <div class="flex flex-wrap gap-1 mb-2">
                        @foreach ($directPermissions as $perm)
                            <span class="badge badge-sm badge-primary badge-outline"
                                title="{{ $perm['name'] }}">{{ $perm['label'] }}</span>
                        @endforeach
                    </div>
                    <p class="text-xs text-base-content/50">
                        Ces permissions sont accordées directement (hors rôles) et se gèrent depuis
                        <code class="text-xs">/app/rights-management</code>.
                    </p>
                @endif
            @endif

            {{-- Footer --}}
            <div class="modal-action">
                <button type="button" class="btn btn-ghost" wire:click="close">
                    Annuler
                </button>
                <button type="button" wire:click="saveChanges" wire:loading.attr="disabled" class="btn btn-warning"
                    @disabled($rolesState === $initialRolesState)>
                    <span wire:loading wire:target="saveChanges" class="loading loading-spinner loading-sm"></span>
                    <i wire:loading.remove wire:target="saveChanges" class="fa-solid fa-check"></i>
                    Enregistrer
                </button>
            </div>
        </div>
        <form method="dialog" class="modal-backdrop">
            <button wire:click="close">close</button>
        </form>
    </dialog>
</div>
