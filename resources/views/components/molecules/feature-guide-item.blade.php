@props([
    // SambaPermission requise pour utiliser la fonctionnalité (objet enum).
    'permission',
    // Contenu how-to (peut être vide → fallback « Guide à venir »).
    'objective' => '',
    'steps' => [],
    // Lien vers la vraie page (URL déjà résolue) + son libellé.
    'link' => null,
    'linkLabel' => null,
    // Décision d'accès INJECTABLE (AC7). Si `null`, elle est dérivée de
    // l'autorisation Spatie GLOBALE de l'utilisateur connecté
    // (`$user->can($permission->value)`). Les futures stories 40.x « machines /
    // wpkg » passeront ici un booléen calculé par
    // `PermissionService::canOnWorkstationGroup()` (mode scopé) SANS toucher ce
    // composant : la décision n'est jamais codée en dur sur `can()`.
    'unlocked' => null,
])

@php
    /** @var \App\Enums\SambaPermission $permission */
    $isUnlocked = $unlocked === null
        ? (auth()->user()?->can($permission->value) ?? false)
        : (bool) $unlocked;

    $permValue = $permission->value;
    // On rend TOUJOURS la fonctionnalité : jamais de masquage (AC4). L'état
    // verrouillé se traduit par un grisé + un badge cadenas, pas par un @can.
@endphp

<div data-testid="feature-{{ $permValue }}"
    class="card border shadow-sm transition-all duration-200
        {{ $isUnlocked
            ? 'bg-base-100 border-primary/30 hover:shadow-md'
            : 'bg-base-200/40 border-base-300 opacity-50' }}">
    <div class="card-body gap-3">

        {{-- En-tête : intitulé (ancré sur l'enum) + badge d'état --}}
        <div class="flex items-start justify-between gap-3">
            <h3 class="card-title text-base leading-tight flex items-center gap-2">
                <i class="fa-solid {{ $isUnlocked ? 'fa-circle-check text-success' : 'fa-lock text-base-content/50' }}"></i>
                {{ $permission->label() }}
            </h3>

            @unless ($isUnlocked)
                <span data-testid="feature-lock-{{ $permValue }}"
                    class="badge badge-warning badge-outline gap-1 shrink-0">
                    <i class="fa-solid fa-lock"></i> Verrouillé
                </span>
            @endunless
        </div>

        {{-- Objectif --}}
        @if ($objective)
            <p class="text-sm text-base-content/70">{{ $objective }}</p>
        @endif

        {{-- Étapes how-to (toujours lisibles, y compris verrouillé) --}}
        @if (!empty($steps))
            <ol class="list-decimal list-inside space-y-1 text-sm text-base-content/80">
                @foreach ($steps as $step)
                    <li>{{ $step }}</li>
                @endforeach
            </ol>
        @else
            <p class="text-sm italic text-base-content/50">Guide à venir</p>
        @endif

        {{-- Rappel du droit requis lorsque la fonctionnalité est verrouillée --}}
        @unless ($isUnlocked)
            <p class="text-xs font-medium text-warning flex items-center gap-1">
                <i class="fa-solid fa-key"></i>
                Droit requis : {{ $permission->label() }}
            </p>
        @endunless

        {{-- Lien vers la vraie page : actif si déverrouillé, désactivé sinon --}}
        @if ($link)
            <div class="card-actions justify-end mt-1">
                @if ($isUnlocked)
                    <a href="{{ $link }}" class="btn btn-primary btn-sm gap-2">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i>
                        {{ $linkLabel ?? 'Ouvrir la page' }}
                    </a>
                @else
                    <span class="btn btn-sm gap-2 btn-disabled pointer-events-none"
                        aria-disabled="true" tabindex="-1">
                        <i class="fa-solid fa-lock"></i>
                        {{ $linkLabel ?? 'Ouvrir la page' }}
                    </span>
                @endif
            </div>
        @endif

    </div>
</div>
