@props([
    'wireModel' => null,
    'accept' => 'image/*',
    'label' => 'Choisir un fichier',
    'icon' => 'fa-solid fa-upload',
    'btnClass' => 'btn-primary btn-outline',
    'btnSize' => 'btn-sm',
    'showFilename' => true,
])

@php
    $inputId = 'fileinput-' . uniqid();
@endphp

<div x-data="{ filename: null }" class="inline-flex items-center gap-2">
    {{-- Bouton visible --}}
    <button type="button" @click="$refs.fileInput.click()" class="btn {{ $btnClass }} {{ $btnSize }}">
        <i class="{{ $icon }}"></i>
        {{ $label }}
    </button>

    {{-- Input masqué --}}
    <input type="file" x-ref="fileInput" @if ($wireModel) wire:model="{{ $wireModel }}" @endif
        accept="{{ $accept }}" @change="filename = $event.target.files[0]?.name" class="hidden"
        id="{{ $inputId }}" {{ $attributes }}>

    {{-- Nom du fichier sélectionné --}}
    @if ($showFilename)
        <span x-show="filename" x-text="filename" class="text-sm text-base-content/70 truncate max-w-[150px]"></span>
    @endif

    {{-- Indicateur de chargement Livewire --}}
    @if ($wireModel)
        <span wire:loading wire:target="{{ $wireModel }}" class="text-sm text-info">
            <i class="fa-solid fa-spinner fa-spin"></i>
        </span>
    @endif
</div>
