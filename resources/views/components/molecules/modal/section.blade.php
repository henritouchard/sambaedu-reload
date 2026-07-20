{{--
    Section de modale : titre + <hr> léger + contenu.

    Sizing via flexbox :
      - Par défaut une section est `shrink-0` (prend sa hauteur naturelle).
      - Passer `grow` pour qu'elle remplisse l'espace vertical restant du
        body de la modale (`flex-1 min-h-0`). En général une seule section
        `grow` par modale, les autres restent shrink.
      - Combiner `grow scrollable` pour qu'un contenu long scrolle à
        l'intérieur de la section sans débord. Dans ce cas, la modale
        parente doit être en `noScroll` (sinon le body modale prend la
        main sur le scroll et l'enfant `flex-1` devient indéterminé).

    Props :
      - title        : titre affiché au-dessus du hr (optionnel).
      - icon         : classe FontAwesome affichée à gauche du titre
                       (ex: "fa-building text-primary"). Prop string simple —
                       pour un contenu riche, utiliser le slot `titleIcon`.
      - grow         : la section s'étire pour remplir l'espace restant.
      - scrollable   : active l'overflow-y-auto sur le contenu de la section.
                       Utile surtout combiné à `grow`.
      - dense        : réduit le spacing vertical (utile pour les sections
                       compactes en haut de modale, comme un "contexte").

    Slots :
      - titleIcon        : override de l'icône à gauche (si besoin autre chose
                           qu'un <i> FA).
      - titleComplement  : élément collé au titre (badge de count, statut, etc.).
                           Sémantiquement lié au titre, contrairement à
                           `headerAction` qui est aligné à droite du bloc.
      - headerAction     : contenu aligné à droite (résumés, actions, badges
                           orthogonaux au titre).

    Usage :
        <x-molecules.modal wire:model="isOpen" noScroll …>
            <x-molecules.modal.section title="Contexte" icon="fa-sliders" dense>
                … tight content …
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Liste" icon="fa-users" grow scrollable>
                <x-slot:titleComplement>
                    <span class="badge badge-neutral badge-sm">{{ count($users) }}</span>
                </x-slot:titleComplement>
                <table>…</table>
            </x-molecules.modal.section>

            <x-molecules.modal.section title="Action" icon="fa-wand-magic" dense>
                … dropdown + date …
            </x-molecules.modal.section>
        </x-molecules.modal>
--}}
@props([
    'title' => null,
    'icon' => null,
    'grow' => false,
    'scrollable' => false,
    'dense' => false,
])

@php
    $gap = $dense ? 'gap-2' : 'gap-3';
    $sectionSizing = $grow ? 'flex-1 min-h-0' : 'shrink-0';
    $contentSizing = $grow ? 'flex-1 min-h-0' : '';
    $contentScroll = $scrollable ? 'overflow-y-auto' : '';
    $hasTitleRow = $title || isset($titleIcon) || isset($titleComplement) || isset($headerAction);
@endphp

<section {{ $attributes->merge(['class' => "flex flex-col $gap $sectionSizing"]) }}>
    @if ($hasTitleRow)
        <div class="shrink-0 flex items-center gap-6">
            <span class="label-text font-medium inline-flex items-center gap-2 min-w-0 shrink-0">
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
            </span>
            <hr class="flex-1 border-t border-base-300 dashed" />
            @isset($headerAction)
                <div class="shrink-0 flex items-center gap-2 flex-wrap justify-end">
                    {{ $headerAction }}
                </div>
            @endisset
        </div>
    @endif

    <div class="{{ trim("$contentSizing $contentScroll") }}">
        {{ $slot }}
    </div>
</section>
