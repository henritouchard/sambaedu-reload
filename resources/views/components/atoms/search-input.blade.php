{{--
    Composant Blade réutilisable — Champ de recherche des barres de filtre.

    Rend un champ de saisie avec une icône loupe À L'INTÉRIEUR du champ, alignée
    à gauche. C'est l'idiome DaisyUI 5 : `.input` est un conteneur flex, l'icône
    et le `<input>` sont ses enfants (le `<input>` porte `grow`). Ne PAS revenir
    à un `<input class="input">` avec une icône positionnée en absolute.

    Props :
      - model       : nom de la propriété Livewire à lier. Le composant pose
                      lui-même `wire:model.live.debounce.300ms` (le debounce est
                      la convention projet pour une recherche texte).
      - placeholder : texte indicatif. Défaut 'Rechercher...'.
      - size        : taille DaisyUI du conteneur ('input-sm' par défaut, ''
                      pour la taille normale).
      - width       : classes de largeur du conteneur. Défaut 'w-full' — dans une
                      x-molecules.filter-bar, enveloppe le composant dans un
                      `<div class="flex-1 min-w-[200px]">` pour qu'il s'étire.
      - testid      : valeur de data-testid posée sur le <input>.

    Tout attribut supplémentaire est transmis au <input> (aria-label, wire:model
    personnalisé, etc.). Un `wire:model` passé en attribut REMPLACE celui dérivé
    de `model` — utile pour un modificateur différent (.live sans debounce).

    Usage :
      <x-atoms.search-input model="profileSearch" placeholder="Nom, description..." />
      <x-atoms.search-input model="search" size="" width="w-72" />
--}}
@props([
    'model' => null,
    'placeholder' => 'Rechercher...',
    'size' => 'input-sm',
    'width' => 'w-full',
    'testid' => 'search-input',
])

@php
    // Un wire:model explicite passé en attribut l'emporte sur la prop `model`.
    $hasExplicitModel = collect($attributes->getAttributes())
        ->keys()
        ->contains(fn (string $key) => str_starts_with($key, 'wire:model'));
@endphp

<label class="input input-bordered {{ $size }} {{ $width }} flex items-center gap-2">
    <i class="fa-solid fa-magnifying-glass opacity-50 shrink-0"></i>
    <input type="search" class="grow min-w-0" placeholder="{{ $placeholder }}"
        @unless ($hasExplicitModel)
            @if ($model) wire:model.live.debounce.300ms="{{ $model }}" @endif
        @endunless
        data-testid="{{ $testid }}"
        {{ $attributes }} />
</label>
