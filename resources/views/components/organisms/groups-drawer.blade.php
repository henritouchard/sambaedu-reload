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
     * La réinitialisation n'est possible qu'en mode ajout.
     * Dès qu'on bascule en mode retrait, on décoche la case de réinitialisation.
     */
    public function updatedRemoveMode(): void
    {
        if ($this->removeMode) {
            $this->resetGroups = false;
        }
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
     * Pour chaque groupe (indexé par cn / name du groupe), la liste des noms
     * affichés (« Nom Prénom ») des utilisateurs ciblés qui en sont déjà
     * membres.
     *
     * Source : pivot SQL `user_group_user` (tenu à jour par applyChanges et la
     * synchro AD). Sert uniquement à l'affichage — mettre en évidence les
     * groupes déjà occupés par tout ou partie de la sélection, et lister les
     * membres au survol.
     *
     * @return array<string,list<string>>  cn => noms affichés des membres
     */
    #[Computed]
    public function membershipMembers(): array
    {
        if (empty($this->targetUsers)) {
            return [];
        }

        $rows = \Illuminate\Support\Facades\DB::table('user_group_user')
            ->join('user_groups', 'user_groups.id', '=', 'user_group_user.user_group_id')
            ->join('users', 'users.id', '=', 'user_group_user.user_id')
            ->whereIn('users.login', $this->targetUsers)
            ->orderBy('users.lastname')
            ->orderBy('users.firstname')
            ->get(['user_groups.name as gname', 'users.firstname', 'users.lastname', 'users.login']);

        $map = [];
        foreach ($rows as $row) {
            $display = trim(($row->lastname ?? '') . ' ' . ($row->firstname ?? ''));
            if ($display === '') {
                $display = (string) $row->login;
            }
            $map[$row->gname][] = $display;
        }

        return $map;
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

        // Sécurité : la réinitialisation n'est valable qu'en mode ajout.
        if ($this->removeMode) {
            $this->resetGroups = false;
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
    <x-molecules.modal wire:model="isOpen" title="Gestion des groupes" icon="fa-users-gear text-primary"
        size="max-w-2xl" height="h-[85vh]" noScroll
        subtitle="{{ $this->usersCount > 0 ? $this->usersCount . ' utilisateur(s) sélectionné(s)' : 'Aucun utilisateur sélectionné' }}">

        {{-- Options de mode — deux cartes côte à côte (« Mode retrait » et
             « Mode réinitialisation »). Toujours visibles ; mutuellement
             exclusives : activer l'une atténue et désactive l'autre. Par défaut
             (aucune cochée) = mode ajout. --}}
        <x-molecules.modal.section dense>
            <div class="grid grid-cols-2 gap-3">
                {{-- Mode retrait --}}
                <label @class([
                    'group relative flex flex-col gap-1.5 rounded-xl border p-3 transition-all duration-150',
                    'cursor-pointer' => !$resetGroups,
                    'cursor-not-allowed opacity-40' => $resetGroups,
                    'border-warning bg-warning/10 ring-1 ring-warning/40 shadow-sm' => $removeMode,
                    'border-base-300 bg-base-100 hover:border-warning/50 hover:bg-warning/5' => !$removeMode && !$resetGroups,
                ])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2 text-sm font-semibold text-warning">
                            <i class="fa-solid fa-user-minus"></i>
                            Mode retrait
                        </span>
                        <input type="checkbox" wire:model.live="removeMode"
                            class="toggle toggle-xs toggle-warning" @disabled($resetGroups) />
                    </div>
                    <p class="text-xs leading-snug text-base-content/60">
                        Retire les groupes sélectionnés des utilisateurs, au lieu de les leur ajouter.
                    </p>
                </label>

                {{-- Mode réinitialisation --}}
                <label @class([
                    'group relative flex flex-col gap-1.5 rounded-xl border p-3 transition-all duration-150',
                    'cursor-pointer' => !$removeMode,
                    'cursor-not-allowed opacity-40' => $removeMode,
                    'border-error bg-error/10 ring-1 ring-error/40 shadow-sm' => $resetGroups,
                    'border-base-300 bg-base-100 hover:border-error/50 hover:bg-error/5' => !$resetGroups && !$removeMode,
                ])>
                    <div class="flex items-center justify-between gap-2">
                        <span class="flex items-center gap-2 text-sm font-semibold text-error">
                            <i class="fa-solid fa-rotate"></i>
                            Mode réinitialisation
                        </span>
                        <input type="checkbox" wire:model.live="resetGroups"
                            class="toggle toggle-xs toggle-error" @disabled($removeMode) />
                    </div>
                    <p class="text-xs leading-snug text-base-content/60">
                        Supprime tous les groupes existants puis assigne uniquement les groupes sélectionnés.
                    </p>
                </label>
            </div>
        </x-molecules.modal.section>

        {{-- Recherche + sélection rapide + liste des groupes --}}
        <x-molecules.modal.section title="Groupes" icon="fa-layer-group text-primary/70" grow scrollable>
            <x-slot:titleComplement>
                @if ($this->selectedCount > 0)
                    <span class="badge badge-primary badge-sm">{{ $this->selectedCount }}</span>
                @endif
            </x-slot:titleComplement>

            <div class="flex flex-col gap-3 h-full min-h-0">
                {{-- Barre de recherche --}}
                <div class="shrink-0">
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

                {{-- Actions de sélection rapide --}}
                <div class="flex gap-2 shrink-0">
                    <button type="button" wire:click="selectAllFiltered" class="btn btn-xs btn-outline">
                        <i class="fa-solid fa-check-double"></i>
                        Tout sélectionner
                    </button>
                    <button type="button" wire:click="deselectAll" class="btn btn-xs btn-outline"
                        @disabled(count($selectedGroups) === 0)>
                        <i class="fa-solid fa-xmark"></i>
                        Tout désélectionner
                    </button>
                </div>

                {{-- Liste des groupes --}}
                <div class="flex-1 overflow-y-auto overflow-x-hidden min-h-0 border rounded-lg bg-base-100">
                    @php
                        $membership = $this->membershipMembers;
                        // Mode retrait : on masque les groupes où aucun utilisateur
                        // ciblé n'est membre — il n'y a rien à en retirer.
                        $displayGroups = $removeMode
                            ? $this->filteredGroups->filter(fn($g) => count($membership[$g['cn']] ?? []) > 0)
                            : $this->filteredGroups;
                        // Tri par appartenance décroissante : tous membres → certains
                        // → aucun. Tri stable secondaire sur le nom pour l'ordre
                        // interne à chaque palier.
                        $displayGroups = $displayGroups
                            ->sortBy(fn($g) => mb_strtolower($g['name']))
                            ->sortByDesc(fn($g) => count($membership[$g['cn']] ?? []))
                            ->values();
                    @endphp
                    @if ($isLoading && !$groupsLoaded)
                        <div class="flex items-center justify-center h-32">
                            <span class="loading loading-spinner loading-lg"></span>
                        </div>
                    @elseif (count($displayGroups) > 0)
                        <div class="divide-y divide-base-200">
                            @foreach ($displayGroups as $group)
                                @php
                                    $members = $membership[$group['cn']] ?? [];
                                    $memberCount = count($members);
                                    $total = $this->usersCount;
                                    // La mise en évidence de l'appartenance n'a de sens
                                    // que hors réinitialisation (en reset, les cases
                                    // décrivent l'état cible, pas l'existant).
                                    $highlight = !$resetGroups;
                                    $allMembers = $highlight && $total > 0 && $memberCount >= $total;
                                    $someMembers = $highlight && $memberCount > 0 && $memberCount < $total;
                                    $isSelected = in_array($group['cn'], $selectedGroups);
                                    // Mode ajout : tous déjà membres → coché + verrouillé
                                    // (évite de croire que décocher les retirerait).
                                    $lockChecked = $allMembers && !$removeMode;
                                    // Trait « indéterminé » quand seuls certains sont membres.
                                    $indeterminate = $someMembers && !$isSelected && !$lockChecked;
                                @endphp

                                <label wire:key="group-{{ $group['cn'] }}-{{ $removeMode ? 'rm' : 'add' }}-{{ $isSelected ? 'sel' : 'unsel' }}-{{ $lockChecked ? 'lock' : ($indeterminate ? 'ind' : 'free') }}"
                                    class="flex items-center gap-3 p-3 transition-colors {{ $lockChecked ? 'opacity-70 cursor-default' : 'cursor-pointer hover:bg-base-200' }}">
                                    <input type="checkbox"
                                        @if ($lockChecked)
                                            checked disabled
                                        @else
                                            wire:click="toggleGroup('{{ $group['cn'] }}')"
                                            @checked($isSelected)
                                            x-init="$el.indeterminate = @js($indeterminate)"
                                        @endif
                                        class="checkbox checkbox-sm {{ $removeMode ? 'checkbox-warning' : 'checkbox-primary' }}" />
                                    <div class="flex-1 min-w-0">
                                        <div class="font-medium truncate">{{ $group['name'] }}</div>
                                        @if (!empty($group['description']))
                                            <div class="text-xs text-base-content/60 truncate">
                                                {{ $group['description'] }}
                                            </div>
                                        @endif
                                    </div>
                                    @if ($allMembers || $someMembers)
                                        {{-- Carte de membres ouverte au survol du badge.
                                             Téléportée vers <body> pour échapper à
                                             l'overflow de la liste et au containing
                                             block de la modale ; scrollable et gardée
                                             ouverte tant que le pointeur reste dessus. --}}
                                        <div class="shrink-0" x-data="{
                                            open: false,
                                            hideTimer: null,
                                            coords: { top: 0, left: 0 },
                                            reveal() {
                                                clearTimeout(this.hideTimer);
                                                const r = $refs.badge.getBoundingClientRect();
                                                const width = 240;
                                                let left = r.right - width;
                                                if (left < 8) left = 8;
                                                this.coords = { top: r.bottom + 6, left };
                                                this.open = true;
                                            },
                                            scheduleHide() { this.hideTimer = setTimeout(() => { this.open = false; }, 150); },
                                            keepOpen() { clearTimeout(this.hideTimer); },
                                        }" @mouseenter="reveal()" @mouseleave="scheduleHide()">
                                            <span x-ref="badge"
                                                class="badge badge-ghost badge-sm cursor-help">
                                                {{ $allMembers ? 'Tous membres' : $memberCount . '/' . $total . ' membres' }}
                                            </span>

                                            <template x-teleport="body">
                                                <div x-show="open" x-cloak x-transition.opacity
                                                    @mouseenter="keepOpen()" @mouseleave="scheduleHide()"
                                                    :style="`top:${coords.top}px; left:${coords.left}px; width:240px;`"
                                                    class="fixed z-[9999] rounded-lg border border-base-300 bg-base-100 shadow-xl p-3">
                                                    <div class="text-xs font-semibold text-base-content/60 mb-2">
                                                        Membres du groupe ({{ $memberCount }})
                                                    </div>
                                                    <div class="max-h-56 overflow-y-auto space-y-1 pr-1">
                                                        @foreach ($members as $member)
                                                            <div class="text-sm truncate">{{ $member }}</div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    @endif
                                    @if ($isSelected && !$lockChecked)
                                        <i class="fa-solid fa-check {{ $removeMode ? 'text-warning' : 'text-primary' }}"></i>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                    @else
                        <div class="flex flex-col items-center justify-center h-32 text-base-content/60 text-center px-4">
                            <i class="fa-solid fa-folder-open text-3xl mb-2"></i>
                            <span>
                                @if ($search)
                                    Aucun groupe trouvé
                                @elseif ($removeMode)
                                    Aucun groupe commun à retirer
                                @else
                                    Aucun groupe disponible
                                @endif
                            </span>
                        </div>
                    @endif
                </div>
            </div>
        </x-molecules.modal.section>

        <x-slot:footer>
            <button type="button" class="btn btn-ghost" wire:click="close">
                Annuler
            </button>
            <button type="button" wire:click="applyChanges" wire:loading.attr="disabled"
                class="btn {{ $removeMode && !$resetGroups ? 'btn-warning' : ($resetGroups ? 'btn-error' : 'btn-primary') }}"
                @disabled(count($targetUsers) === 0 || (count($selectedGroups) === 0 && !$resetGroups))>
                <span wire:loading wire:target="applyChanges" class="loading loading-spinner loading-sm"></span>
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
        </x-slot:footer>
    </x-molecules.modal>
</div>
