@props([
    'title' => '',
    'icon' => 'fa-solid fa-folder',
    'color' => 'primary',
    'description' => '',
])

@php
    $color = $color ?: 'primary';
@endphp

<section class="relative rounded-2xl border-2 border-{{ $color }}/30 bg-gradient-to-br from-{{ $color }}/5 via-base-100 to-base-100 p-6 shadow-sm">
    <div class="absolute top-0 left-6 -translate-y-1/2 flex items-center gap-2 bg-base-100 px-3 py-1 rounded-full border border-{{ $color }}/40">
        <i class="{{ $icon }} text-{{ $color }}"></i>
        <h2 class="text-sm font-bold uppercase tracking-wider text-{{ $color }}">{{ $title }}</h2>
    </div>

    @if ($description)
        <p class="text-sm text-base-content/60 mt-3 mb-4">{{ $description }}</p>
    @else
        <div class="mt-3"></div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        {{ $slot }}
    </div>
</section>
