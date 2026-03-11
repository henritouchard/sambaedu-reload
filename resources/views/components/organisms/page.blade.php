@props([
    'scrollable' => true,
    'noPadding' => false,
    'title' => '',
    'description' => '',
    'icon' => '',
    'back' => '',
    'backUrl' => '',
    'backText' => '',
    'actions' => null,
])

{{-- Map backUrl to back if provided --}}
@php
    $back = $back ?: $backUrl;
@endphp

<div class="min-h-full px-2 {{ $scrollable ? 'overflow-y-auto' : 'h-full overflow-y-hidden' }} pb-4 flex flex-col">
    <div class="flex justify-between items-start mb-8">
        <div class="">
            <div class="flex items-center gap-2">
                @if ($back)
                    <a href="{{ $back }}" class="btn btn-ghost btn-md p-1">
                        <i class="fa-solid fa-arrow-left text-xl"></i>
                    </a>
                @endif
                <h1 class="text-3xl font-bold text-base-content flex items-center gap-2">
                    @if ($icon)
                        <i class="{{ $icon }} text-primary"></i>
                    @endif
                    {{ $title }}
                </h1>
            </div>
            <p class="text-base-content/60 mt-2">{{ $description }}</p>
        </div>

        @if (isset($actions))
            <div class="flex items-center gap-2">
                {{ $actions }}
            </div>
        @endif
    </div>

    @if (!$scrollable)
        <div id="page-content" class="flex flex-col flex-1 min-h-0">
            {{ $slot }}
        </div>
    @else
        <div id="page-content">
            {{ $slot }}
        </div>
    @endif
</div>
