@props([
    'href' => '#',
    'icon' => 'fa-solid fa-gear',
    'iconColor' => 'primary',
    'title' => '',
    'description' => '',
    'badge' => null,
    'badgeColor' => null,
    'testid' => null,
])

@php
    $color = $iconColor ?: 'primary';
    $badgeC = $badgeColor ?: $color;
@endphp

<a href="{{ $href }}"
    @if ($testid) data-testid="{{ $testid }}" @endif
    class="card bg-base-100 shadow-md hover:shadow-xl transition-all duration-200 hover:-translate-y-1 border border-base-300/50 hover:border-{{ $color }}/40">
    <div class="card-body">
        <div class="flex items-center gap-4 mb-2">
            <div class="w-12 h-12 rounded-xl bg-{{ $color }}/10 flex items-center justify-center shrink-0">
                <i class="{{ $icon }} text-{{ $color }} text-xl"></i>
            </div>
            <h2 class="card-title text-lg leading-tight">{{ $title }}</h2>
        </div>
        <p class="text-sm text-base-content/70">{{ $description }}</p>
        @if ($badge)
            <div class="card-actions justify-end mt-4">
                <span class="badge badge-{{ $badgeC }} badge-outline">{{ $badge }}</span>
            </div>
        @endif
    </div>
</a>
