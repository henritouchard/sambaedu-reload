<div class="modal modal-open">
    <div class="modal-box max-w-3xl">
        <h3 class="font-bold text-lg mb-4">Ajouter des groupes au profil</h3>

        <!-- Recherche -->
        <div class="form-control mb-4">
            <input type="text" wire:model.live.debounce.300ms="addGroupSearch" class="input input-bordered"
                placeholder="Rechercher un groupe..." />
        </div>

        <!-- Liste des groupes disponibles -->
        <div class="max-h-96 overflow-y-auto border border-base-200 rounded-lg">
            @forelse ($this->availableGroups as $group)
                <label wire:key="add-group-{{ $group->id }}"
                    class="flex items-center gap-3 p-3 hover:bg-base-200 cursor-pointer border-b border-base-200 last:border-b-0">
                    <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                        wire:model="selectedGroupsToAdd" value="{{ $group->id }}" />
                    <div class="avatar placeholder">
                        <div class="bg-secondary/10 text-secondary rounded w-8 h-8">
                            <i class="fa-solid fa-folder-tree text-sm"></i>
                        </div>
                    </div>
                    <div class="flex-1">
                        <div class="font-medium">{{ $group->name }}</div>
                        <div class="text-xs text-base-content/60">
                            {{ $group->workstations()->count() }} poste(s)
                            @if ($group->isSyncedWithAd())
                                <span class="mx-1">•</span>
                                <span class="text-success">{{ $group->getAdStatusLabel() }}</span>
                            @endif
                        </div>
                    </div>
                </label>
            @empty
                <div class="p-8 text-center text-base-content/60">
                    @if ($addGroupSearch)
                        <p>Aucun groupe trouvé pour "{{ $addGroupSearch }}"</p>
                    @else
                        <p>Tous les groupes sont déjà liés à ce profil</p>
                    @endif
                </div>
            @endforelse
        </div>

        <!-- Sélection -->
        @if (count($selectedGroupsToAdd) > 0)
            <div class="mt-4 p-3 bg-primary/10 rounded-lg">
                <span class="font-medium">{{ count($selectedGroupsToAdd) }} groupe(s) sélectionné(s)</span>
            </div>
        @endif

        <div class="modal-action">
            <button type="button" class="btn" wire:click="closeAddGroupsModal">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="addSelectedGroups" @disabled(count($selectedGroupsToAdd) === 0)>
                <i class="fa-solid fa-plus mr-1"></i>
                Ajouter
            </button>
        </div>
    </div>
    <div class="modal-backdrop" wire:click="closeAddGroupsModal"></div>
</div>
