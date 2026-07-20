{{--
    Composant Blade réutilisable — Filtre à options en liste déroulante.

    À utiliser quand un filtre a BEAUCOUP d'options (règle projet : plus de 4, ou
    un nombre variable — catégories, groupes, OS…). En dessous, prendre
    <x-molecules.filter-toggle>, qui rend les options visibles sans clic.

    Le libellé est posé À L'INTÉRIEUR du select via une option « tous » explicite
    (`placeholder`), pas au-dessus : dans une barre de filtre compacte, un label
    surmonté ajoute une ligne pour une information que le placeholder porte déjà.
    C'est l'inverse de la convention des FORMULAIRES de saisie, où le label reste
    au-dessus du champ.

    Props :
      - model       : nom de la propriété Livewire à lier (wire:model.live).
      - options     : tableau associatif valeur => libellé, ou liste simple de
                      chaînes (la valeur sert alors de libellé).
      - placeholder : libellé de l'option vide, ex. 'Toutes les catégories'.
                      Passer null pour ne pas émettre d'option vide (filtre
                      obligatoire, ex. le sélecteur de dépôt).
      - size        : taille DaisyUI. Défaut 'select-sm'.
      - width       : classes de largeur. Défaut 'w-auto'.

    Usage :
      <x-molecules.filter-select model="categoryFilter" :options="$categories"
          placeholder="Toutes les catégories" />
--}}
@props([
    'model' => null,
    'options' => [],
    'placeholder' => 'Tous',
    'size' => 'select-sm',
    'width' => 'w-auto',
])

<select class="select select-bordered {{ $size }} {{ $width }}"
    @if ($model) wire:model.live="{{ $model }}" @endif
    data-testid="filter-{{ $model ?? 'select' }}"
    {{ $attributes }}>
    @if ($placeholder !== null)
        <option value="">{{ $placeholder }}</option>
    @endif
    @foreach ($options as $value => $optionLabel)
        {{-- Liste simple (clés numériques) : la valeur EST le libellé. --}}
        <option value="{{ is_int($value) ? $optionLabel : $value }}">{{ $optionLabel }}</option>
    @endforeach
</select>
