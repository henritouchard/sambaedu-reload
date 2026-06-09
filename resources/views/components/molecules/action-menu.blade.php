@props([
    'label' => 'Actions',
    'icon' => 'fa-ellipsis-vertical',
    'align' => 'end',
    'size' => 'sm',
    'variant' => 'primary',
    'width' => 'w-64',
    'testid' => null,
])

{{--
    Bouton d'action en dropdown réutilisable (DaisyUI focus-dropdown, pattern
    iso header /users — se ferme au clic extérieur). Le slot reçoit les items
    sous forme de <li>...</li>.

    Usage :
      <x-molecules.action-menu label="Actions" icon="fa-bolt">
          <li><button wire:click="...">…</button></li>
      </x-molecules.action-menu>
--}}
<div {{ $attributes->merge(['class' => 'dropdown dropdown-' . $align]) }}
    @if ($testid) data-testid="{{ $testid }}" @endif>
    <label tabindex="0" class="btn btn-{{ $size }} btn-{{ $variant }} gap-1">
        <i class="fa-solid {{ $icon }}"></i>
        {{ $label }}
        <i class="fa-solid fa-chevron-down text-xs opacity-70"></i>
    </label>
    <ul tabindex="0"
        class="dropdown-content z-[20] menu p-2 shadow bg-base-100 rounded-box {{ $width }} border border-base-300 mt-1">
        {{ $slot }}
    </ul>
</div>
