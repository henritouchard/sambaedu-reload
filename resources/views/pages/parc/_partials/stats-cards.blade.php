<!-- Cartes de statistiques -->
{{-- Story 16.13bis : compteur "Postes migrés". Story 24.7 : compteurs de
     conformité agent (ajoutés à la SUITE, sur la même rangée flex, uniquement
     si au moins un poste est enrôlé). Sur petit écran les tuiles sont compactées
     (icône + valeur + infobulle) pour ne pas masquer le tableau : voir
     x-molecules.stat-tile. --}}
<div class="flex flex-wrap justify-between items-stretch gap-2 xl:gap-4 flex-shrink-0">
    <x-molecules.stat-tile icon="fa-computer" bg="bg-success/10" text="text-success" label="Postes actifs"
        tip="Nombre de postes actuellement actifs dans le parc." :loading="! $statsLoaded">
        {{ $machineStats['active'] ?? 0 }}
    </x-molecules.stat-tile>

    <x-molecules.stat-tile icon="fa-triangle-exclamation" bg="bg-warning/10" text="text-warning" label="Sans groupe"
        tip="Postes qui ne sont rattachés à aucun groupe (ni salle, ni parc logique)." :loading="! $statsLoaded">
        {{ $machineStats['without_group'] ?? 0 }}
    </x-molecules.stat-tile>

    {{-- Story 16.13bis — compteur X/Y postes migrés SE4 → SE5, scoped aux filtres actifs. --}}
    <x-molecules.stat-tile icon="fa-arrows-rotate" bg="bg-info/10" text="text-info" label="Postes migrés"
        tip="Postes ayant basculé de SE4 vers SE5 ; le compte est limité aux filtres actifs." :loading="! $statsLoaded">
        {{ $machineStats['migrated'] ?? 0 }}<span
            class="text-base-content/40 text-base font-normal">/{{ $machineStats['total'] ?? 0 }}</span>
    </x-molecules.stat-tile>

    {{-- Story 24.7 — Conformité agent : périmètre = postes ENRÔLÉS du parc.
             « En écart » = drift+error (Story 27.8 : drifted_allowed retiré —
             convergence stricte). Masqué si aucun poste enrôlé (pas de bruit). --}}
    <x-molecules.stat-tile icon="fa-triangle-exclamation" bg="bg-error/10" text="text-error" label="En écart"
        tip="Postes en dérive de configuration ou en erreur (le statut le plus défavorable est retenu)." :loading="! $statsLoaded">
        {{ $conformityStats['exceptions'] ?? 0 }}
    </x-molecules.stat-tile>

    <x-molecules.stat-tile icon="fa-volume-xmark" bg="bg-warning/10" text="text-warning" label="Muets"
        tip="Postes enrôlés sans interaction récente (éteints?), n'ayant jamais rapporté leur état ou en erreur de communication." :loading="! $statsLoaded">
        {{ ($conformityStats['silent'] ?? 0) + ($conformityStats['never_reported'] ?? 0) }}
    </x-molecules.stat-tile>

    <x-molecules.stat-tile icon="fa-circle-check" bg="bg-success/10" text="text-success" label="Conformes"
        tip="Postes conformes à leur configuration cible sur l'ensemble de leurs ressources." :loading="! $statsLoaded">
        {{ $conformityStats['compliant'] ?? 0 }}
    </x-molecules.stat-tile>

    <x-molecules.stat-tile icon="fa-tower-broadcast" bg="bg-primary/10" text="text-primary" label="Postes enrôlés"
        tip="Postes disposant d'un agent actif sur le canal desired-state." :loading="! $statsLoaded">
        {{ $conformityStats['enrolled'] ?? 0 }}
    </x-molecules.stat-tile>
</div>
