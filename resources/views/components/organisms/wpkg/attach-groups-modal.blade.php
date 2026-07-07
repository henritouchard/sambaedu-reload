@props([
    'title' => 'Ajouter des groupes',
    'items' => [],
    'searchProperty' => 'addGroupSearch',
    'selectionProperty' => 'selectedGroupsToAdd',
    'searchValue' => '',
    'closeMethod' => 'closeAddGroupsModal',
    'confirmMethod' => 'addSelectedGroups',
    'selectionCount' => 0,
    'keyPrefix' => 'add-group',
])
<div class="modal modal-open">
    <div class="modal-box max-w-3xl">
        <h3 class="font-bold text-lg mb-4">{{ $title }}</h3>

        <div class="form-control mb-4">
            <input type="text" wire:model.live.debounce.300ms="{{ $searchProperty }}"
                class="input input-bordered"
                placeholder="Rechercher un groupe..." />
        </div>

        <div class="max-h-96 overflow-y-auto border border-base-200 rounded-lg">
            @forelse ($items as $group)
                <label wire:key="{{ $keyPrefix }}-{{ $group->id }}"
                    class="flex items-center gap-3 p-3 hover:bg-base-200 cursor-pointer border-b border-base-200 last:border-b-0">
                    <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                        wire:model.live="{{ $selectionProperty }}" value="{{ $group->id }}" />
                    <x-atoms.icon-avatar icon="fa-folder-tree" bgColor="bg-secondary/10" textColor="text-secondary"
                        size="w-8 h-8" iconSize="text-sm" />
                    <div class="flex-1">
                        <div class="font-medium">{{ $group->display_name_or_name }}</div>
                        <div class="text-xs text-base-content/60">
                            {{ $group->workstations()->count() }} poste(s)
                            @if (method_exists($group, 'isSyncedWithAd') && $group->isSyncedWithAd())
                                <span class="mx-1">•</span>
                                <span class="text-success">{{ $group->getAdStatusLabel() }}</span>
                            @endif
                        </div>
                    </div>
                </label>
            @empty
                <div class="p-8 text-center text-base-content/60">
                    @if (!empty($searchValue))
                        <p>Aucun groupe trouvé pour "{{ $searchValue }}"</p>
                    @else
                        <p>Aucun groupe disponible</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($selectionCount > 0)
            <div class="mt-4 p-3 bg-primary/10 rounded-lg">
                <span class="font-medium">{{ $selectionCount }} groupe(s) sélectionné(s)</span>
            </div>
        @endif

        <div class="modal-action">
            <button type="button" class="btn" wire:click="{{ $closeMethod }}">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="{{ $confirmMethod }}"
                @disabled($selectionCount === 0)>
                <i class="fa-solid fa-plus mr-1"></i>
                Ajouter
            </button>
        </div>
    </div>
    <div class="modal-backdrop" wire:click="{{ $closeMethod }}"></div>
</div>
