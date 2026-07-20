{{--
    Composant Blade réutilisable — Filtre à options en boutons segmentés.

    À utiliser quand un filtre a PEU d'options (règle projet : 4 au maximum,
    valeur « Tous » comprise). Au-delà, prendre <x-molecules.filter-select> :
    une rangée de plus de 4 boutons déborde sur les écrans étroits.

    L'option active reste ENFONCÉE et colorée (primary adouci), le même
    traitement que l'entrée active de la sidebar — un filtre actif doit se voir
    sans avoir à lire les libellés.

    Props :
      - name    : nom de la propriété Livewire pilotée (posée via $set).
      - options : tableau associatif valeur => libellé, ou valeur => ['label' =>,
                  'icon' =>]. La valeur '' est la convention « pas de filtre ».
      - active  : valeur courante du filtre (la valeur de la propriété Livewire).
      - label   : intitulé facultatif affiché à gauche du groupe.
      - size    : taille DaisyUI des boutons. Défaut 'btn-sm'.

    Le cast est important : $active arrive parfois en bool/int depuis Livewire
    alors que les clés du tableau sont des chaînes — la comparaison est donc
    faite en souple sur la représentation chaîne.

    Usage :
      <x-molecules.filter-toggle name="groupTypeFilter" :active="$groupTypeFilter"
          :options="['all' => 'Tous', 'physical' => ['label' => 'Physiques', 'icon' => 'fa-solid fa-building']]" />
--}}
@props([
    'name' => '',
    'options' => [],
    'active' => '',
    'label' => null,
    'size' => 'btn-sm',
])

@php
    $activeKey = is_bool($active) ? ($active ? '1' : '0') : (string) ($active ?? '');
@endphp

<div {{ $attributes->merge(['class' => 'flex items-center gap-2']) }}>
    @if ($label)
        <span class="text-xs text-base-content/60 shrink-0">{{ $label }}</span>
    @endif
    <div class="join" role="group" @if ($label) aria-label="{{ $label }}" @endif>
        @foreach ($options as $value => $option)
            @php
                $isActive = (string) $value === $activeKey;
                $optionLabel = is_array($option) ? ($option['label'] ?? $value) : $option;
                $optionIcon = is_array($option) ? $option['icon'] ?? null : null;
            @endphp
            <button type="button"
                class="join-item btn {{ $size }} {{ $isActive
                    ? 'btn-active bg-primary/15 text-primary border-primary/30 font-semibold hover:bg-primary/20'
                    : 'text-base-content/70' }}"
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                wire:click="$set('{{ $name }}', '{{ $value }}')"
                data-testid="filter-{{ $name }}-{{ $value === '' ? 'all' : $value }}">
                @if ($optionIcon)
                    <i class="{{ $optionIcon }} text-xs"></i>
                @endif
                {{ $optionLabel }}
            </button>
        @endforeach
    </div>
</div>
