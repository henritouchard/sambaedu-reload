{{--
    Modale d'override quota (user OU groupe).

    Mutualisée entre :
      - pages/users/[login]/_partials/quota-section.blade.php
      - pages/users/groups/[id]/_partials/group-quota-section.blade.php

    Le composant Livewire englobant DOIT exposer :
      - public bool   $showOverrideModal
      - public string $overridePartition  ('/home' | '/var/sambaedu')
      - public string $overrideType       ('inherited' | 'unlimited' | 'custom')
      - public int    $overrideSoftMb
      - public int    $overrideOveragePercent
      - public function closeOverrideModal()
      - public function applyOverride()

    Props :
      - title             : titre du header (varie user vs group).
      - inheritedLabel    : libellé du radio "inherited" (héritage user = règle
                            groupe ou défaut ; héritage groupe = défaut profil).
      - overridePartition : valeur courante (prop nécessaire car le scope Blade
                            d'un x-component n'hérite pas des vars du parent —
                            les wire:model continuent eux de cibler le parent
                            Livewire englobant).
      - overrideType      : idem.
--}}
@props([
    'title' => 'Modifier le quota',
    'inheritedLabel' => 'Hériter',
    'overridePartition' => '/home',
    'overrideType' => 'inherited',
])

{{-- @teleport vers <body> : invocation depuis l'intérieur de la card quota,
     dont le containing block contraint visuellement le `<dialog>`. --}}
@teleport('body')

@php
    $partitionLabels = [
        '/home' => '/home — Espace personnel (K:)',
        '/var/sambaedu' => '/var/sambaedu — Partages',
    ];
    $typeLabels = [
        'inherited' => $inheritedLabel,
        'unlimited' => 'Illimité',
        'custom' => 'Personnalisé',
    ];
@endphp

<x-molecules.modal wire:model="showOverrideModal" closeMethod="closeOverrideModal"
    :title="$title" icon="fa-hard-drive text-primary" size="max-w-2xl" height="h-auto">

    <x-molecules.modal.section title="Partition" icon="fa-folder-open text-primary" dense>
        <details class="dropdown w-full" x-data @click.outside="$el.removeAttribute('open')">
            <summary class="btn btn-sm btn-outline w-full justify-between font-normal">
                <span class="truncate">{{ $partitionLabels[$overridePartition] ?? 'Choisir une partition' }}</span>
                <i class="fa-solid fa-chevron-down text-xs"></i>
            </summary>
            <ul class="menu dropdown-content bg-base-100 rounded-box z-20 mt-1 p-1 shadow border border-base-300 w-full">
                @foreach ($partitionLabels as $value => $label)
                    <li>
                        <a class="text-sm py-1"
                            wire:click="$set('overridePartition', '{{ $value }}')"
                            onclick="this.closest('details').removeAttribute('open')">
                            {{ $label }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </details>
    </x-molecules.modal.section>

    <x-molecules.modal.section title="Type de quota" icon="fa-sliders text-primary" dense>
        <div class="flex flex-col gap-2">
            @foreach ($typeLabels as $value => $label)
                <label class="flex gap-2 cursor-pointer">
                    <input type="radio" wire:model.live="overrideType" value="{{ $value }}"
                        class="radio radio-sm" />
                    <span>{{ $label }}</span>
                </label>
            @endforeach
        </div>

        @if ($overrideType === 'custom')
            <div class="grid grid-cols-2 gap-3 mt-3">
                <div class="form-control">
                    <label class="label py-1">
                        <span class="label-text text-xs">Quota soft (Mo)</span>
                    </label>
                    <input type="number" wire:model="overrideSoftMb"
                        class="input input-bordered input-sm" min="0" />
                    @error('overrideSoftMb')
                        <span class="text-xs text-error mt-1">{{ $message }}</span>
                    @enderror
                </div>
                <div class="form-control">
                    <label class="label py-1">
                        <span class="label-text text-xs">Dépassement (%)</span>
                    </label>
                    <input type="number" wire:model="overrideOveragePercent"
                        class="input input-bordered input-sm" min="0" max="100" />
                </div>
            </div>
        @endif
    </x-molecules.modal.section>

    <x-slot:footer>
        <button type="button" class="btn btn-ghost" wire:click="closeOverrideModal">Annuler</button>
        <button type="button" class="btn btn-primary" wire:click="applyOverride"
            wire:loading.attr="disabled" wire:target="applyOverride">
            <span wire:loading wire:target="applyOverride"
                class="loading loading-spinner loading-xs"></span>
            Appliquer
        </button>
    </x-slot:footer>
</x-molecules.modal>

@endteleport
