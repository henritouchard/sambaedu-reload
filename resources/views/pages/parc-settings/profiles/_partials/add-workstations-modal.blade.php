<div class="modal modal-open">
    <div class="modal-box max-w-2xl">
        <h3 class="font-bold text-lg mb-4">
            <i class="fa-solid fa-computer mr-2"></i>
            Ajouter des postes au profil
        </h3>

        <!-- Recherche -->
        <div class="form-control mb-4">
            <input type="text" wire:model.live.debounce.300ms="addWorkstationSearch" class="input input-bordered"
                placeholder="Rechercher un poste..." />
        </div>

        <!-- Liste des postes disponibles -->
        <div class="max-h-80 overflow-y-auto border border-base-200 rounded-lg">
            @if ($this->availableWorkstations->isEmpty())
                <div class="p-8 text-center text-base-content/60">
                    <i class="fa-solid fa-computer text-3xl mb-2 opacity-30"></i>
                    <p>Aucun poste disponible</p>
                    @if ($addWorkstationSearch)
                        <p class="text-sm mt-1">Essayez avec d'autres termes de recherche</p>
                    @endif
                </div>
            @else
                <table class="table table-zebra">
                    <thead>
                        <tr>
                            <th class="w-12"></th>
                            <th>Poste</th>
                            <th>Système</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->availableWorkstations as $workstation)
                            <tr wire:key="add-ws-{{ $workstation->id }}" class="hover:bg-base-200">
                                <td>
                                    <label class="cursor-pointer">
                                        <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                                            wire:model.live="selectedWorkstationsToAdd"
                                            value="{{ $workstation->id }}" />
                                    </label>
                                </td>
                                <td>
                                    <div class="flex items-center gap-2">
                                        <i class="fa-solid fa-computer text-info"></i>
                                        <span class="font-medium">{{ $workstation->name }}</span>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge badge-ghost badge-sm">{{ $workstation->os ?? '-' }}</span>
                                </td>
                                <td>
                                    <code class="text-xs">{{ $workstation->ip ?? '-' }}</code>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <!-- Sélection -->
        @if (count($selectedWorkstationsToAdd) > 0)
            <div class="mt-4 p-2 bg-primary/10 rounded-lg">
                <span class="text-sm font-medium">
                    {{ count($selectedWorkstationsToAdd) }} poste(s) sélectionné(s)
                </span>
            </div>
        @endif

        <!-- Actions -->
        <div class="modal-action">
            <button type="button" class="btn btn-ghost" wire:click="closeAddWorkstationsModal">
                Annuler
            </button>
            <button type="button" class="btn btn-primary" wire:click="addSelectedWorkstations"
                @if (count($selectedWorkstationsToAdd) === 0) disabled @endif>
                <i class="fa-solid fa-plus mr-1"></i>
                Ajouter
            </button>
        </div>
    </div>
    <div class="modal-backdrop bg-black/50" wire:click="closeAddWorkstationsModal"></div>
</div>
