{{--
    Composant Blade réutilisable — Barre d'onglets DaisyUI uniforme.

    Rend une barre `role="tablist"` pilotée par une propriété Livewire `$tab`
    (voir la convention onglets : #[Url(keep:true)] public string $tab).

    Props :
      - tabs    : tableau associatif clé => définition, ex.
                  ['groups' => ['label' => 'Groupes', 'icon' => 'fa-solid fa-folder-tree', 'badge' => 3]]
                  'icon' et 'badge' sont optionnels ; 'badge' peut être null/0 (masqué si vide).
                  La visibilité conditionnelle (@can) se gère en AMONT en n'ajoutant pas
                  l'onglet interdit au tableau.
      - active  : clé de l'onglet actif (la valeur de $tab).
      - action  : nom de la méthode Livewire appelée avec la clé (défaut 'setTab').
      - variant : 'bordered' | 'boxed' (noms historiques v4, mappés vers tabs-border /
                  tabs-box de DaisyUI 5). Défaut 'bordered' : barre pleine largeur avec
                  ligne de base fine et onglet actif souligné d'un trait primaire.
                  'boxed' garde l'ancien rendu en pastille primaire sur fond base-200.
      - size    : taille DaisyUI, ex. 'tab-lg' (mappée vers tabs-lg sur le conteneur).

    Usage :
      <x-molecules.tabs :tabs="$tabs" :active="$tab" />
      <x-molecules.tabs :tabs="$tabs" :active="$activeTab" action="setTab" variant="bordered" size="tab-lg" />
--}}
@props([
    'tabs' => [],
    'active' => '',
    'action' => 'setTab',
    'variant' => 'bordered',
    'size' => '',
])

@php
    // Mapping des noms de variantes DaisyUI 4 (API du composant) vers DaisyUI 5.
    $variantClass = ['boxed' => 'tabs-box', 'bordered' => 'tabs-border'][$variant] ?? 'tabs-'.$variant;
    // En v5 la taille se pose sur le conteneur (tabs-lg), plus sur chaque onglet (tab-lg).
    $sizeClass = str_replace('tab-', 'tabs-', $size);

    // Variante soulignée : on masque l'indicateur natif DaisyUI (::before à 80 % de
    // large, posé au bord du tab) et on dessine notre propre trait via une vraie
    // bordure basse pleine largeur, tirée d'1px vers le bas (-mb-px) pour qu'il se
    // SUPERPOSE à la ligne de base du conteneur au lieu de laisser un espace.
    $borderedBase = 'before:hidden rounded-none! border-b-2 border-transparent -mb-px';

    $activeClass = $variant === 'bordered'
        ? "{$borderedBase} tab-active !border-b-primary text-primary font-semibold"
        : 'tab-active bg-primary text-primary-content font-semibold shadow-md
           [&_.badge]:bg-primary-content [&_.badge]:text-primary [&_.badge]:border-0';

    $inactiveClass = $variant === 'bordered'
        ? "{$borderedBase} text-base-content/60 hover:text-base-content"
        : 'text-base-content/60 hover:text-base-content';

    // La variante soulignée s'étend sur toute la largeur et pose une ligne de base
    // fine (noir léger 1px, thème-aware) sous l'ensemble de la zone de contenu.
    $containerClass = $variant === 'bordered' ? 'w-full border-b border-base-content/20' : '';
@endphp

<div role="tablist" {{ $attributes->merge(['class' => "tabs {$variantClass} {$sizeClass} {$containerClass}"]) }}>
    @foreach ($tabs as $key => $tab)
        <button type="button" role="tab"
            aria-selected="{{ $active === $key ? 'true' : 'false' }}"
            class="tab transition-colors duration-150 focus:outline-none! focus-visible:outline-none! {{ $active === $key
                ? $activeClass
                : $inactiveClass }}"
            wire:click="{{ $action }}('{{ $key }}')"
            data-testid="tab-{{ $key }}">
            @if (! empty($tab['icon']))
                <i class="{{ $tab['icon'] }} mr-2"></i>
            @endif
            {{ $tab['label'] ?? $key }}
            @if (! empty($tab['badge']))
                <span class="badge badge-sm ml-2">{{ $tab['badge'] }}</span>
            @endif
        </button>
    @endforeach
</div>
