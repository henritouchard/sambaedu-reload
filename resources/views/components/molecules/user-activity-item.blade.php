@props([
    'initials' => '',
    'color' => 'primary',
    'imageUrl' => null,
    'name' => '',
    'action' => '',
    'timeAgo' => ''
])

<div wire:ignore class="flex items-center gap-3">
    <x-atoms.avatar-placeholder 
        :initials="$initials"
        :color="$color"
        :image-url="$imageUrl"
        size="w-8"
    />
    <div class="flex-1">
        <p class="text-sm font-medium">{{ $name }} {{ $action }}</p>
        <p class="text-xs text-base-content/60">{{ $timeAgo }}</p>
    </div>
</div>
