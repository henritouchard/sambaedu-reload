@props([
    // Statut de conformité agent (Story 24.7) :
    // valeurs enum AgentResourceStatus (compliant|drift|drifted_allowed|error)
    // + dérivés (never_reported|silent) + 'neutral' (poste non enrôlé / hors
    // conformité). null = neutre.
    'status' => null,
])

@php
    // Mapping centralisé statut → badge DaisyUI (jamais dupliqué dans les
    // vues). `drifted_allowed` a une distinction visuelle propre (info,
    // dérive TOLÉRÉE — piège 8) distincte de l'écart réel (drift/error).
    $map = [
        'compliant' => ['class' => 'badge-success', 'icon' => 'fa-circle-check', 'label' => 'Conforme'],
        'drift' => ['class' => 'badge-error', 'icon' => 'fa-triangle-exclamation', 'label' => 'En écart'],
        'error' => ['class' => 'badge-error', 'icon' => 'fa-circle-xmark', 'label' => 'Erreur'],
        'drifted_allowed' => ['class' => 'badge-info', 'icon' => 'fa-circle-info', 'label' => 'Dérive tolérée'],
        'never_reported' => ['class' => 'badge-ghost', 'icon' => 'fa-circle-question', 'label' => 'Jamais rapporté'],
        'silent' => ['class' => 'badge-warning', 'icon' => 'fa-volume-xmark', 'label' => 'Muet'],
        'neutral' => ['class' => 'badge-ghost', 'icon' => 'fa-minus', 'label' => '—'],
    ];

    $key = $status ?? 'neutral';
    $cfg = $map[$key] ?? $map['neutral'];
@endphp

<span {{ $attributes->merge(['class' => 'badge badge-sm gap-1 ' . $cfg['class']]) }}
      title="{{ $cfg['label'] }}" aria-label="{{ $cfg['label'] }}">
    <i class="fa-solid {{ $cfg['icon'] }}"></i>
    {{ $cfg['label'] }}
</span>
