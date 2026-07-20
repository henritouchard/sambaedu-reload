{{--
    Composant Blade réutilisable — Barre de filtre au-dessus d'une liste.

    Conteneur unique de toutes les barres de filtre de l'application. Il porte le
    filet de 1px (« hairline », token --color-border) qui sépare la barre du
    tableau : la barre pose à plat sur le fond de page, sans carte ni ombre.

    Le bouton de réinitialisation est rendu PAR LE COMPOSANT et aligné à droite
    (ml-auto) — ne pas le passer dans le contenu, sinon il se retrouve collé aux
    filtres et l'alignement diverge d'une page à l'autre.

    Props :
      - reset        : nom de la méthode Livewire de réinitialisation. Le bouton
                       n'est rendu que si cette prop est fournie.
      - resetLabel   : libellé du bouton. Défaut 'Réinitialiser'.
      - resetDisabled: désactive le bouton (typiquement quand aucun filtre n'est
                       actif). Le bouton reste VISIBLE mais grisé, pour que la
                       barre ne change pas de largeur quand on filtre.

    Slots :
      - default  : les contrôles de filtre, dans l'ordre de lecture. Convention :
                   la recherche en premier, puis les filtres du plus large au
                   plus étroit.
      - actions  : contenu aligné à droite AVANT le bouton reset (ex. sélecteur
                   « lignes par page »).
      - footer   : contenu rendu SOUS la rangée de filtres, à l'intérieur de la
                   barre (ex. chips de filtres actifs, alerte de synchro).

    Usage :
      <x-molecules.filter-bar reset="resetProfileFilters">
          <div class="flex-1 min-w-[200px]">
              <x-atoms.search-input model="profileSearch" placeholder="Nom, description..." />
          </div>
          <x-molecules.filter-toggle name="activeOnly" :active="$activeOnly"
              :options="['' => 'Tous', '1' => 'Actifs', '0' => 'Inactifs']" />
      </x-molecules.filter-bar>
--}}
@props([
    'reset' => null,
    'resetLabel' => 'Réinitialiser',
    'resetDisabled' => false,
])

<div {{ $attributes->merge(['class' => 'flex-shrink-0 border-b border-base-300 pb-3']) }}>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        {{ $slot }}

        @if (isset($actions) || $reset)
            {{-- ml-auto : pousse actions + reset contre le bord droit de la barre. --}}
            <div class="ml-auto flex items-center gap-2">
                {{ $actions ?? '' }}
                @if ($reset)
                    <button type="button" class="btn btn-ghost btn-sm" wire:click="{{ $reset }}"
                        title="{{ $resetLabel }} les filtres" @disabled($resetDisabled)
                        data-testid="filter-reset">
                        <i class="fa-solid fa-rotate-left"></i>
                        <span class="hidden sm:inline">{{ $resetLabel }}</span>
                    </button>
                @endif
            </div>
        @endif
    </div>

    @isset($footer)
        <div class="mt-2">{{ $footer }}</div>
    @endisset
</div>
