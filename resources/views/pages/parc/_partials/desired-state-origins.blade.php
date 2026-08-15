{{--
    Story 37.1 — Rendu des badges d'ORIGINE d'un item d'état cible (raccourci ou
    application), partagé par les onglets « État cible » de la fiche poste et de la
    page parc. Vocabulaire d'origine unifié (décision D6, palette DaisyUI iso
    conventions existantes : salle badge-warning fa-door-open, parc logique
    badge-primary fa-layer-group — iso `shortcuts/.../assigned-groups.blade.php`).

    Entrée : $origins = list<array{kind, group_id?, group_name?, group_physical?, via?}>
    triée par spécificité (index 0 = badge PRINCIPAL). Multi-origines (piège #5) :
    badge principal rendu en entier + « +N » en tooltip listant les autres.
--}}
@php
    /**
     * Descripteur visuel d'une origine → {class, icon, text, group_id?}.
     * Consultation pure : aucun état, aucun formulaire.
     */
    $describe = function (array $o): array {
        return match ($o['kind']) {
            'workstation' => ['class' => 'badge-success', 'icon' => 'fa-computer', 'text' => 'Ce poste'],
            'group_self' => ['class' => 'badge-success', 'icon' => 'fa-layer-group', 'text' => 'Ce parc'],
            'room_self' => ['class' => 'badge-warning', 'icon' => 'fa-door-open', 'text' => 'Cette salle'],
            'group_profile' => ['class' => 'badge-info', 'icon' => 'fa-cubes', 'text' => 'via profil ' . ($o['via'] ?? '')],
            'logical_group' => ['class' => 'badge-primary', 'icon' => 'fa-layer-group', 'text' => $o['group_name'] ?? 'Parc', 'group_id' => $o['group_id'] ?? null],
            'physical_group' => ['class' => 'badge-warning', 'icon' => 'fa-door-open', 'text' => $o['group_name'] ?? 'Salle', 'group_id' => $o['group_id'] ?? null],
            'dependency' => ['class' => 'badge-info badge-outline', 'icon' => 'fa-diagram-project', 'text' => $o['via'] ? ('Dépendance de ' . $o['via']) : 'Dépendance'],
            'parc_default' => ['class' => 'badge-ghost', 'icon' => 'fa-globe', 'text' => 'Socle commun', 'tip' => 'Défaut appliqué à tous les postes — configurable dans Réglages → Configuration par défaut du parc.'],
            'file_policy' => ['class' => 'badge-ghost', 'icon' => 'fa-cloud', 'text' => 'Politique de fichiers', 'tip' => 'Posé sur tous les postes par le réglage global — Réglages → Fichiers.'],
            'upstream_locked' => ['class' => 'badge-neutral', 'icon' => 'fa-lock', 'text' => 'Contrat amont', 'tip' => 'Verrouillé par le contrat amont.'],
            'upstream_permissive' => ['class' => 'badge-info', 'icon' => 'fa-lock-open', 'text' => 'Contrat amont', 'tip' => 'Proposé par le contrat amont (permissif).'],
            'upstream' => ['class' => 'badge-neutral', 'icon' => 'fa-lock', 'text' => 'Contrat amont', 'tip' => 'Ordre d\'installation du contrat amont.'],
            default => ['class' => 'badge-ghost', 'icon' => 'fa-circle-question', 'text' => 'Origine inconnue'],
        };
    };

    $primary = $describe($origins[0]);
    $others = array_slice($origins, 1);
@endphp

<div class="flex items-center gap-1 flex-wrap">
    @php $tip = $primary['tip'] ?? null; @endphp
    <span @class(['badge badge-sm gap-1', $primary['class'], 'tooltip' => (bool) $tip])
        @if ($tip) data-tip="{{ $tip }}" @endif>
        <i class="fa-solid {{ $primary['icon'] }} text-xs"></i>
        @if (! empty($primary['group_id']))
            <a href="{{ route('app.parc.groups.show', $primary['group_id']) }}" class="link link-hover">{{ $primary['text'] }}</a>
        @else
            {{ $primary['text'] }}
        @endif
    </span>

    @if (count($others) > 0)
        @php
            $otherTexts = collect($others)->map(fn ($o) => $describe($o)['text'])->implode(' • ');
        @endphp
        <span class="badge badge-sm badge-ghost tooltip before:max-w-xs before:whitespace-normal"
            data-tip="Aussi : {{ $otherTexts }}">
            +{{ count($others) }}
        </span>
    @endif
</div>
