@props([
    'position' => 'top',
    'color' => 'primary',
    'size' => 'sm',
    'trigger' => 'hover',
    'icon' => null,
    'iconClass' => 'fa-solid fa-circle-info text-primary/70 ml-2',
    'label' => null,
    'labelClass' => 'label-text font-semibold',
])

@php
    $tooltipId = 'tooltip-' . uniqid();
@endphp

<div class="inline-flex items-center w-fit tooltip-trigger" data-tooltip-id="{{ $tooltipId }}"
    data-tooltip-position="{{ $position }}">
    @if ($label)
        <span class="{{ $labelClass }}">{{ $label }}</span>
    @endif
    @if ($icon)
        <i class="{{ $iconClass }}"></i>
    @elseif(!$label)
        {{ $trigger }}
    @endif

    @if ($slot->isNotEmpty())
        <div id="{{ $tooltipId }}"
            class="tooltip-content fixed z-[9999] px-3 py-2 text-sm bg-base-300 text-base rounded-lg shadow-lg w-max max-w-[50dvw] pointer-events-none opacity-0 invisible transition-opacity duration-150"
            role="tooltip">
            {{ $slot }}
        </div>
    @endif
</div>
