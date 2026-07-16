{{--
    Tableau de rattachement aux parcs (workstation groups) pour les modales
    imprimante. 1 ligne = 1 parc, case à cocher liée à un tableau d'ids Livewire.
    Recherche par nom côté client (Alpine, instantané, sans round-trip) : les
    lignes masquées gardent leur état de sélection (display:none, pas de démontage).

    Variables attendues :
      @var array  $availableGroups  liste [{id,name,display_name,description,workstations_count}]
      @var string $model            cible wire:model (nom du tableau d'ids : new/editWorkstationGroupIds)
--}}
<div x-data="{ q: '' }">
    <label class="input input-bordered input-sm flex items-center gap-2 mb-2 w-full">
        <i class="fa-solid fa-magnifying-glass opacity-50"></i>
        <input type="text" x-model="q" class="grow" placeholder="Rechercher un parc par nom…" />
    </label>

    @if (empty($availableGroups))
        <p class="text-sm text-base-content/60">Aucun parc disponible.</p>
    @else
        <div class="border border-base-200 rounded-lg overflow-hidden">
            <div class="max-h-64 overflow-y-auto">
                <table class="table table-sm table-pin-rows">
                    <thead>
                        <tr>
                            <th class="w-8"></th>
                            <th>Parc</th>
                            <th>Description</th>
                            <th class="text-center">Postes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($availableGroups as $g)
                            <tr wire:key="attach-{{ $model }}-{{ $g['id'] }}"
                                data-search="{{ mb_strtolower(($g['display_name'] ?? '') . ' ' . ($g['name'] ?? '')) }}"
                                x-show="q.trim() === '' || $el.dataset.search.includes(q.toLowerCase().trim())">
                                <td>
                                    <input type="checkbox" wire:model="{{ $model }}" value="{{ $g['id'] }}"
                                        class="checkbox checkbox-sm" />
                                </td>
                                <td>
                                    <div class="font-medium text-sm">{{ $g['display_name'] ?? $g['name'] }}</div>
                                    @if (!empty($g['display_name']) && $g['display_name'] !== $g['name'])
                                        <div class="text-xs text-base-content/50 font-mono">{{ $g['name'] }}</div>
                                    @endif
                                </td>
                                <td>
                                    <span class="text-sm text-base-content/70 line-clamp-1">{{ $g['description'] ?: '—' }}</span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-ghost badge-sm">{{ $g['workstations_count'] }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
