<!-- Onglets Postes / Imprimantes -->
<div x-data="{ tab: 'postes' }">
    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            {{-- Onglets SECONDAIRES — rendu « cartes » aligné sur x-molecules.secondary-tabs.
                 Le composant est piloté par wire:click ; ici l'état reste local à Alpine
                 (bascule instantanée, aucun aller-retour serveur), d'où le balisage inline. --}}
            <div role="tablist" class="flex flex-wrap items-stretch gap-2 xl:gap-3 mb-4">
                @foreach ([
                    ['key' => 'postes', 'label' => 'Postes', 'icon' => 'fa-solid fa-computer', 'badge' => $group->members->count()],
                    ['key' => 'imprimantes', 'label' => 'Imprimantes', 'icon' => 'fa-solid fa-print', 'badge' => $group->printers->count()],
                ] as $secondaryTab)
                    <button type="button" role="tab"
                        :aria-selected="tab === '{{ $secondaryTab['key'] }}' ? 'true' : 'false'"
                        @click="tab = '{{ $secondaryTab['key'] }}'"
                        data-testid="secondary-tab-{{ $secondaryTab['key'] }}"
                        class="card flex-1 min-w-[9rem] bg-base-100 border shadow-sm text-left
                            cursor-pointer transition duration-150 focus:outline-none
                            focus-visible:ring-2 focus-visible:ring-primary/50"
                        :class="tab === '{{ $secondaryTab['key'] }}'
                            ? 'border-primary/30 ring-2 ring-primary ring-offset-1 ring-offset-base-100 bg-primary/5'
                            : 'border-base-300 hover:shadow-md hover:-translate-y-0.5'">
                        <div class="card-body flex-row items-center gap-3 py-2.5 px-3">
                            <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                                :class="tab === '{{ $secondaryTab['key'] }}' ? 'bg-primary/15' : 'bg-base-200'">
                                <i class="{{ $secondaryTab['icon'] }}"
                                    :class="tab === '{{ $secondaryTab['key'] }}' ? 'text-primary' : 'text-base-content/50'"></i>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold leading-tight truncate"
                                    :class="tab === '{{ $secondaryTab['key'] }}' ? 'text-primary' : 'text-base-content'">
                                    {{ $secondaryTab['label'] }}
                                </div>
                            </div>
                            @if ($secondaryTab['badge'])
                                <span class="badge badge-sm shrink-0"
                                    :class="tab === '{{ $secondaryTab['key'] }}' ? 'badge-primary' : 'badge-ghost'">
                                    {{ $secondaryTab['badge'] }}
                                </span>
                            @endif
                        </div>
                    </button>
                @endforeach
            </div>

            {{-- Onglet Postes --}}
            <div x-show="tab === 'postes'" x-cloak>
            <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                <h3 class="card-title text-base">
                    <i class="fa-solid fa-computer text-primary"></i>
                    Machines du groupe
                    <span class="badge badge-ghost">{{ $group->members->count() }}</span>
                </h3>
                <div class="flex items-center gap-2">
                    @if ($group->members->isNotEmpty())
                        <button type="button" class="btn btn-ghost btn-sm" wire:click="selectAllGroupMachines">
                            <i class="fa-solid fa-check-double"></i>
                            Tout sélectionner
                        </button>
                    @endif
                    <button type="button" class="btn btn-primary btn-sm" wire:click="openAddMachinesModal">
                        <i class="fa-solid fa-plus"></i>
                        Ajouter
                    </button>
                </div>
            </div>

            @teleport('body')
                <dialog class="modal" x-data="{ open: @entangle('showAddMachinesModal') }" :class="{ 'modal-open': open }" x-cloak>
                    <div class="modal-box w-11/12 max-w-2xl">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-bold flex items-center gap-2">
                                <i class="fa-solid fa-plus text-primary"></i>
                                Ajouter des machines
                            </h3>
                            <button type="button" wire:click="closeAddMachinesModal"
                                class="btn btn-sm btn-circle btn-ghost">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <p class="text-sm text-base-content/60 mb-4">
                            Sélectionnez les machines à ajouter au groupe <span
                                class="font-semibold">{{ $group->name }}</span>.
                        </p>
                        <x-molecules.machine-selector wire:model="selectedMachines" maxHeight="400px" />
                        <div class="modal-action">
                            <button type="button" class="btn btn-ghost" wire:click="closeAddMachinesModal">
                                Annuler
                            </button>
                            <button type="button" class="btn btn-primary" wire:click="addMachines"
                                wire:loading.attr="disabled">
                                <span wire:loading wire:target="addMachines"
                                    class="loading loading-spinner loading-sm"></span>
                                <i wire:loading.remove wire:target="addMachines" class="fa-solid fa-plus"></i>
                                Ajouter les machines
                            </button>
                        </div>
                    </div>
                    <form method="dialog" class="modal-backdrop">
                        <button wire:click="closeAddMachinesModal">close</button>
                    </form>
                </dialog>
            @endteleport

            @if ($group->members->isEmpty())
                <div class="flex flex-col items-center justify-center py-12 text-center">
                    <div class="text-5xl mb-4 opacity-20">
                        <i class="fa-solid fa-computer"></i>
                    </div>
                    <h4 class="text-lg font-semibold mb-2">Aucune machine</h4>
                    <p class="text-base-content/60 max-w-sm">
                        Ce groupe ne contient aucune machine. Ajoutez des machines depuis l'onglet Machines.
                    </p>
                </div>
            @else
                @php
                    // Pré-calcul côté PHP pour éviter N+1 dans la boucle Blade (story 4-3).
                    $machineActiveTasksById = $this->machineActiveTasksById;
                @endphp
                <div class="overflow-visible">
                    <table class="table table-zebra">
                        <thead>
                            <tr>
                                <th class="w-12">
                                    <input type="checkbox" class="checkbox" wire:model.live="allGroupMachinesSelected">
                                </th>
                                <th>Nom</th>
                                <th>OS</th>
                                <th>IP</th>
                                <th class="text-center">État</th>
                                <th>Action</th>
                                <th class="w-20"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($group->members as $machine)
                                @php
                                    $activeTask = $machineActiveTasksById[$machine->id] ?? null;
                                    // Source unique de vérité : méthode helper côté composant (review #13).
                                    $isTaskActive = $this->isMachineActionActive($machine->id);
                                    $isTaskFailed = $activeTask && $activeTask->status === \App\Models\MachinePowerActionTask::STATUS_FAILED;
                                    $isTaskCompleted = $activeTask && $activeTask->status === \App\Models\MachinePowerActionTask::STATUS_COMPLETED;
                                    $rowHighlight = match (true) {
                                        $isTaskActive => 'bg-info/5',
                                        $isTaskCompleted => 'bg-success/10',
                                        $isTaskFailed => 'bg-error/10',
                                        default => '',
                                    };
                                @endphp
                                <tr class="hover cursor-pointer {{ $rowHighlight }}"
                                    onclick="if (!event.target.closest('.checkbox-cell') && !event.target.closest('.action-cell')) window.location.href='{{ route('app.parc.machines.show', ['id' => $machine->id, 'from' => route('app.parc.groups.show', ['id' => $group->id], false)]) }}'">
                                    <td class="checkbox-cell p-0">
                                        <label
                                            class="flex items-center justify-center w-full h-full p-3 cursor-pointer">
                                            <input type="checkbox" class="checkbox" value="{{ $machine->id }}"
                                                wire:model.live="selectedGroupMachineIds" wire:click.stop>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid fa-computer text-base-content/50"></i>
                                            <a href="{{ route('app.parc.machines.show', ['id' => $machine->id, 'from' => route('app.parc.groups.show', ['id' => $group->id], false)]) }}"
                                                class="font-medium hover:text-primary">
                                                {{ $machine->name }}
                                            </a>
                                            @if ($machine->isProtected())
                                                <span class="badge badge-warning badge-xs" title="Poste protégé">
                                                    <i class="fa-solid fa-lock"></i> Protégé
                                                </span>
                                            @endif
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-ghost badge-sm">{{ $machine->os }}</span>
                                    </td>
                                    <td class="font-mono text-sm">{{ $machine->ip }}</td>
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
                                            <span class="status status-warning"
                                                  title="Injoignable — dernier check-in {{ $machine->agent_last_checkin_at->diffForHumans() }}"
                                                  aria-label="Injoignable"></span>
                                        @else
                                            <span class="text-base-content/30" title="Présence inconnue (pas d'agent)">—</span>
                                        @endif
                                    </td>
                                    <td>
                                        {{-- Badge d'état de la task associée (story 4-3, AC3). --}}
                                        @if ($activeTask)
                                            @switch($activeTask->status)
                                                @case(\App\Models\MachinePowerActionTask::STATUS_QUEUED)
                                                    <span class="badge badge-ghost badge-sm">
                                                        <i class="fa-solid fa-hourglass-start"></i>
                                                        En file
                                                    </span>
                                                @break

                                                @case(\App\Models\MachinePowerActionTask::STATUS_DISPATCHED)
                                                @case(\App\Models\MachinePowerActionTask::STATUS_RUNNING)
                                                    <span class="badge badge-info badge-sm">
                                                        <span class="loading loading-spinner loading-xs"></span>
                                                        En cours
                                                    </span>
                                                @break

                                                @case(\App\Models\MachinePowerActionTask::STATUS_COMPLETED)
                                                    <span class="badge badge-success badge-sm">
                                                        <i class="fa-solid fa-check"></i>
                                                        OK
                                                    </span>
                                                @break

                                                @case(\App\Models\MachinePowerActionTask::STATUS_FAILED)
                                                    <span class="badge badge-error badge-sm tooltip tooltip-left"
                                                        data-tip="{{ $activeTask->error_message ?? 'Échec inconnu' }}">
                                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                                        Échec
                                                    </span>
                                                @break
                                            @endswitch
                                        @else
                                            <span class="text-base-content/30 text-sm">—</span>
                                        @endif
                                    </td>
                                    <td class="action-cell">
                                        <div class="dropdown dropdown-left">
                                            <label tabindex="0" class="btn btn-ghost btn-sm btn-square
                                                {{ $isTaskActive ? 'opacity-50 cursor-not-allowed' : '' }}">
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </label>
                                            <ul tabindex="0"
                                                class="dropdown-content z-[60] menu p-2 shadow bg-base-100 rounded-box w-56 border border-base-300">
                                                @can('computer.control')
                                                    @foreach ($this->machineActions as $action)
                                                        @php
                                                            $confirmMessage = match ($action->key) {
                                                                'shutdown' => 'Confirmer l\'extinction de cette machine ?',
                                                                'shutdown-force' => 'Forcer l\'extinction de cette machine ? Attention : un utilisateur peut perdre son travail non sauvegardé.',
                                                                'restart' => 'Confirmer le redémarrage de cette machine ?',
                                                                default => null,
                                                            };
                                                            $isDangerous = $action->key === 'shutdown-force';
                                                            $isActionDisabled = $isTaskActive && $action->key !== 'remote';
                                                        @endphp
                                                        <li>
                                                            <button type="button"
                                                                wire:click="executeMachineAction({{ $machine->id }}, '{{ $action->key }}')"
                                                                @if ($confirmMessage) wire:confirm="{{ $confirmMessage }}" @endif
                                                                @disabled($isActionDisabled)
                                                                class="{{ $isDangerous ? 'text-error' : '' }} {{ $isActionDisabled ? 'opacity-50 cursor-not-allowed' : '' }}">
                                                                <i class="{{ $action->icon }}"></i>
                                                                {{ $action->label }}
                                                            </button>
                                                        </li>
                                                    @endforeach
                                                    <div class="divider my-1"></div>
                                                @endcan
                                                <li>
                                                    <button type="button" class="text-error"
                                                        wire:click="removeMachine({{ $machine->id }})"
                                                        wire:confirm="Retirer cette machine du groupe ?">
                                                        <i class="fa-solid fa-xmark"></i>
                                                        Retirer du groupe
                                                    </button>
                                                </li>
                                            </ul>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if (count($selectedGroupMachineIds) > 0)
                    <div class="fixed bottom-4 left-1/2 -translate-x-1/2 z-50">
                        <div class="card bg-base-100 shadow-xl border border-base-300">
                            <div class="card-body py-3 px-4 flex-row items-center gap-4">
                                <span class="text-sm font-medium">
                                    {{ count($selectedGroupMachineIds) }} machine(s) sélectionnée(s)
                                </span>
                                <div class="divider divider-horizontal m-0"></div>
                                @can('computer.control')
                                    <div class="dropdown dropdown-top">
                                        <label tabindex="0"
                                            class="btn btn-primary btn-sm {{ $batchRunning ? 'btn-disabled opacity-50 cursor-not-allowed' : '' }}">
                                            <i class="fa-solid fa-bolt"></i>
                                            Actions machine
                                            <i class="fa-solid fa-chevron-up ml-1"></i>
                                        </label>
                                        <ul tabindex="0"
                                            class="dropdown-content z-[1] menu p-2 shadow bg-base-100 rounded-box w-60 border border-base-300 mb-2">
                                            {{-- batchMachineActions exclut `remote` (AC6 story 4-3). --}}
                                            @foreach ($this->batchMachineActions as $action)
                                                @php
                                                    $confirmMessage = match ($action->key) {
                                                        'shutdown'
                                                            => 'Confirmer l\'extinction des machines sélectionnées ?',
                                                        'shutdown-force'
                                                            => 'Forcer l\'extinction de TOUTES les machines sélectionnées ? Attention : les utilisateurs peuvent perdre leur travail non sauvegardé.',
                                                        'restart'
                                                            => 'Confirmer le redémarrage des machines sélectionnées ?',
                                                        default => null,
                                                    };
                                                    $isDangerous = $action->key === 'shutdown-force';
                                                @endphp
                                                <li>
                                                    <button type="button"
                                                        wire:click="executeSelectedGroupMachinesAction('{{ $action->key }}')"
                                                        @if ($confirmMessage) wire:confirm="{{ $confirmMessage }}" @endif
                                                        @disabled($batchRunning)
                                                        class="{{ $isDangerous ? 'text-error' : '' }} {{ $batchRunning ? 'opacity-50 cursor-not-allowed' : '' }}">
                                                        <i class="{{ $action->icon }}"></i>
                                                        {{ $action->label }}
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endcan
                                <button type="button" class="btn btn-ghost btn-sm"
                                    wire:click="clearSelectedGroupMachines">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            @endif
            </div>{{-- /onglet Postes --}}

            {{-- Onglet Imprimantes --}}
            <div x-show="tab === 'imprimantes'" x-cloak>
                @include('pages.parc.groups.[id]._partials.printers-list')
            </div>
        </div>
    </div>
</div>
