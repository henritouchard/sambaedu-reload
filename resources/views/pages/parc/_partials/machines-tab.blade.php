<!-- Onglet Postes -->
<div class="h-full flex flex-col gap-4">
    {{-- Vérification synchronisation AD/SQL des postes --}}
    <div class="flex-shrink-0">
        <livewire:components::molecules.workstation-sync-status />
    </div>

    <!-- Statistiques -->
    @include('pages.parc._partials.stats-cards')

    <!-- Filtres -->
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body py-3">
            <div class="flex flex-wrap items-center gap-3">
                <div class="form-control flex-1 min-w-[200px]">
                    <input type="text" wire:model.live.debounce.300ms="machineSearch"
                        placeholder="Rechercher un poste..." class="input input-bordered w-full" />
                </div>
                <div class="form-control w-40">
                    <select wire:model.live="osFilter" class="select select-bordered">
                        <option value="">Tous les OS</option>
                        @foreach ($availableOs as $os)
                            <option value="{{ $os }}">{{ $os }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-control w-48">
                    <select wire:model.live="groupFilter" class="select select-bordered">
                        <option value="">Tous les groupes</option>
                        @foreach ($availableGroups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}</option>
                        @endforeach
                    </select>
                </div>
                {{-- Story 16.13bis — filtre par statut de migration SE4 → SE5 --}}
                <div class="form-control w-48">
                    <select wire:model.live="migrationFilter" class="select select-bordered" aria-label="Statut migration">
                        <option value="">Migration : tous</option>
                        <option value="migrated">Migrés</option>
                        <option value="not-migrated">Non migrés</option>
                    </select>
                </div>
                @if ($machineSearch || $osFilter || $groupFilter || $migrationFilter)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="resetMachineFilters">
                        <i class="fa-solid fa-eraser"></i>
                    </button>
                @endif
            </div>
        </div>
    </div>

    <!-- Tableau des machines -->
    <div class="card bg-base-100 shadow-sm flex-1 min-h-0">
        @if ($this->machines->isEmpty())
            <div class="card-body flex flex-col items-center justify-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-computer"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Aucun poste trouvé</h3>
                <p class="text-base-content/60 text-center max-w-md">
                    @if ($machineSearch || $osFilter || $groupFilter || $migrationFilter)
                        Aucun poste ne correspond aux critères de recherche.
                    @else
                        Aucun poste n'est enregistré dans le système.
                    @endif
                </p>
                @if ($machineSearch || $osFilter || $groupFilter || $migrationFilter)
                    <button type="button" class="btn btn-outline mt-4" wire:click="resetMachineFilters">
                        <i class="fa-solid fa-eraser"></i>
                        Effacer les filtres
                    </button>
                @endif
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th class="w-12">
                                <input type="checkbox" class="checkbox" wire:model.live="selectAllMachines"
                                    @click="$wire.selectedMachines = $wire.selectedMachines.length === {{ $this->machines->total() }} ? [] : {{ json_encode($this->machines->pluck('id')->toArray()) }}">
                            </th>
                            <th>Nom</th>
                            <th>OS</th>
                            <th>IP</th>
                            <th>Dernier rapport</th>
                            <th>Statut</th>
                            {{-- Story 16.13bis — colonne migration SE4 → SE5 --}}
                            <th class="text-center">Migration</th>
                            <th class="text-center">Déploiement</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->machines as $machine)
                            <tr class="hover cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.parc.machines.show', $machine->id) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                        <input type="checkbox" class="checkbox" wire:model.live="selectedMachines"
                                            value="{{ $machine->id }}">
                                    </label>
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                                            <i class="fa-solid fa-computer text-primary text-sm"></i>
                                        </div>
                                        <div>
                                            <div class="font-bold">{{ $machine->name }}</div>
                                            @if ($machine->ad_guid)
                                                <div class="text-xs text-base-content/50 font-mono">
                                                    {{ Str::limit($machine->ad_guid, 8) }}...
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-ghost">{{ $machine->os }}</span>
                                </td>
                                <td class="font-mono text-sm">{{ $machine->ip }}</td>
                                <td>
                                    @if ($machine->date_rapport_poste)
                                        <span class="text-sm" title="{{ $machine->date_rapport_poste }}">
                                            {{ $machine->date_rapport_poste->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-base-content/50">-</span>
                                    @endif
                                </td>
                                <td>
                                    @php
                                        $statusClass = match ($machine->status) {
                                            1 => 'badge-success',
                                            2 => 'badge-warning',
                                            default => 'badge-error',
                                        };
                                    @endphp
                                    <span class="badge {{ $statusClass }} badge-sm">
                                        {{ $machine->getStatusLabel() }}
                                    </span>
                                </td>
                                {{-- Story 16.13bis — badge migration ✅/❌ --}}
                                <td class="text-center">
                                    @php
                                        $isMigrated = $machine->migrated;
                                        $migrationBadge = $isMigrated
                                            ? ['class' => 'badge-success', 'icon' => '✅', 'label' => 'Migré']
                                            : ['class' => 'badge-ghost', 'icon' => '❌', 'label' => 'Non migré'];
                                    @endphp
                                    <span class="badge {{ $migrationBadge['class'] }} badge-sm"
                                          title="{{ $migrationBadge['label'] }}"
                                          aria-label="{{ $migrationBadge['label'] }}">
                                        {{ $migrationBadge['icon'] }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    @if (($machine->installed_apps_count ?? 0) > 0 || ($machine->error_apps_count ?? 0) > 0)
                                        <span class="font-mono text-sm">
                                            <span class="text-success">{{ $machine->installed_apps_count }} ✓</span>
                                            @if ($machine->error_apps_count > 0)
                                                <span class="text-error ml-1">{{ $machine->error_apps_count }} ✗</span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-base-content/30">—</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            @if ($this->machines instanceof \Illuminate\Pagination\LengthAwarePaginator)
                <x-molecules.pagination :paginator="$this->machines" :allowedPerPage="$allowedPerPage" perPageModel="machinesPerPage"
                    itemLabel="poste" itemLabelPlural="postes" />
            @endif
        @endif
    </div>

    <!-- Actions groupées -->
    @if (count($selectedMachines) > 0)
        <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
            <div class="card bg-base-100 shadow-xl border border-base-300">
                <div class="card-body py-3 px-4 flex-row items-center gap-4">
                    <span class="text-sm font-medium">
                        {{ count($selectedMachines) }} poste(s) sélectionné(s)
                    </span>
                    <div class="divider divider-horizontal m-0"></div>
                    <div class="dropdown dropdown-top">
                        <label tabindex="0" class="btn btn-primary btn-sm">
                            <i class="fa-solid fa-bolt"></i>
                            Actions machine
                            <i class="fa-solid fa-chevron-up ml-1"></i>
                        </label>
                        <ul tabindex="0"
                            class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-60 border border-base-300 mb-2">
                            @foreach ($this->machineActions as $action)
                                @php
                                    $confirmMessage = match ($action->key) {
                                        'shutdown' => 'Confirmer l\'extinction des machines sélectionnées ?',
                                        'restart' => 'Confirmer le redémarrage des machines sélectionnées ?',
                                        default => null,
                                    };
                                @endphp
                                <li>
                                    <button type="button"
                                        wire:click="executeSelectedMachinesAction('{{ $action->key }}')"
                                        @if ($confirmMessage) wire:confirm="{{ $confirmMessage }}" @endif>
                                        <i class="{{ $action->icon }}"></i>
                                        {{ $action->label }}
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                    <button type="button" class="btn btn-primary btn-sm" wire:click="$set('showGroupModal', true)">
                        <i class="fa-solid fa-folder-plus"></i>
                        Ajouter aux groupes
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="$set('selectedMachines', [])">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Modale de sélection de groupe -->
    @teleport('body')
        <dialog class="modal" x-data="{ open: @entangle('showGroupModal') }" :class="{ 'modal-open': open }" x-cloak>
            <div class="modal-box w-11/12 max-w-lg">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold flex items-center gap-2">
                        <i class="fa-solid fa-folder-plus text-primary"></i>
                        Ajouter aux groupes
                    </h3>
                    <button type="button" wire:click="$set('showGroupModal', false)"
                        class="btn btn-sm btn-circle btn-ghost">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <p class="text-sm text-base-content/60 mb-4">
                    Sélectionnez le groupe auquel ajouter les
                    <span class="font-semibold">{{ count($selectedMachines) }} poste(s)</span> sélectionné(s).
                </p>
                <div class="space-y-1 max-h-[50vh] overflow-y-auto">
                    @foreach ($availableGroups as $group)
                        <button type="button"
                            class="flex items-center gap-3 w-full p-3 rounded-xl hover:bg-base-200 transition-colors text-left"
                            wire:click="addMachinesToGroup({{ $group->id }}); $set('showGroupModal', false)">
                            <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center flex-shrink-0">
                                <i class="fa-solid fa-folder text-primary text-sm"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-sm">{{ $group->display_name_or_name }}</div>
                                @if ($group->description)
                                    <div class="text-xs text-base-content/50 truncate">{{ $group->description }}</div>
                                @endif
                            </div>
                            <span
                                class="badge badge-ghost badge-sm">{{ $group->members_count }}</span>
                        </button>
                    @endforeach
                </div>
                <div class="modal-action">
                    <button type="button" class="btn btn-ghost" wire:click="$set('showGroupModal', false)">
                        Annuler
                    </button>
                </div>
            </div>
            <form method="dialog" class="modal-backdrop">
                <button wire:click="$set('showGroupModal', false)">close</button>
            </form>
        </dialog>
    @endteleport
</div>
