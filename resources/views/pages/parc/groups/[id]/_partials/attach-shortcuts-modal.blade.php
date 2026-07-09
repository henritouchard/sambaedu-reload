{{--
    Modale d'attribution de raccourcis à ce groupe de postes.
    Mirror du pattern des modales d'attachement WPKG (recherche + sélection
    multiple + confirmation). Pilotée par le composant parent
    (showAttachShortcutModal / shortcutSearch / selectedShortcutIdsToAdd).
--}}
@php
    $shortcuts = $this->availableShortcuts;
    $selectionCount = count($selectedShortcutIdsToAdd);
@endphp

<div class="modal modal-open">
    <div class="modal-box max-w-3xl">
        <h3 class="font-bold text-lg mb-4">
            <i class="fa-solid fa-link mr-2"></i>
            Attribuer des raccourcis au groupe
        </h3>

        {{-- Recherche --}}
        <div class="form-control mb-4">
            <input type="text" wire:model.live.debounce.300ms="shortcutSearch"
                class="input input-bordered"
                placeholder="Rechercher un raccourci..." />
        </div>

        {{-- Liste --}}
        <div class="max-h-96 overflow-y-auto border border-base-200 rounded-lg">
            @forelse ($shortcuts as $shortcut)
                <label wire:key="grp-add-shortcut-{{ $shortcut->id }}"
                    class="flex items-center gap-3 p-3 hover:bg-base-200 cursor-pointer border-b border-base-200 last:border-b-0">
                    <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                        wire:model.live="selectedShortcutIdsToAdd" value="{{ $shortcut->id }}" />
                    <img src="{{ route('shortcuts.icon', ['name' => $shortcut->name]) }}"
                        alt="{{ $shortcut->name }}" class="w-8 h-8 object-contain rounded shrink-0"
                        onerror="this.src='/elements/images/system-run.png'" />
                    <div class="flex-1 min-w-0">
                        <div class="font-medium truncate">
                            {{ $shortcut->name }}
                            @if (!$shortcut->is_active)
                                <span class="badge badge-ghost badge-xs ml-1">inactif</span>
                            @endif
                        </div>
                        <div class="text-xs text-base-content/60 truncate">
                            <code>{{ $shortcut->key }}</code>
                            <span class="mx-1">•</span>
                            {{ $shortcut->getPlaceLabel() }}
                            @if ($shortcut->category)
                                <span class="mx-1">•</span>{{ $shortcut->category }}
                            @endif
                        </div>
                    </div>
                </label>
            @empty
                <div class="p-8 text-center text-base-content/60">
                    @if ($shortcutSearch !== '')
                        <p>Aucun raccourci trouvé pour "{{ $shortcutSearch }}"</p>
                    @else
                        <p>Aucun raccourci disponible à attribuer</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($selectionCount > 0)
            <div class="mt-4 p-3 bg-primary/10 rounded-lg">
                <span class="font-medium">{{ $selectionCount }} raccourci(s) sélectionné(s)</span>
            </div>
        @endif

        <div class="modal-action">
            <button type="button" class="btn" wire:click="closeAttachShortcutModal">Annuler</button>
            <button type="button" class="btn btn-primary" wire:click="attachShortcuts"
                @disabled($selectionCount === 0)>
                <i class="fa-solid fa-plus mr-1"></i>
                Attribuer
            </button>
        </div>
    </div>
    <div class="modal-backdrop bg-black/50" wire:click="closeAttachShortcutModal"></div>
</div>
