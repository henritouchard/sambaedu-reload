@props([
    // Title affiché en en-tête.
    'title' => 'Ajouter des applications',
    // Collection|array<Application> — items à afficher (déjà filtrés par le parent).
    'items' => [],
    // Nom de la propriété Livewire (livre via wire:model.live) qui pilote la recherche.
    'searchProperty' => 'addAppSearch',
    // Nom de la propriété array qui contient les IDs sélectionnés.
    'selectionProperty' => 'selectedAppsToAdd',
    // Texte de recherche courant (lecture pour empty-state).
    'searchValue' => '',
    // Méthodes Livewire à appeler.
    'closeMethod' => 'closeAddAppsModal',
    'confirmMethod' => 'addSelectedApps',
    // Compte sélectionné (pour disabled + summary).
    'selectionCount' => 0,
    // Slot — wire:key prefix pour idempotence Livewire.
    'keyPrefix' => 'add-app',
    // Variante du context pour wire:keys / labels — 'profile' / 'group' / 'workstation'.
    'context' => 'profile',
])
@php
    $emptyMsg = match ($context) {
        'group' => 'Aucune application disponible pour ce parc',
        'workstation' => 'Aucune application disponible pour ce poste',
        default => 'Aucune application disponible',
    };
@endphp
<div class="modal modal-open">
    <div class="modal-box max-w-3xl">
        <h3 class="font-bold text-lg mb-4">
            <i class="fa-solid fa-cube mr-2"></i>
            {{ $title }}
        </h3>

        {{-- Recherche --}}
        <div class="form-control mb-4">
            <input type="text" wire:model.live.debounce.300ms="{{ $searchProperty }}"
                class="input input-bordered"
                placeholder="Rechercher une application..." />
        </div>

        {{-- Liste --}}
        <div class="max-h-96 overflow-y-auto border border-base-300 rounded-lg">
            @forelse ($items as $app)
                <label wire:key="{{ $keyPrefix }}-{{ $app->id }}"
                    class="flex items-center gap-3 p-3 hover:bg-base-200 cursor-pointer border-b border-base-300 last:border-b-0">
                    <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                        wire:model.live="{{ $selectionProperty }}" value="{{ $app->id }}" />
                    <x-atoms.icon-avatar icon="fa-cube" bgColor="bg-primary/10" textColor="text-primary"
                        size="w-8 h-8" iconSize="text-sm" />
                    <div class="flex-1">
                        <div class="font-medium">
                            {{ $app->name }}
                            @if (!empty($app->branch) && $app->branch !== 'stable')
                                <span class="badge badge-{{ $app->branch === 'testing' ? 'warning' : 'info' }} badge-xs ml-1">
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
                    @if (!empty($searchValue))
                        <p>Aucune application trouvée pour "{{ $searchValue }}"</p>
                    @else
                        <p>{{ $emptyMsg }}</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($selectionCount > 0)
            <div class="mt-4 p-3 bg-primary/10 rounded-lg">
                <span class="font-medium">{{ $selectionCount }} application(s) sélectionnée(s)</span>
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
    <div class="modal-backdrop bg-black/50" wire:click="{{ $closeMethod }}"></div>
</div>
