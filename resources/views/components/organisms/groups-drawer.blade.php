<?php

use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;
use App\Repositories\GroupRepository;
use App\Repositories\UserRepository;
use App\Services\UserGroupService;
use App\Models\User as SqlUserModel;
use App\Models\UserGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Gate;
use Devrabiul\ToastMagic\Facades\ToastMagic;

new class extends Component {
    // Cache statique partagé entre instances
    private static ?array $cachedGroups = null;

    // État du drawer
    public bool $isOpen = false;
    public bool $isLoading = false;

    // Mode d'opération
    public bool $resetGroups = false;
    public bool $removeMode = false;

    // Recherche et sélection
    public string $search = '';
    public array $selectedGroups = [];

    // Utilisateurs ciblés (tableau de logins ou objets User)
    public array $targetUsers = [];

    // Groupes disponibles (chargés une seule fois en lazy)
    public array $availableGroups = [];
    public bool $groupsLoaded = false;

    // Services (private pour éviter la sérialisation Livewire)
    private GroupRepository $groupRepository;
    private UserRepository $userRepository;
    private UserGroupService $userGroupService;

    public function boot(GroupRepository $groupRepository, UserRepository $userRepository, UserGroupService $userGroupService)
    {
        $this->groupRepository = $groupRepository;
        $this->userRepository = $userRepository;
        $this->userGroupService = $userGroupService;
    }

    public function mount()
    {
        // Ne pas charger les groupes au mount - lazy loading
    }

    /**
     * Charge les groupes disponibles (une seule fois)
     */
    public function loadGroups(): void
    {
        if ($this->groupsLoaded) {
            return;
        }

        $this->isLoading = true;

        try {
            // Utiliser le cache statique si disponible
            if (self::$cachedGroups !== null) {
                $this->availableGroups = self::$cachedGroups;
                $this->groupsLoaded = true;
                $this->isLoading = false;
                return;
            }

            $groups = collect($this->userGroupService->listPaginated(search: null, perPage: 5000)->items())
                ->map(
                    static fn($group): array => [
                        'cn' => (string) $group->name,
                        'name' => (string) ($group->display_name ?: $group->name),
                        'description' => (string) ($group->type ?? ''),
                        'dn' => (string) ($group->ad_dn ?? ''),
                    ],
                )
                ->values()
                ->all();

            // Mettre en cache statique
            self::$cachedGroups = $groups;
            $this->availableGroups = $groups;
            $this->groupsLoaded = true;
        } catch (\Exception $e) {
            Log::error('GroupsDrawer loadGroups error: ' . $e->getMessage());
            $this->availableGroups = [];
            ToastMagic::error('Erreur lors du chargement des groupes');
        }

        $this->isLoading = false;
    }

    /**
     * Ouvre le drawer avec les utilisateurs sélectionnés
     */
    #[On('open-groups-drawer')]
    public function open(array $users = []): void
    {
        Log::info('GroupsDrawer open() called', ['users' => $users]);

        // Vérifier les droits
        if (!$this->canManageGroups()) {
            Log::warning('GroupsDrawer: Droits insuffisants');
            ToastMagic::error('Vous n\'avez pas les droits pour gérer les groupes');
            return;
        }

        $this->targetUsers = $users;
        $this->isOpen = true;
        $this->reset(['search', 'selectedGroups', 'resetGroups', 'removeMode']);

        // Charger les groupes si pas encore fait
        $this->loadGroups();

        Log::info('GroupsDrawer opened successfully', ['isOpen' => $this->isOpen, 'targetUsers' => $this->targetUsers]);
    }

    /**
     * Ferme le drawer
     */
    public function close(): void
    {
        $this->isOpen = false;
    }

    /**
     * Toggle la sélection d'un groupe
     */
    public function toggleGroup(string $groupCn): void
    {
        if (in_array($groupCn, $this->selectedGroups)) {
            $this->selectedGroups = array_values(array_diff($this->selectedGroups, [$groupCn]));
        } else {
            $this->selectedGroups[] = $groupCn;
        }
    }

    /**
     * Sélectionne tous les groupes filtrés
     */
    public function selectAllFiltered(): void
    {
        $filteredCns = $this->filteredGroups->pluck('cn')->toArray();
        $this->selectedGroups = array_unique(array_merge($this->selectedGroups, $filteredCns));
    }

    /**
     * Désélectionne tous les groupes
     */
    public function deselectAll(): void
    {
        $this->selectedGroups = [];
    }

    /**
     * Nombre de groupes sélectionnés
     */
    #[Computed]
    public function selectedCount(): int
    {
        return count($this->selectedGroups);
    }

    /**
     * Nombre d'utilisateurs ciblés
     */
    #[Computed]
    public function usersCount(): int
    {
        return count($this->targetUsers);
    }

    /**
     * Groupes filtrés par la recherche
     */
    #[Computed]
    public function filteredGroups()
    {
        $groups = $this->availableGroups ?? [];

        if (empty($this->search)) {
            return collect($groups);
        }

        $search = strtolower($this->search);

        return collect($groups)->filter(function ($group) use ($search) {
            return str_contains(strtolower($group['name'] ?? ''), $search) || str_contains(strtolower($group['cn'] ?? ''), $search) || str_contains(strtolower($group['description'] ?? ''), $search);
        });
    }

    /**
     * Vérifie si l'utilisateur peut gérer les groupes
     */
    public function canManageGroups(): bool
    {
        return Gate::allows('manage-groups');
    }

    /**
     * Applique les modifications de groupes
     */
    public function applyChanges(): void
    {
        // Vérifier les droits
        if (!$this->canManageGroups()) {
            ToastMagic::error('Vous n\'avez pas les droits pour effectuer cette action');
            return;
        }

        if (empty($this->targetUsers)) {
            ToastMagic::warning('Aucun utilisateur sélectionné');
            return;
        }

        if (empty($this->selectedGroups) && !$this->resetGroups) {
            ToastMagic::warning('Aucun groupe sélectionné');
            return;
        }

        $this->isLoading = true;

        try {
            $action = $this->removeMode ? 'retrait' : 'assignation';
            $usersCount = count($this->targetUsers);
            $groupsCount = count($this->selectedGroups);

            Log::info("GroupsDrawer: {$action} de {$groupsCount} groupe(s) pour {$usersCount} utilisateur(s)", [
                'users' => $this->targetUsers,
                'groups' => $this->selectedGroups,
                'removeMode' => $this->removeMode,
                'resetGroups' => $this->resetGroups,
            ]);

            $successCount = 0;
            $errorCount = 0;

            foreach ($this->targetUsers as $login) {
                $ldapUser = $this->userRepository->findLdapModelByLogin($login);
                if (!$ldapUser) {
                    Log::warning('GroupsDrawer: utilisateur introuvable dans LDAP', ['login' => $login]);
                    $errorCount++;
                    continue;
                }

                $userDn = $ldapUser->getDn();

                if ($this->resetGroups) {
                    $currentGroups = $ldapUser->getAttribute('memberof') ?? [];
                    foreach ($currentGroups as $groupDn) {
                        if (preg_match('/^cn=([^,]+)/i', $groupDn, $matches)) {
                            $cn = $matches[1];
                            if (!in_array($cn, ['Domain Users', 'Domain Admins', 'Eleves', 'Profs', 'Administratifs'])) {
                                $this->groupRepository->removeMember($cn, $userDn);
                            }
                        }
                    }
                    foreach ($this->selectedGroups as $groupCn) {
                        $this->groupRepository->addMember($groupCn, $userDn);
                    }
                    $successCount++;
                } elseif ($this->removeMode) {
                    foreach ($this->selectedGroups as $groupCn) {
                        $this->groupRepository->removeMember($groupCn, $userDn);
                    }
                    $successCount++;
                } else {
                    foreach ($this->selectedGroups as $groupCn) {
                        $this->groupRepository->addMember($groupCn, $userDn);
                    }
                    $successCount++;
                }

                // Sync pivot SQL
                $sqlUser = SqlUserModel::query()->where('login', $login)->first();
                if ($sqlUser) {
                    $selectedGroupIds = UserGroup::query()
                        ->whereIn('name', $this->selectedGroups)
                        ->pluck('id')
                        ->all();

                    if ($this->resetGroups) {
                        // Re-lire les groupes AD du user et sync tout le pivot
                        $freshMemberOf = $ldapUser->getAttribute('memberof') ?? [];
                        $adGroupCns = [];
                        foreach ($freshMemberOf as $dn) {
                            if (preg_match('/^CN=([^,]+),/i', $dn, $m)) {
                                $adGroupCns[] = $m[1];
                            }
                        }
                        $allGroupIds = UserGroup::query()->whereIn('name', $adGroupCns)->pluck('id')->all();
                        $sqlUser->userGroups()->sync($allGroupIds);
                    } elseif ($this->removeMode) {
                        $sqlUser->userGroups()->detach($selectedGroupIds);
                    } else {
                        $sqlUser->userGroups()->syncWithoutDetaching($selectedGroupIds);
                    }
                }

                $this->userRepository->invalidateCache($login);
            }

            if ($errorCount > 0) {
                ToastMagic::warning("{$successCount} utilisateur(s) traité(s), {$errorCount} erreur(s)");
            } elseif ($this->resetGroups) {
                ToastMagic::success("Groupes réinitialisés pour {$successCount} utilisateur(s)");
            } else {
                ToastMagic::success(ucfirst($action) . " de {$groupsCount} groupe(s) pour {$successCount} utilisateur(s) effectuée");
            }

            // Émettre un événement pour rafraîchir les données parentes
            $this->dispatch('groups-updated', users: $this->targetUsers);

            // Fermer le drawer et recharger la page courante
            $this->isOpen = false;
            $this->js('window.location.reload()');
        } catch (\Exception $e) {
            Log::error('GroupsDrawer applyChanges error: ' . $e->getMessage());
            ToastMagic::error('Erreur lors de l\'application des modifications: ' . $e->getMessage());
        }

        $this->isLoading = false;
    }
};
?>

