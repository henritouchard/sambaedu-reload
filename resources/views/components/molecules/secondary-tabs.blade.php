{{--
    Composant Blade réutilisable — Onglets SECONDAIRES (niveau imbriqué).

    À utiliser pour poser une seconde barre d'onglets À L'INTÉRIEUR d'un onglet
    de premier niveau (x-molecules.tabs, barre soulignée). Le rendu délibérément
    différent — une rangée de cartes façon « stat cards » DaisyUI (icône dans une
    pastille arrondie colorée + libellé, carte active surlignée en `ring-primary`)
    — évite toute confusion avec la barre soulignée du niveau au-dessus.

    Piloté par une propriété Livewire `$tab` comme la barre primaire
    (convention : #[Url(keep:true)] public string $subTab).

    Props :
      - tabs   : tableau associatif clé => définition, ex.
                 ['drives' => ['label' => 'Lecteurs', 'icon' => 'fa-solid fa-hard-drive', 'badge' => 3, 'desc' => 'montés au logon']]
                 Clés de définition (toutes optionnelles sauf 'label') :
                   'label' : texte principal de l'onglet (défaut = clé).
                   'icon'  : classe FontAwesome de la pastille.
                   'badge' : compteur affiché à droite (masqué si null/0).
                   'desc'  : sous-titre discret sous le libellé.
                   'bg' / 'text' : couleur littérale de la pastille au repos
                     (ex. 'bg-info/10' / 'text-info'). Tailwind JIT : la valeur
                     doit être écrite littéralement dans le tableau, jamais
                     construite dynamiquement. La carte ACTIVE force toujours la
                     teinte primaire, quelle que soit la couleur au repos.
                 La visibilité conditionnelle (@can) se gère en AMONT en
                 n'ajoutant pas l'onglet interdit au tableau.
      - active : clé de l'onglet actif (la valeur de $subTab).
      - action : nom de la méthode Livewire appelée avec la clé (défaut 'setSubTab').

    Usage :
      <x-molecules.secondary-tabs :tabs="[
          'drives'  => ['label' => 'Lecteurs réseau', 'icon' => 'fa-solid fa-hard-drive', 'badge' => 3],
          'lockers' => ['label' => 'Casiers', 'icon' => 'fa-solid fa-box-archive'],
      ]" :active="$subTab" action="setSubTab" />
--}}
@props([
    'tabs' => [],
    'active' => '',
    'action' => 'setSubTab',
])

<div role="tablist" {{ $attributes->merge(['class' => 'flex flex-wrap items-stretch gap-2 xl:gap-3']) }}>
    @foreach ($tabs as $key => $tab)
        @php
            $isActive = $active === $key;
            // Pastille : teinte au repos personnalisable par onglet ; l'onglet
            // actif force le primaire pour un point d'ancrage visuel unique.
            $figureBg = $isActive ? 'bg-primary/15' : ($tab['bg'] ?? 'bg-base-200');
            $figureText = $isActive ? 'text-primary' : ($tab['text'] ?? 'text-base-content/50');
        @endphp
        <button type="button" role="tab"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            wire:click="{{ $action }}('{{ $key }}')"
            data-testid="secondary-tab-{{ $key }}"
            class="card flex-1 min-w-[9rem] bg-base-100 border shadow-sm text-left
                cursor-pointer transition duration-150 focus:outline-none
                focus-visible:ring-2 focus-visible:ring-primary/50
                {{ $isActive
                    ? 'border-primary/30 ring-2 ring-primary ring-offset-1 ring-offset-base-100 bg-primary/5'
                    : 'border-base-300 hover:shadow-md hover:-translate-y-0.5 hover:border-base-300' }}">
            <div class="card-body flex-row items-center gap-3 py-2.5 px-3">
                <div class="w-9 h-9 rounded-lg {{ $figureBg }} flex items-center justify-center shrink-0">
                    @if (! empty($tab['icon']))
                        <i class="{{ $tab['icon'] }} {{ $figureText }}"></i>
                    @endif
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-semibold leading-tight truncate
                        {{ $isActive ? 'text-primary' : 'text-base-content' }}">
                        {{ $tab['label'] ?? $key }}
                    </div>
                    @if (! empty($tab['desc']))
                        <div class="text-xs text-base-content/50 truncate">{{ $tab['desc'] }}</div>
                    @endif
                </div>
                @if (! empty($tab['badge']))
                    <span class="badge badge-sm shrink-0 {{ $isActive ? 'badge-primary' : 'badge-ghost' }}">
                        {{ $tab['badge'] }}
                    </span>
                @endif
            </div>
        </button>
    @endforeach
</div>
