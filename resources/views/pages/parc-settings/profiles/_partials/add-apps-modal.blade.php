<div class="modal modal-open">
    <div class="modal-box max-w-3xl">
        <h3 class="font-bold text-lg mb-4">
            <i class="fa-solid fa-cube mr-2"></i>
            Ajouter des applications au profil
        </h3>

        <!-- Recherche -->
        <div class="form-control mb-4">
            <input type="text" wire:model.live.debounce.300ms="addAppSearch" class="input input-bordered"
                placeholder="Rechercher une application..." />
        </div>

        <!-- Liste des applications disponibles (depuis depot_applications) -->
        <div class="max-h-96 overflow-y-auto border border-base-200 rounded-lg">
            @forelse ($this->availableApplications as $app)
                <label wire:key="add-app-{{ $app->id }}"
                    class="flex items-center gap-3 p-3 hover:bg-base-200 cursor-pointer border-b border-base-200 last:border-b-0">
                    <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                        wire:model.live="selectedAppsToAdd" value="{{ $app->id }}" />
                    <x-atoms.icon-avatar icon="fa-cube" bgColor="bg-primary/10" textColor="text-primary" size="w-8 h-8" iconSize="text-sm" />
                    <div class="flex-1">
                        <div class="font-medium">
                            {{ $app->name }}
                            @if ($app->branch && $app->branch !== 'stable')
                                <span
                                    class="badge badge-{{ $app->branch === 'testing' ? 'warning' : 'info' }} badge-xs ml-1">
                                    {{ $app->branch }}
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-base-content/60">
                            <code>{{ $app->app_id }}</code>
                            <span class="mx-1">•</span>
                            {{ $app->category ?? '-' }}
                            <span class="mx-1">•</span>
                            v{{ $app->version ?? '-' }}
                        </div>
                    </div>
                </label>
            @empty
                <div class="p-8 text-center text-base-content/60">
                    @if ($addAppSearch)
                        <p>Aucune application trouvée pour "{{ $addAppSearch }}"</p>
                    @else
                        <p>Aucune application disponible dans les dépôts</p>
                    @endif
                </div>
            @endforelse
        </div>

        <!-- Sélection -->
        @if (count($selectedAppsToAdd) > 0)
            <div class="mt-4 p-3 bg-primary/10 rounded-lg">
                <span class="font-medium">{{ count($selectedAppsToAdd) }} application(s) sélectionnée(s)</span>
            </div>
        @endif

        <div class="modal-action">
            <button type="button" class="btn" wire:click="closeAddAppsModal">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="addSelectedApps" @disabled(count($selectedAppsToAdd) === 0)>
                <i class="fa-solid fa-plus mr-1"></i>
                Ajouter
            </button>
        </div>
    </div>
    <div class="modal-backdrop bg-black/50" wire:click="closeAddAppsModal"></div>
</div>
