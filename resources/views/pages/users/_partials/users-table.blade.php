<div class="flex-1 flex flex-col min-h-0"
    wire:key="users-table-{{ $searchPerformed ? 'results' : 'empty' }}-{{ $usersLoaded ? 'loaded' : 'loading' }}">
    @if (!$usersLoaded)
        <!-- État de chargement initial -->
        <div wire:key="users-loading" id="users-list-loading"
            class="card flex flex-col h-full justify-center items-center">
            <div class="card-body flex-col justify-center items-center flex-0 py-16">
                <span class="loading loading-spinner loading-lg text-primary mb-4"></span>
                <h3 class="text-lg font-semibold mb-2">Initialisation</h3>
                <p class="text-base-content/60 text-base">
                    Chargement des données de filtres...
                </p>
            </div>
        </div>
    @elseif (!$searchPerformed)
        <!-- État vide - invitation à rechercher -->
        <div wire:key="users-empty" id="users-list-empty" class="card flex flex-col h-full justify-center items-center">
            <div class="card-body flex-col justify-center items-center flex-0 py-16 text-center">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Recherchez des utilisateurs</h3>
                <p class="text-base-content/60 text-base max-w-md mb-6">
                    Utilisez le formulaire ci-dessus pour rechercher des utilisateurs par nom, login, rôle,
                    statut, classe ou groupe.
                </p>
                <div class="alert alert-info max-w-md">
                    <i class="fa-solid fa-lightbulb"></i>
                    <div class="text-sm text-left">
                        <p class="font-semibold mb-1">Astuce pour des recherches rapides :</p>
                        <ul class="list-disc list-inside space-y-1">
                            <li>Utilisez le champ <strong>Login</strong> pour une recherche très rapide
                                (attribut hautement indexé)</li>
                            <li>Le champ <strong>Nom/Prénom</strong> utilise également des attributs indexés
                            </li>
                            <li>Combinez plusieurs critères pour affiner vos résultats</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    @elseif ($userResults && $userResults->isEmpty())
        <!-- Aucun résultat -->
        <div wire:key="users-no-results" id="users-list-no-results"
            class="card flex flex-col h-full justify-center items-center">
            <div class="card-body flex-col justify-center items-center flex-0 py-16 text-center">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-user-slash"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Aucun utilisateur trouvé</h3>
                <p class="text-base-content/60 text-base max-w-md mb-6">
                    Aucun utilisateur ne correspond aux critères de recherche spécifiés.
                </p>
                <button type="button" class="btn btn-outline" wire:click="resetFilters">
                    <i class="fa-solid fa-eraser"></i>
                    Effacer les critères
                </button>
            </div>
        </div>
    @else
        <div wire:key="users-results" class="flex flex-col flex-1 min-h-0">
            <!-- Actions rapides -->
            <div class="flex-shrink-0 flex justify-between items-center mb-4 mt-3 h-9">
                <div class="flex items-center gap-4">
                    <span class="text-base-content/70 text-xs">{{ $userResults?->total() ?? 0 }} utilisateur(s)
                        trouvé(s)</span>
                </div>

                <!-- Actions groupées -->
                <div class="flex items-center gap-4" x-show="$wire.selectedUsers.length > 0" x-transition>
                    <span class="text-base-content/70 text-xs">
                        <span x-text="$wire.selectedUsers.length"></span> utilisateur(s) sélectionné(s)
                    </span>
                    <!-- Dropdown des actions groupées -->
                    <div class="dropdown dropdown-end">
                        <label tabindex="0" class="btn btn-primary btn-sm">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                            </svg>
                            Actions
                            <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 9l-7 7-7-7">
                                </path>
                            </svg>
                        </label>
                        <div tabindex="0"
                            class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-80 border border-base-300">
                            <div class="menu-title">
                                <span class="text-sm font-medium">Actions groupées</span>
                                <span class="text-xs text-base-content/60 ml-2">(<span
                                        x-text="$wire.selectedUsers.length"></span>
                                    sélectionné(s))</span>
                            </div>

                            <!-- Gestion des groupes -->
                            <div class="divider">Groupes & Droits</div>
                            <li>
                                <button type="button"
                                    class="text-sm cursor-pointer flex items-center gap-2 p-2 hover:bg-base-200 rounded"
                                    @click="Livewire.dispatch('open-groups-drawer', { users: $wire.selectedUsers }); document.activeElement.blur();">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                                        </path>
                                    </svg>
                                    Gérer les groupes
                                </button>
                            </li>
                            <li>
                                <button type="button"
                                    class="text-sm cursor-pointer flex items-center gap-2 p-2 hover:bg-base-200 rounded text-primary"
                                    @click="Livewire.dispatch('open-rights-drawer', { users: $wire.selectedUsers }); document.activeElement.blur();">
                                    <i class="fa-solid fa-shield-halved w-4 h-4 flex items-center justify-center"></i>
                                    Gérer les droits
                                </button>
                            </li>
                            <li>
                                <button type="button"
                                    class="text-sm cursor-pointer flex items-center gap-2 p-2 hover:bg-base-200 rounded text-primary"
                                    @click="Livewire.dispatch('open-delegation-modal', { users: $wire.selectedUsers }); document.activeElement.blur();">
                                    <i class="fa-solid fa-building w-4 h-4 flex items-center justify-center"></i>
                                    Déléguer un droit sur une salle
                                </button>
                            </li>

                            <!-- Gestion du statut -->
                            <div class="divider">Statut</div>
                            <li>
                                <button type="button"
                                    class="text-sm cursor-pointer flex items-center gap-2 p-2 hover:bg-base-200 rounded text-success"
                                    wire:click="bulkEnable">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                    Activer les comptes
                                </button>
                            </li>
                            <li>
                                <button type="button"
                                    class="text-sm cursor-pointer flex items-center gap-2 p-2 hover:bg-base-200 rounded text-warning"
                                    wire:click="bulkDisable">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636">
                                        </path>
                                    </svg>
                                    Désactiver les comptes
                                </button>
                            </li>
                            <li>
                                <button type="button"
                                    class="text-sm cursor-pointer flex items-center gap-2 p-2 hover:bg-base-200 rounded text-info"
                                    wire:click="bulkResetPassword">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z">
                                        </path>
                                    </svg>
                                    Réinitialiser les mots de passe
                                </button>
                            </li>

                            <!-- Actions finales -->
                            <div class="divider">Actions finales</div>
                            <li>
                                <button type="button"
                                    class="text-sm cursor-pointer flex items-center gap-2 p-2 hover:bg-base-200 rounded"
                                    wire:click="bulkExport">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                                        </path>
                                    </svg>
                                    Exporter
                                </button>
                            </li>
                            <li>
                                <button type="button"
                                    class="text-sm cursor-pointer flex items-center gap-2 p-2 hover:bg-base-200 rounded text-error"
                                    wire:click="bulkDelete">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                                        </path>
                                    </svg>
                                    Supprimer
                                </button>
                            </li>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tableau des utilisateurs -->
            <div class="card flex-1 min-h-0 flex flex-col overflow-hidden">
                <x-organisms.data-table
                    colgroup="<colgroup><col style='width: 3rem'><col style='width: 24%'><col style='width: 22%'><col style='width: 150px'><col style='width: 98px'><col style='width: auto'></colgroup>">
                    <x-slot:header>
                        <th>
                            <label>
                                <input type="checkbox" class="checkbox" wire:click="toggleSelectAll"
                                    @checked($selectAll)>
                            </label>
                        </th>
                        <th>Utilisateur</th>
                        <th>Login</th>
                        <th>
                            <div class="flex items-center gap-2">
                                <span>Rôle</span>
                                <button type="button" class="btn btn-ghost btn-xs"
                                    @click="showRolePopover = !showRolePopover; showStatusPopover = false; showGroupPopover = false">
                                    <i class="fa-solid fa-filter text-xs"></i>
                                </button>
                            </div>
                            <!-- Popover Rôle -->
                            <div x-show="showRolePopover" x-transition @click.away="showRolePopover = false"
                                class="absolute z-50 mt-2 bg-base-100 border border-base-300 rounded-lg shadow-lg p-3 min-w-48">
                                <div class="space-y-2">
                                    @foreach ($filters['role'] as $value => $label)
                                        @if ($value !== 'all')
                                            <label
                                                class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded">
                                                <input type="checkbox" class="checkbox checkbox-sm"
                                                    wire:model.live="role" value="{{ $value }}">
                                                <span class="text-sm">{{ $label }}</span>
                                            </label>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </th>
                        <th>
                            <div class="flex items-center gap-2">
                                <span>Statut</span>
                                <button type="button" class="btn btn-ghost btn-xs"
                                    @click="showStatusPopover = !showStatusPopover; showRolePopover = false; showGroupPopover = false">
                                    <i class="fa-solid fa-filter text-xs"></i>
                                </button>
                            </div>
                            <!-- Popover Statut -->
                            <div x-show="showStatusPopover" x-transition @click.away="showStatusPopover = false"
                                class="absolute z-50 mt-2 bg-base-100 border border-base-300 rounded-lg shadow-lg p-3 min-w-48">
                                <div class="space-y-2">
                                    @foreach ($filters['status'] as $value => $label)
                                        @if ($value !== 'all')
                                            <label
                                                class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded">
                                                <input type="checkbox" class="checkbox checkbox-sm"
                                                    wire:model.live="status" value="{{ $value }}">
                                                <span class="text-sm">{{ $label }}</span>
                                            </label>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        </th>
                        <th>
                            <div class="flex items-center gap-2">
                                <span>Groupes</span>
                                <button type="button" class="btn btn-ghost btn-xs"
                                    @click="showGroupPopover = !showGroupPopover; showRolePopover = false; showStatusPopover = false">
                                    <i class="fa-solid fa-filter text-xs"></i>
                                </button>
                            </div>
                            <!-- Popover Groupes -->
                            <div x-show="showGroupPopover" x-transition @click.away="showGroupPopover = false"
                                class="absolute z-50 mt-2 bg-base-100 border border-base-300 rounded-lg shadow-lg p-3 min-w-64 max-h-96 overflow-y-auto">
                                <div class="space-y-2">
                                    <div class="sticky top-0 bg-base-100 pb-2 mb-2 border-b border-base-300">
                                        <input type="text" x-model="groupSearchTerm"
                                            placeholder="Rechercher un groupe..."
                                            class="input input-sm input-bordered w-full" />
                                    </div>
                                    <div id="groupList">
                                        @foreach ($availableGroups as $group)
                                            <label
                                                class="flex items-center gap-2 cursor-pointer hover:bg-base-200 p-1 rounded group-item"
                                                x-show="groupSearchTerm === '' || '{{ strtolower($group['name']) }}'.includes(groupSearchTerm.toLowerCase())">
                                                <input type="checkbox" class="checkbox checkbox-sm"
                                                    wire:model.live="group" value="{{ $group['cn'] }}">
                                                <div class="flex-1">
                                                    <span class="text-sm font-medium">{{ $group['name'] }}</span>
                                                    @if (!empty($group['description']))
                                                        <div class="text-xs text-base-content/60">
                                                            {{ $group['description'] }}
                                                        </div>
                                                    @endif
                                                </div>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </th>
                    </x-slot:header>
                    @foreach ($userResults?->items ?? [] as $user)
                        <tr class="cursor-pointer"
                            onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.user.show', $user->login) }}'">
                            <td class="checkbox-cell p-0">
                                <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                    <input type="checkbox" class="checkbox user-checkbox"
                                        wire:model.live="selectedUsers" value="{{ $user->login }}">
                                </label>
                            </td>
                            <td>
                                <div class="flex items-center gap-3">
                                    <x-atoms.avatar-placeholder :initials="strtoupper(substr($user->fullname ?? $user->login, 0, 1)) .
                                        strtoupper(substr(explode(' ', $user->fullname ?? '')[1] ?? '', 0, 1))" size="w-8" />
                                    <div class="font-bold">{{ $user->fullname ?: $user->login }}</div>
                                </div>
                            </td>
                            <td>
                                <span class="font-mono text-sm">{{ $user->login }}</span>
                            </td>
                            <td>
                                @if ($user->role === 'administratifs')
                                    <div class="badge badge-warning">Administrateur</div>
                                @elseif($user->role === 'profs')
                                    <div class="badge badge-success">Professeur</div>
                                @elseif($user->role === 'eleves')
                                    <div class="badge badge-info">Élève</div>
                                @elseif($user->role === 'autre')
                                    <div class="badge badge-secondary">Autre</div>
                                @else
                                    <div class="badge badge-ghost">Inconnu</div>
                                @endif
                            </td>
                            <td>
                                @if ($user->isActiveUser ?? false)
                                    <div class="badge badge-success">Actif</div>
                                @else
                                    <div class="badge badge-error">Inactif</div>
                                @endif
                            </td>
                            <td>
                                @php
                                    $userGroups = $user->groups ?? [];
                                    $groupCount = count($userGroups);
                                    $displayGroups = array_slice($userGroups, 0, 2);
                                @endphp

                                @if ($groupCount > 0)
                                    <div class="flex flex-wrap gap-1 items-center">
                                        @foreach ($displayGroups as $group)
                                            <span class="badge badge-outline badge-sm">{{ $group }}</span>
                                        @endforeach

                                        @if ($groupCount > 2)
                                            <div class="dropdown dropdown-end">
                                                <label tabindex="0"
                                                    class="badge badge-primary badge-sm cursor-pointer">
                                                    +{{ $groupCount - 2 }}
                                                </label>
                                                <div tabindex="0"
                                                    class="dropdown-content z-[1] card card-compact w-64 p-2 shadow bg-base-100 border border-base-300">
                                                    <div class="card-body">
                                                        <h3 class="font-bold text-sm mb-2">Tous les groupes
                                                            ({{ $groupCount }})
                                                        </h3>
                                                        <div class="flex flex-wrap gap-1 max-h-48 overflow-y-auto">
                                                            @foreach ($userGroups as $group)
                                                                <span
                                                                    class="badge badge-outline badge-sm">{{ $group }}</span>
                                                            @endforeach
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <span class="text-sm text-base-content/50">Aucun groupe</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-organisms.data-table>

                <!-- Pagination -->
                @if ($userResults && $userResults->pagination)
                    <x-molecules.pagination :currentPage="$userResults->pagination->currentPage" :lastPage="$userResults->pagination->lastPage" :total="$userResults->pagination->total" :from="$userResults->pagination->from"
                        :to="$userResults->pagination->to" :perPage="$perPage" :allowedPerPage="$allowedPerPage" onPageChange="goToPage"
                        perPageModel="perPage" itemLabel="utilisateur" itemLabelPlural="utilisateurs" />
                @endif
            </div>
    @endif
</div>
