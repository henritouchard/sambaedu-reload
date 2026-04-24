{{--
    Template de modale réutilisable.

    Structure fixe :
      [Header fixe : titre + sous-titre optionnel + bouton fermer]
      [Body (container scrollable par défaut) : sections]
      [Footer fixe : note optionnelle + actions]

    Usage minimal :
        <x-molecules.modal wire:model="isOpen" title="Déléguer un droit">
            <x-molecules.modal.section title="Contexte">
                …
            </x-molecules.modal.section>

            <x-slot:footer>
                <button class="btn btn-ghost" wire:click="close">Annuler</button>
                <button class="btn btn-primary" wire:click="apply">Appliquer</button>
            </x-slot:footer>
        </x-molecules.modal>

    Props :
      - title / subtitle : textes du header. Le sous-titre est optionnel.
      - icon             : classe FontAwesome affichée à gauche du titre
                           (ex: "fa-building text-primary"). Pour un contenu
                           riche, utiliser le slot `titleIcon`.
      - size / height    : largeur et hauteur de la modal-box (classes Tailwind).
      - closeMethod      : méthode Livewire appelée par le bouton ✕ et le backdrop
                           (défaut : `close`).
      - noScroll         : désactive le scroll vertical du body (rare — la modale
                           est par défaut non scrollable en hauteur totale, seul
                           le body interne scrolle si besoin).

    Slots nommés (optionnels) :
      - titleIcon        : override de l'icône à gauche du titre.
      - titleComplement  : élément collé au titre (badge de count, statut, etc.).
      - headerAction     : zone alignée à droite dans le header (tabs, actions).
      - header           : override complet du bloc titre/sous-titre (si besoin
                           d'un layout totalement custom — ignore title/icon/…).
      - footer           : actions (boutons) du footer.
      - footerNote       : note discrète affichée au-dessus des actions du footer.

    Déclenchement :
      Le parent (composant Livewire SFC) expose `public bool $isOpen` et écoute
      l'événement d'ouverture via `#[On('open-xxx-modal')]`, exactement comme
      la delegation-modal actuelle. La modale est bindée ici via `wire:model`.
--}}
@props([
    'title' => null,
    'subtitle' => null,
    'icon' => null,
    'size' => 'max-w-4xl',
    'height' => 'h-[85vh]',
    'closeMethod' => 'close',
    'noScroll' => false,
])

@php
    $wireModel = $attributes->wire('model')->value();
    $bodyScrollClass = $noScroll ? 'overflow-hidden' : 'overflow-y-auto';
@endphp

<div>
    <dialog class="modal relative" x-data="{ open: @entangle($wireModel) }" :class="{ 'modal-open': open }" x-cloak>
        <div
            class="modal-box modal-card w-11/12 {{ $size }} {{ $height }} flex flex-col overflow-hidden p-0">

            {{-- Header (fixe) --}}
            <div class="shrink-0 flex items-start justify-between gap-3 px-6 pt-5 pb-3">
                <div class="min-w-0 flex-1">
                    @isset($header)
                        {{ $header }}
                    @else
                        @if ($title || isset($titleIcon) || isset($titleComplement) || $icon)
                            <h3 class="font-bold text-lg leading-tight inline-flex items-center gap-2 min-w-0">
                                @isset($titleIcon)
                                    {{ $titleIcon }}
                                @elseif ($icon)
                                    <i class="fa-solid {{ $icon }}"></i>
                            @endif
                            @if ($title)
                                <span class="truncate">{{ $title }}</span>
                            @endif
                            @isset($titleComplement)
                                {{ $titleComplement }}
                            @endisset
                            </h3>
                            @endif
                            @if ($subtitle)
                                <p class="text-sm text-base-content/60 mt-0.5">{{ $subtitle }}</p>
                            @endif
                        @endisset
                    </div>
                    @isset($headerAction)
                        <div class="shrink-0 flex items-center gap-2 flex-wrap">
                            {{ $headerAction }}
                        </div>
                    @endisset
                    <button type="button" wire:click="{{ $closeMethod }}" class="btn btn-sm btn-circle btn-ghost shrink-0"
                        aria-label="Fermer">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>

                {{-- Body (container des sections). Scrollable par défaut. --}}
                <div class="flex-1 min-h-0 {{ $bodyScrollClass }} px-6 py-4 flex flex-col gap-4 space-y-3">
                    {{ $slot }}
                </div>

                {{-- Footer (fixe) --}}
                @isset($footer)
                    <div class="shrink-0 px-6 pt-3 pb-4">
                        @isset($footerNote)
                            <div class="text-xs text-base-content/60 mb-2">{{ $footerNote }}</div>
                        @endisset
                        <div class="flex gap-2 justify-end items-center flex-wrap">
                            {{ $footer }}
                        </div>
                    </div>
                @endisset
                <form method="dialog" class="modal-backdrop">
                    <button type="button" wire:click="{{ $closeMethod }}">close</button>
                </form>
            </div>
        </dialog>
    </div>