<div>
    <!-- Drawer pour la gestion des groupes -->
    <div class="dialog z-[60]" x-data="{ open: @entangle('isOpen') }">
        <input type="checkbox" class="drawer-toggle" :checked="open" />
        <div class="drawer-side z-[60]" x-show="open" x-cloak>
            <label class="drawer-overlay" wire:click="close"></label>
            <div class="bg-base-200 h-screen w-full md:w-[500px] lg:w-[600px] flex flex-col z-[60]">

                <!-- Header du drawer -->
                <div class="bg-base-100 p-4 border-b border-base-300 shrink-0">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold">Gestion des groupes</h3>
                            <p class="text-sm text-base-content/60">
                                @if ($this->usersCount > 0)
                                    {{ $this->usersCount }} utilisateur(s) sélectionné(s)
                                @else
                                    Aucun utilisateur sélectionné
                                @endif
                            </p>
                        </div>
                        <button wire:click="close" class="btn btn-sm btn-circle btn-ghost">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- Contenu principal -->
                <div class="flex-1 overflow-hidden flex flex-col p-4">

                    <!-- Options de mode -->
                    <div class="space-y-3 shrink-0 mb-4">
                        <!-- Mode ajouter/retirer -->
                        <label class="flex gap-3 cursor-pointer" @class(['opacity-50 pointer-events-none' => $resetGroups])>
                            <input type="checkbox" wire:model.live="removeMode" class="toggle toggle-warning"
                                @disabled($resetGroups) />
                            <div class="flex-1">
                                <div class="font-medium">
                                    {{ $removeMode ? 'Mode retrait' : 'Mode ajout' }}
                                </div>
                                <div class="text-sm text-base-content/60">
                                    {{ $removeMode ? 'Retire les groupes sélectionnés des utilisateurs' : 'Ajoute les groupes sélectionnés aux utilisateurs' }}
                                </div>
                            </div>
                        </label>

                        <!-- Option de réinitialisation -->
                        <label class="flex gap-3 cursor-pointer">
                            <input type="checkbox" wire:model.live="resetGroups" class="toggle toggle-error" />
                            <div class="flex-1">
                                <div class="font-medium">{{ $resetGroups ? 'Mode réinitialisation' : 'Mode normal' }}</div>
                                <div class="text-sm {{ $resetGroups ? 'text-error' : 'text-base-content/60' }}">
                                    {{ $resetGroups
                                        ? 'Supprime tous les groupes existants et assigne uniquement les groupes sélectionnés'
                                        : 'Conserve les groupes existants et ajoute ou retire les groupes sélectionnés' }}
                                </div>
                            </div>
                        </label>
                    </div>

                    <!-- Barre de recherche -->
                    <div class="mb-3 shrink-0">
                        <label class="input input-bordered flex items-center gap-2 w-full">
                            <i class="fa-solid fa-magnifying-glass opacity-50"></i>
                            <input type="text" wire:model.live.debounce.300ms="search"
                                placeholder="Rechercher un groupe..." class="grow" />
                            @if ($search)
                                <button type="button" wire:click="$set('search', '')"
                                    class="btn btn-ghost btn-xs btn-circle">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            @endif
                        </label>
                    </div>

                    <!-- Actions de sélection rapide -->
                    <div class="flex gap-2 mb-3 shrink-0">
                        <button type="button" wire:click="selectAllFiltered" class="btn btn-xs btn-outline">
                            <i class="fa-solid fa-check-double"></i>
                            Tout sélectionner
                        </button>
                        <button type="button" wire:click="deselectAll" class="btn btn-xs btn-outline"
                            @disabled(count($selectedGroups) === 0)>
                            <i class="fa-solid fa-xmark"></i>
                            Tout désélectionner
                        </button>
                        @if ($this->selectedCount > 0)
                            <span class="badge badge-primary">{{ $this->selectedCount }} sélectionné(s)</span>
                        @endif
                    </div>

                    <!-- Liste des groupes -->
                    <div class="flex-1 overflow-y-auto overflow-x-hidden min-h-0 border rounded-lg bg-base-100">
                        @if ($isLoading && !$groupsLoaded)
                            <div class="flex items-center justify-center h-32">
                                <span class="loading loading-spinner loading-lg"></span>
                            </div>
                        @elseif (count($this->filteredGroups) > 0)
                            <div class="divide-y divide-base-200">
                                @foreach ($this->filteredGroups as $group)
                                    <label wire:key="group-{{ $group['cn'] }}"
                                        class="flex items-center gap-3 p-3 cursor-pointer hover:bg-base-200 transition-colors">
                                        <input type="checkbox" wire:click="toggleGroup('{{ $group['cn'] }}')"
                                            @checked(in_array($group['cn'], $selectedGroups))
                                            class="checkbox checkbox-primary checkbox-sm" />
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium truncate">{{ $group['name'] }}</div>
                                            @if (!empty($group['description']))
                                                <div class="text-xs text-base-content/60 truncate">
                                                    {{ $group['description'] }}
                                                </div>
                                            @endif
                                        </div>
                                        @if (in_array($group['cn'], $selectedGroups))
                                            <i class="fa-solid fa-check text-primary"></i>
                                        @endif
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <div class="flex flex-col items-center justify-center h-32 text-base-content/60">
                                <i class="fa-solid fa-folder-open text-3xl mb-2"></i>
                                <span>{{ $search ? 'Aucun groupe trouvé' : 'Aucun groupe disponible' }}</span>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Footer avec actions -->
                <div class="bg-base-100 p-4 border-t border-base-300 shrink-0">
                    <div class="flex justify-between items-center">
                        <button type="button" class="btn btn-ghost" wire:click="close">
                            Annuler
                        </button>
                        <button type="button" wire:click="applyChanges" wire:loading.attr="disabled"
                            class="btn {{ $removeMode && !$resetGroups ? 'btn-warning' : ($resetGroups ? 'btn-error' : 'btn-primary') }}"
                            @disabled(count($targetUsers) === 0 || (count($selectedGroups) === 0 && !$resetGroups))>
                            <span wire:loading wire:target="applyChanges"
                                class="loading loading-spinner loading-sm"></span>
                            <i wire:loading.remove wire:target="applyChanges"
                                class="fa-solid {{ $removeMode && !$resetGroups ? 'fa-minus' : ($resetGroups ? 'fa-rotate' : 'fa-plus') }}"></i>
                            @if ($resetGroups)
                                Réinitialiser
                            @elseif ($removeMode)
                                Retirer
                            @else
                                Assigner
                            @endif
                            @if ($this->selectedCount > 0)
                                <span class="badge badge-sm">{{ $this->selectedCount }}</span>
                            @endif
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
