<!-- Onglet Postes -->
<div class="h-full flex flex-col gap-4">
    {{-- Vérification synchronisation AD/SQL des postes --}}
    <div class="flex-shrink-0">
        <livewire:components::molecules.workstation-sync-status />
    </div>

    <!-- Statistiques -->
    @include('pages.parc._partials.stats-cards')

    <!-- Filtres -->
    <x-molecules.filter-bar reset="resetMachineFilters"
        :reset-disabled="!$machineSearch && !$osFilter && !$groupFilter && !$cardFilter && !$presenceFilter">
        <div class="flex-1 min-w-[200px]">
            <x-atoms.search-input model="machineSearch" placeholder="Rechercher un poste..." />
        </div>

        {{-- OS et groupes : nombre variable venant de la base → dropdown. --}}
        <x-molecules.filter-select model="osFilter" :options="$availableOs" placeholder="Tous les OS"
            width="w-44" />

        <x-molecules.filter-select model="groupFilter"
            :options="$availableGroups->pluck('name', 'id')->all()" placeholder="Tous les groupes"
            width="w-52" />

        {{-- 3 options : segmenté. Migration et conformité restent pilotées par les
             tuiles cliquables de stats-cards, pas par cette barre. --}}
        <x-molecules.filter-toggle name="presenceFilter" :active="$presenceFilter" label="État"
            :options="['' => 'Tous', 'online' => 'Allumés', 'off' => 'Éteints']" />
    </x-molecules.filter-bar>

    <!-- Tableau des machines -->
    <div class="card bg-base-100 shadow-sm flex-1 min-h-0 flex flex-col overflow-hidden">
        @if ($this->machines->isEmpty())
            <div class="card-body flex flex-col items-center justify-center py-16">
                <div class="text-6xl mb-6 opacity-20">
                    <i class="fa-solid fa-computer"></i>
                </div>
                <h3 class="text-xl font-semibold mb-3">Aucun poste trouvé</h3>
                <p class="text-base-content/60 text-center max-w-md">
                    @if ($machineSearch || $osFilter || $groupFilter || $cardFilter || $presenceFilter)
                        Aucun poste ne correspond aux critères de recherche.
                    @else
                        Aucun poste n'est enregistré dans le système.
                    @endif
                </p>
                @if ($machineSearch || $osFilter || $groupFilter || $cardFilter || $presenceFilter)
                    <button type="button" class="btn btn-outline mt-4" wire:click="resetMachineFilters">
                        <i class="fa-solid fa-eraser"></i>
                        Effacer les filtres
                    </button>
                @endif
            </div>
        @else
            <div class="overflow-auto flex-1 min-h-0">
                <table class="table table-zebra table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-12">
                                {{-- Story 3.11 — exclut les postes protégés du « tout sélectionner »
                                     (non réinstallables — D10 niveau 1). --}}
                                <x-molecules.select-all-checkbox
                                    :ids="$this->machines->reject(fn($m) => $m->isProtected())->pluck('id')"
                                    model="selectedMachines" />
                            </th>
                            <th>Nom</th>
                            <th>OS</th>
                            <th>IP</th>
                            <th>Dernier rapport</th>
                            <th class="text-center">État</th>
                            {{-- Story 24.7 — colonne conformité agent (worst-status) --}}
                            <th class="text-center">Conformité</th>
                            {{-- Story 16.13bis — colonne migration SE4 → SE5 --}}
                            <th class="text-center">Migration</th>
                            <th class="text-center">Déploiement</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->machines as $machine)
                            <tr class="hover cursor-pointer"
                                onclick="if (!event.target.closest('.checkbox-cell')) window.location.href='{{ route('app.parc.machines.show', ['id' => $machine->id, 'from' => route('app.parc.index', ['tab' => 'machines'], false)]) }}'">
                                <td class="checkbox-cell p-0">
                                    <label class="flex items-center justify-center w-full h-full p-3 cursor-pointer"
                                        @if ($machine->isProtected()) title="Poste protégé — non réinstallable" @endif>
                                        <input type="checkbox" class="checkbox" wire:model.live="selectedMachines"
                                            value="{{ $machine->id }}"
                                            @disabled($machine->isProtected())
                                            @class(['opacity-40 cursor-not-allowed' => $machine->isProtected()])>
                                    </label>
                                </td>
                                <td>
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                                            <i class="fa-solid fa-computer text-primary text-sm"></i>
                                        </div>
                                        <div>
                                            <div class="font-bold">
                                                {{ $machine->name }}
                                                @if ($machine->isProtected())
                                                    <span class="badge badge-warning badge-xs align-middle ml-1"
                                                          title="Poste protégé">
                                                        <i class="fa-solid fa-lock"></i> Protégé
                                                    </span>
                                                @endif
                                            </div>
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
                                    @if ($machine->last_report_at)
                                        <span class="text-sm" title="{{ $machine->last_report_at }}">
                                            {{ $machine->last_report_at->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-base-content/50">-</span>
                                    @endif
                                </td>
                                {{-- État de présence dérivé du canal agent : extinction
                                     signalée au shutdown, sinon check-in < 2 × ttl —
                                     voir agentPresence(). --}}
                                <td class="text-center">
                                    @php $presence = $machine->agentPresence(); @endphp
                                    @if ($presence === 'online')
                                        <span class="status status-success"
                                              title="Allumé — check-in {{ $machine->agent_last_checkin_at->diffForHumans() }}"
                                              aria-label="Allumé"></span>
                                    @elseif ($presence === 'reported_off')
                                        <span class="status"
                                              title="Éteint — extinction signalée {{ $machine->agent_reported_offline_at->diffForHumans() }}"
                                              aria-label="Éteint"></span>
                                    @elseif ($presence === 'silent')
                                        {{-- Pas d'alerte : rien n'interdit qu'un poste reste
                                             éteint. On affiche un simple tiret neutre plutôt
                                             qu'un warning anxiogène. --}}
                                        <span class="text-base-content/50"
                                              title="Éteint ou injoignable — dernier check-in {{ $machine->agent_last_checkin_at->diffForHumans() }}"
                                              aria-label="Éteint ou injoignable">-</span>
                                    @else
                                        <span class="text-base-content/30" title="Présence inconnue (pas d'agent)">—</span>
                                    @endif
                                </td>
                                {{-- Story 24.7 — badge conformité agent (worst-status par
                                     poste, calculé en 1 requête agrégée pour la page —
                                     $this->machineConformity, zéro N+1). Neutre si non
                                     enrôlé (hors conformité). --}}
                                <td class="text-center">
                                    @if ($machine->isAgentEnrolled())
                                        <x-atoms.conformity-badge
                                            :status="$this->machineConformity[$machine->id] ?? 'never_reported'" />
                                    @else
                                        <span class="text-base-content/30" title="Poste non enrôlé">—</span>
                                    @endif
                                </td>
                                {{-- Story 16.13bis — badge migration SE4 → SE5. Un poste
                                     natif SE5 (enrôlé agent, jamais passé par le legacy)
                                     n'est pas concerné : tiret, pas de ❌ anxiogène. --}}
                                <td class="text-center">
                                    @if ($machine->migrated)
                                        <span class="badge badge-success badge-sm"
                                              title="Migré" aria-label="Migré">✅</span>
                                    @elseif ($machine->isAgentEnrolled())
                                        <span class="text-base-content/30"
                                              title="Non applicable — poste natif SE5">—</span>
                                    @else
                                        <span class="badge badge-ghost badge-sm"
                                              title="Non migré" aria-label="Non migré">❌</span>
                                    @endif
                                </td>
                                {{-- Déploiement applicatif via le canal natif de
                                     l'agent (AgentApplicationInventory, 27.5) :
                                     compteur installées ✓ / en échec ✗. Le canal
                                     WPKG legacy (GPO) n'alimente plus cette colonne. --}}
                                <td class="text-center">
                                    @if (($machine->installed_apps_count ?? 0) > 0 || ($machine->error_apps_count ?? 0) > 0)
                                        <span class="font-mono text-sm"
                                              title="Applications rapportées par l'agent : {{ $machine->installed_apps_count }} installée(s){{ ($machine->error_apps_count ?? 0) > 0 ? ', ' . $machine->error_apps_count . ' en échec' : '' }}">
                                            <span class="text-success">{{ $machine->installed_apps_count }} ✓</span>
                                            @if ($machine->error_apps_count > 0)
                                                <span class="text-error ml-1">{{ $machine->error_apps_count }} ✗</span>
                                            @endif
                                        </span>
                                    @else
                                        <span class="text-base-content/30"
                                              title="Aucune application rapportée par l'agent">—</span>
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
                            Actions machines
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
                            @can('computer.install')
                                <li class="menu-title px-0 pt-1"><div class="divider m-0"></div></li>
                                <li>
                                    <button type="button" class="text-error" wire:click="openReinstallModal">
                                        <i class="fa-solid fa-arrows-rotate"></i>
                                        Réinstaller la sélection
                                    </button>
                                </li>
                            @endcan
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
