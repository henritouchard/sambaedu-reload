@props([
    'icon',                 // classe FontAwesome, ex: fa-computer
    'bg' => 'bg-primary/10', // classe littérale (Tailwind JIT : jamais dynamique)
    'text' => 'text-primary',
    'label' => '',
    'tip' => null,           // infobulle (title) — défaut = label
    'loading' => false,      // true → spinner à la place de la valeur
    'active' => false,       // surligne la tuile (filtre rapide sélectionné)
    'clickable' => false,    // rend la tuile interactive (curseur + hover)
])

{{-- Tuile de statistique.
     - < xl : icône colorée + valeur ; le libellé passe en infobulle (hover).
     - xl+  : design complet, libellé visible sous forme longue.
     Le libellé masqué en dessous de xl gagne la largeur nécessaire pour tenir
     5 tuiles de front sans manger la hauteur (le tableau reste visible).
     Quand `clickable`, la tuile absorbe un `wire:click` fourni par le parent
     (fusion d'attributs) et sert de filtre rapide ; `active` la surligne. --}}
<div
    @if ($clickable) role="button" tabindex="0" @endif
    {{ $attributes->merge([
        'class' => 'card bg-base-100 shadow-sm shrink-0 transition'
            . ($clickable ? ' cursor-pointer hover:shadow-md hover:-translate-y-0.5' : '')
            . ($active ? ' ring-2 ring-primary ring-offset-1 ring-offset-base-100 bg-primary/5' : ''),
    ]) }}
    title="{{ $tip ?? $label }}">
    <div class="card-body py-2 px-3 xl:py-4 xl:px-4">
        <div class="flex items-center gap-2 xl:gap-3">
            <div class="w-9 h-9 xl:w-10 xl:h-10 rounded-lg {{ $bg }} flex items-center justify-center shrink-0">
                <i class="fa-solid {{ $icon }} {{ $text }}"></i>
            </div>
            <div>
                <div class="text-sm text-base-content/60 hidden xl:block whitespace-nowrap">{{ $label }}</div>
                <div class="text-xl xl:text-2xl font-bold leading-tight">
                    @if ($loading)
                        <span class="loading loading-spinner loading-xs align-middle opacity-60"></span>
                    @else
                        {{ $slot }}
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>
