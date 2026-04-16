{{--
Avatar avec icône FontAwesome réutilisable

Usage:
<x-atoms.icon-avatar icon="fa-cube" bgColor="bg-primary/10" textColor="text-primary" size="w-8 h-8" iconSize="text-sm" />

Props:
- icon: Classe FontAwesome (ex: "fa-cube", "fa-folder-tree")
- bgColor: Classe de couleur de fond (ex: "bg-primary/10", "bg-secondary/10")
- textColor: Classe de couleur du texte (ex: "text-primary", "text-secondary")
- size: Dimensions du conteneur (ex: "w-8 h-8", "w-16 h-16")
- iconSize: Taille de l'icône (ex: "text-sm", "text-2xl")
--}}

@props([
    'icon' => 'fa-cube',
    'bgColor' => 'bg-primary/10',
    'textColor' => 'text-primary',
    'size' => 'w-8 h-8',
    'iconSize' => 'text-sm',
])

<div class="{{ $bgColor }} {{ $textColor }} rounded-xl {{ $size }} flex items-center justify-center">
    <i class="fa-solid {{ $icon }} {{ $iconSize }}"></i>
</div>
