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
      - variant : 'boxed' | 'bordered' -> classe tabs-<variant> (défaut 'boxed').
      - size    : classe de taille DaisyUI appliquée à chaque onglet, ex. 'tab-lg' (défaut '').

    Usage :
      <x-molecules.tabs :tabs="$tabs" :active="$tab" />
      <x-molecules.tabs :tabs="$tabs" :active="$activeTab" action="setTab" variant="bordered" size="tab-lg" />
--}}
@props([
    'tabs' => [],
    'active' => '',
    'action' => 'setTab',
    'variant' => 'boxed',
    'size' => '',
])

<div role="tablist" {{ $attributes->merge(['class' => 'tabs tabs-'.$variant]) }}>
    @foreach ($tabs as $key => $tab)
        <button type="button" role="tab"
            class="tab {{ $size }} {{ $active === $key ? 'tab-active' : '' }}"
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
