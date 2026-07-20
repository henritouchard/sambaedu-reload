{{-- Story 7.1 — Onglet Historique (AC6) --}}
<div class="space-y-4">

    {{-- Filtres --}}
    <div class="border-b border-base-300 pb-3">
        <div class="flex flex-wrap gap-3 items-end">
            <div class="flex-1 min-w-[180px]">
                <label class="text-xs font-medium text-base-content/60 mb-1 block">Action</label>
                <select wire:model.live="historyActionFilter"
                    class="select select-sm select-bordered w-full">
                    <option value="">Toutes</option>
                    <option value="grant">grant</option>
                    <option value="revoke">revoke</option>
                    <option value="negate">negate</option>
                    <option value="expire">expire</option>
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="text-xs font-medium text-base-content/60 mb-1 block">Utilisateur cible</label>
                <label class="input input-sm w-full">
                    <i class="fa-solid fa-user opacity-50"></i>
                    <input type="text" wire:model.live.debounce.300ms="historyTargetFilter"
                        placeholder="login..." class="grow" />
                </label>
            </div>
            <div class="flex-1 min-w-[140px]">
                <label class="text-xs font-medium text-base-content/60 mb-1 block">Du</label>
                <input type="date" wire:model.live="historyFromFilter"
                    class="input input-sm input-bordered w-full" />
            </div>
            <div class="flex-1 min-w-[140px]">
                <label class="text-xs font-medium text-base-content/60 mb-1 block">Au</label>
                <input type="date" wire:model.live="historyToFilter"
                    class="input input-sm input-bordered w-full" />
            </div>
            <div class="shrink-0">
                <button type="button" wire:click="resetHistoryFilters" class="btn btn-ghost btn-sm">
                    <i class="fa-solid fa-xmark"></i>
                    Effacer
                </button>
            </div>
        </div>
    </div>

    {{-- Table paginée --}}
    @php
        $entries = $this->historyEntries;
    @endphp

    @if ($entries->isEmpty())
        <div class="card bg-base-100 border border-base-300 shadow-sm">
            <div class="card-body text-center py-12">
                <div class="text-4xl mb-4 opacity-20"><i class="fa-solid fa-clock-rotate-left"></i></div>
                <h3 class="text-lg font-semibold mb-2">Aucune entrée d'historique</h3>
                <p class="text-base-content/60 max-w-md mx-auto">
                    Aucune opération de délégation ne correspond aux filtres actuels.
                </p>
            </div>
        </div>
    @else
        <div class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Acteur</th>
                            <th>Action</th>
                            <th>Cible</th>
                            <th>Salle</th>
                            <th>Permission</th>
                            <th>Type</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($entries as $h)
                            @php
                                $badgeClass = match ($h->action) {
                                    'grant' => 'badge-success',
                                    'revoke' => 'badge-warning',
                                    'negate' => 'badge-error',
                                    'expire' => 'badge-ghost',
                                    default => 'badge-ghost',
                                };
                            @endphp
                            <tr class="hover:bg-base-200/30">
                                <td class="text-xs text-base-content/70 whitespace-nowrap">
                                    {{ $h->created_at?->format('d/m/Y H:i') }}
                                </td>
                                <td class="text-xs font-mono">{{ $h->actor?->login ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $badgeClass }} badge-xs">{{ $h->action }}</span>
                                </td>
                                <td class="text-xs font-mono">{{ $h->target?->login ?? '—' }}</td>
                                <td class="text-xs">{{ $h->workstationGroup?->name ?? '—' }}</td>
                                <td><span class="font-mono text-xs">{{ $h->permission_name }}</span></td>
                                <td>
                                    @if ($h->is_negative)
                                        <span class="badge badge-error badge-xs">Exclusion</span>
                                    @else
                                        <span class="badge badge-outline badge-xs">Positive</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            <div class="p-3 border-t border-base-300">
                {{ $entries->links() }}
            </div>
        </div>
    @endif
</div>
