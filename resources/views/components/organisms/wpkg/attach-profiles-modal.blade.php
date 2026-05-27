{{--
    Story 15.4 / Correction post-review #2 — Modale dédiée à l'attachement
    d'AppProfile à un parc ou un poste.

    Distincte de attach-groups-modal (qui sert à attacher des WorkstationGroup
    à un AppProfile dans la fiche profil — sémantique inversée). Le sous-texte
    affiche le nombre d'applications du profil au lieu de « N poste(s) ».

    @props :
      - $items : Collection<App\Models\AppProfile> — DOIT être eager-loadée
        avec la relation `applications` (via ->with('applications') ou
        ->withCount('applications')) côté composant Livewire parent pour éviter
        le N+1. Le composant ne fait PAS de fallback : si la relation n'est
        pas chargée, $profile->applications->count() déclenchera 1 query par
        item. Voir computed `availableWpkgProfiles` dans les pages parc/groups
        et parc/machines.
--}}
@props([
    'title' => 'Ajouter des profils applicatifs',
    'items' => [],
    'searchProperty' => 'wpkgProfileSearch',
    'selectionProperty' => 'selectedWpkgProfileIdsToAdd',
    'searchValue' => '',
    'closeMethod' => 'closeAttachWpkgProfileModal',
    'confirmMethod' => 'attachWpkgProfiles',
    'selectionCount' => 0,
    'keyPrefix' => 'attach-profile',
])
<div class="modal modal-open">
    <div class="modal-box max-w-3xl">
        <h3 class="font-bold text-lg mb-4">{{ $title }}</h3>

        <div class="form-control mb-4">
            <input type="text" wire:model.live.debounce.300ms="{{ $searchProperty }}"
                class="input input-bordered"
                placeholder="Rechercher un profil applicatif..." />
        </div>

        <div class="max-h-96 overflow-y-auto border border-base-200 rounded-lg">
            @forelse ($items as $profile)
                <label wire:key="{{ $keyPrefix }}-{{ $profile->id }}"
                    class="flex items-center gap-3 p-3 hover:bg-base-200 cursor-pointer border-b border-base-200 last:border-b-0">
                    <input type="checkbox" class="checkbox checkbox-sm checkbox-primary"
                        wire:model.live="{{ $selectionProperty }}" value="{{ $profile->id }}" />
                    <x-atoms.icon-avatar icon="fa-cubes" bgColor="bg-primary/10" textColor="text-primary"
                        size="w-8 h-8" iconSize="text-sm" />
                    <div class="flex-1">
                        <div class="font-medium">{{ $profile->display_name ?? $profile->name }}</div>
                        <div class="text-xs text-base-content/60">
                            {{-- Eager-loading attendu : cf. @props ci-dessus. --}}
                            {{ $profile->applications->count() }} application(s)
                            @if (! $profile->is_active)
                                <span class="mx-1">•</span>
                                <span class="text-warning">inactif</span>
                            @endif
                        </div>
                    </div>
                </label>
            @empty
                <div class="p-8 text-center text-base-content/60">
                    @if (!empty($searchValue))
                        <p>Aucun profil trouvé pour "{{ $searchValue }}"</p>
                    @else
                        <p>Aucun profil disponible</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($selectionCount > 0)
            <div class="mt-4 p-3 bg-primary/10 rounded-lg">
                <span class="font-medium">{{ $selectionCount }} profil(s) sélectionné(s)</span>
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
