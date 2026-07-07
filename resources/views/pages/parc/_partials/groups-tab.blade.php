<!-- Onglet Groupes -->
<div class="flex-1 min-h-0 flex flex-col gap-4">
    <!-- Filtres -->
    <div class="flex-shrink-0 card bg-base-100 shadow-sm">
        <div class="card-body py-3">
            <div class="flex flex-wrap items-center gap-3">
                <!-- Recherche -->
                <div class="form-control">
                    <input type="text" wire:model.live.debounce.300ms="groupSearch"
                        placeholder="Rechercher un groupe..." class="input input-bordered w-48" />
                </div>

                <!-- Filtre type de groupes : tous / physiques / logiques -->
                <div class="flex items-center gap-2">
                    <label class="label-text text-xs">Groupes</label>
                    <div class="join">
                        <button type="button" class="join-item btn btn-sm {{ $groupTypeFilter === 'all' ? 'btn-active' : '' }}"
                            wire:click="$set('groupTypeFilter', 'all')">
                            <i class="fa-solid fa-layer-group text-xs"></i>
                            Tous
                        </button>
                        <button type="button" class="join-item btn btn-sm {{ $groupTypeFilter === 'physical' ? 'btn-active' : '' }}"
                            wire:click="$set('groupTypeFilter', 'physical')">
                            <i class="fa-solid fa-building text-xs"></i>
                            Physiques
                        </button>
                        <button type="button" class="join-item btn btn-sm {{ $groupTypeFilter === 'logical' ? 'btn-active' : '' }}"
                            wire:click="$set('groupTypeFilter', 'logical')">
                            <i class="fa-solid fa-network-wired text-xs"></i>
                            Logiques
                        </button>
                    </div>
                </div>

                <!-- Bouton reset -->
                @if ($groupSearch)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="resetGroupFilters">
                        <i class="fa-solid fa-eraser"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Tableau des groupes -->
    <div class="card bg-base-100 shadow-sm flex-1 min-h-0 flex flex-col overflow-hidden">
        @if ($this->groups->isEmpty())
            <div class="card-body flex flex-col items-center justify-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-folder-tree"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Aucun groupe trouvé</h3>
                <p class="text-base-content/60 text-center max-w-md mb-6">
                    @if ($groupSearch)
                        Aucun groupe ne correspond aux critères de recherche.
                    @else
                        Aucun groupe n'est configuré. Créez votre premier groupe ou importez les parcs legacy.
                    @endif
                </p>
                <div class="flex gap-2">
                    @if ($groupSearch)
                        <button type="button" class="btn btn-outline" wire:click="resetGroupFilters">
                            <i class="fa-solid fa-eraser"></i>
                            Effacer les filtres
                        </button>
                    @endif
                    <button type="button" class="btn btn-primary" wire:click="$dispatch('open-group-modal')">
                        <i class="fa-solid fa-plus"></i>
                        Créer un groupe
                    </button>
                </div>
            </div>
        @else
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <input type="checkbox" class="checkbox"
                                    @click="$wire.selectedGroups = $wire.selectedGroups.length === {{ $this->groups->total() }} ? [] : {{ json_encode($this->groups->pluck('id')->toArray()) }}">
                            </th>
                            <th>Type</th>
                            <th>Nom</th>
                            <th>Machines</th>
                            <th>Parent</th>
                            <th>Sync AD</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->groups as $group)
                            <tr class="hover cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.parc.groups.show', $group->id) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox" class="checkbox" wire:model.live="selectedGroups"
                                            value="{{ $group->id }}">
                                    </label>
                                </td>
                                <td>
                                    @if ($group->is_physical)
                                        <span class="badge badge-info badge-sm">
                                            <i class="fa-solid fa-building mr-1"></i>
                                            Physique
                                        </span>
                                    @else
                                        <span class="badge badge-warning badge-sm">
                                            <i class="fa-solid fa-network-wired mr-1"></i>
                                            Logique
                                        </span>
                                    @endif
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                                            <i class="fa-solid fa-layer-group text-primary text-sm"></i>
                                        </div>
                                        <div>
                                            <div class="font-bold flex items-center gap-2">
                                                {{ $group->display_name_or_name }}
                                                @if ($group->isLocked())
                                                    <span class="tooltip" data-tip="{{ $group->getLockDescription() }}">
                                                        <i class="fa-solid fa-lock text-warning text-xs"></i>
                                                    </span>
                                                @endif
                                            </div>
                                            @if ($group->display_name && $group->display_name !== $group->name)
                                                <div class="text-xs text-base-content/50 font-mono">
                                                    {{ $group->name }}
                                                </div>
                                            @endif
                                            @if ($group->description)
                                                <div class="text-xs text-base-content/50 truncate max-w-xs">
                                                    {{ $group->description }}
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-ghost">
                                        {{ $group->members_count }}
                                    </span>
                                </td>
                                <td>
                                    @if ($group->parent)
                                        <span class="text-sm">{{ $group->parent->display_name_or_name }}</span>
                                    @else
                                        <span class="text-base-content/50">-</span>
                                    @endif
                                </td>
                                <td>
                                    @if ($group->isSyncedWithAd())
                                        <span class="badge badge-success badge-sm">
                                            <i class="fa-solid fa-check mr-1"></i>
                                            Synchronisé
                                        </span>
                                    @else
                                        <span class="badge badge-warning badge-sm">
                                            <i class="fa-solid fa-clock mr-1"></i>
                                            En attente
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($this->groups instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <x-molecules.pagination :paginator="$this->groups" :allowedPerPage="$allowedPerPage" perPageModel="groupsPerPage"
                    itemLabel="groupe" itemLabelPlural="groupes" />
            @endif
        @endif
    </div>

    <!-- Actions groupées -->
    @if (count($selectedGroups) > 0)
        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
            <div class="card bg-base-100 shadow-xl border border-base-300">
                <div class="card-body py-3 px-4 flex-row items-center gap-4">
                    <span class="text-sm font-medium">
                        {{ count($selectedGroups) }} groupe(s) sélectionné(s)
                    </span>
                    <div class="divider divider-horizontal m-0"></div>
                    <div class="dropdown dropdown-top">
                        <label tabindex="0" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-cog"></i>
                            Actions
                            <i class="fa-solid fa-chevron-up ml-1"></i>
                        </label>
                        <ul tabindex="0"
                            class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-56 border border-base-300 mb-2">
                            <li>
                                <button type="button" wire:click="syncGroupsWithAd">
                                    <i class="fa-solid fa-sync text-info"></i>
                                    Synchroniser avec AD
                                </button>
                            </li>
                            <li>
                                <button type="button" wire:click="mergeGroups">
                                    <i class="fa-solid fa-object-group"></i>
                                    Fusionner
                                </button>
                            </li>
                            <li>
                                <details>
                                    <summary>
                                        <i class="fa-solid fa-laptop-house"></i>
                                        Définir le profil des postes(nommade, fixe, etc.)
                                    </summary>
                                    <ul>
                                        @foreach (\App\Enums\WorkstationEnvironment::cases() as $env)
                                            <li>
                                                <button type="button"
                                                    wire:click="setGroupsEnvironment('{{ $env->value }}')">
                                                    {{ $env->shortLabel() }}
                                                </button>
                                            </li>
                                        @endforeach
                                        <li>
                                            <button type="button" class="text-base-content/70"
                                                wire:click="setGroupsEnvironment('')">
                                                Non déclaré
                                            </button>
                                        </li>
                                    </ul>
                                </details>
                            </li>
                            <div class="divider my-1"></div>
                            <li>
                                <button type="button" class="text-error" wire:click="deleteGroups"
                                    wire:confirm="Êtes-vous sûr de vouloir supprimer ces groupes ?">
                                    <i class="fa-solid fa-trash"></i>
                                    Supprimer
                                </button>
                            </li>
                        </ul>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('selectedGroups', [])">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
