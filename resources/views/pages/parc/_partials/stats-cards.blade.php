<!-- Cartes de statistiques -->
{{-- Chaque tuile est un FILTRE RAPIDE cliquable : un clic restreint le tableau
     à cette catégorie (« montre uniquement ce type »), un second clic la
     désélectionne. La tuile active est surlignée. L'ordre suit une lecture en
     entonnoir : périmètre (Actifs → Enrôlés → Conformes → Migrés) puis
     catégories à traiter (Sans groupe, En écart, Muets).
     Story 16.13bis : compteur « Postes migrés ». Story 24.7 : compteurs de
     conformité agent (issus de ConformityService::summary). Sur petit écran
     les tuiles sont compactées (icône + valeur + infobulle) pour ne pas
     masquer le tableau : voir x-molecules.stat-tile. --}}
<div class="flex flex-wrap justify-between items-stretch gap-2 xl:gap-4 flex-shrink-0">
    <x-molecules.stat-tile icon="fa-computer" bg="bg-success/10" text="text-success" label="Postes actifs"
        tip="Postes actuellement actifs dans le parc. Cliquer pour ne montrer qu'eux." :loading="! $statsLoaded"
        clickable :active="$cardFilter === 'active'" wire:click="filterByCard('active')">
        {{ $machineStats['active'] ?? 0 }}
    </x-molecules.stat-tile>

    <x-molecules.stat-tile icon="fa-tower-broadcast" bg="bg-primary/10" text="text-primary" label="Postes enrôlés"
        tip="Postes disposant d'un agent actif sur le canal desired-state. Cliquer pour filtrer." :loading="! $statsLoaded"
        clickable :active="$cardFilter === 'enrolled'" wire:click="filterByCard('enrolled')">
        {{ $conformityStats['enrolled'] ?? 0 }}
    </x-molecules.stat-tile>

    <x-molecules.stat-tile icon="fa-circle-check" bg="bg-success/10" text="text-success" label="Conformes"
        tip="Postes conformes à leur configuration cible sur l'ensemble de leurs ressources. Cliquer pour filtrer." :loading="! $statsLoaded"
        clickable :active="$cardFilter === 'compliant'" wire:click="filterByCard('compliant')">
        {{ $conformityStats['compliant'] ?? 0 }}
    </x-molecules.stat-tile>

    {{-- Story 16.13bis — compteur X/Y postes migrés SE4 → SE5 (scopé OS/groupe). --}}
    <x-molecules.stat-tile icon="fa-arrows-rotate" bg="bg-info/10" text="text-info" label="Postes migrés"
        tip="Postes ayant basculé de SE4 vers SE5. Cliquer pour ne montrer qu'eux." :loading="! $statsLoaded"
        clickable :active="$cardFilter === 'migrated'" wire:click="filterByCard('migrated')">
        {{ $machineStats['migrated'] ?? 0 }}<span
            class="text-base-content/40 text-base font-normal">/{{ $machineStats['total'] ?? 0 }}</span>
    </x-molecules.stat-tile>

    <x-molecules.stat-tile icon="fa-triangle-exclamation" bg="bg-warning/10" text="text-warning" label="Sans groupe"
        tip="Postes rattachés à aucun groupe (ni salle, ni parc logique). Cliquer pour filtrer." :loading="! $statsLoaded"
        clickable :active="$cardFilter === 'without_group'" wire:click="filterByCard('without_group')">
        {{ $machineStats['without_group'] ?? 0 }}
    </x-molecules.stat-tile>

    {{-- Story 24.7 — « En écart » = drift+error (le statut le plus défavorable
         est retenu ; Story 27.8 : convergence stricte, plus de dérive tolérée). --}}
    <x-molecules.stat-tile icon="fa-triangle-exclamation" bg="bg-error/10" text="text-error" label="En écart"
        tip="Postes enrôlés en dérive de configuration ou en erreur. Cliquer pour filtrer." :loading="! $statsLoaded"
        clickable :active="$cardFilter === 'exceptions'" wire:click="filterByCard('exceptions')">
        {{ $conformityStats['exceptions'] ?? 0 }}
    </x-molecules.stat-tile>

    <x-molecules.stat-tile icon="fa-volume-xmark" bg="bg-warning/10" text="text-warning" label="Muets"
        tip="Postes enrôlés sans interaction récente (éteints ?) ou n'ayant jamais rapporté leur état. Cliquer pour filtrer." :loading="! $statsLoaded"
        clickable :active="$cardFilter === 'silent'" wire:click="filterByCard('silent')">
        {{ ($conformityStats['silent'] ?? 0) + ($conformityStats['never_reported'] ?? 0) }}
    </x-molecules.stat-tile>
</div>
