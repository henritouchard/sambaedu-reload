<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use App\Services\Parc\WorkstationGroupService;
use App\Jobs\SyncWorkstationGroupsFromAd;
use App\Components\Traits\WithToasts;
use App\Enums\WorkstationEnvironment;
use App\Models\WorkstationGroup;
use App\Services\Agent\Reporting\ConformityService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

new #[Title('Gestion du Parc - SE4FS')] class extends Component {
    use WithToasts;
    use WithPagination;

    private WorkstationGroupService $parcService;

    // Onglet actif — toujours reflété dans l'URL (?tab=), deep-link supporté.
    #[Url(keep: true)]
    public string $tab = 'groups';

    /** Onglets valides de la page (allow-list du switch). */
    private const TABS = ['groups', 'machines', 'printers'];

    // Filtres machines
    #[Url]
    public string $machineSearch = '';
    #[Url]
    public string $osFilter = '';
    #[Url]
    public ?int $groupFilter = null;
    // Filtre rapide « carte » — piloté par le clic sur les tuiles de
    // statistiques de l'onglet Postes. Mutuellement exclusif (« montre
    // uniquement ce type »). Absorbe les anciens filtres migration (16.13bis)
    // et conformité (24.7), désormais représentés par des cartes cliquables.
    // Valeurs admises : '' (tous), 'active', 'enrolled', 'compliant',
    // 'migrated', 'without_group', 'exceptions', 'silent'.
    #[Url]
    public string $cardFilter = '';
    // Filtre par état de présence (canal agent). Valeurs admises : '' (tous),
    // 'online' (allumé), 'off' (éteint = extinction signalée ou muet).
    #[Url]
    public string $presenceFilter = '';

    /** Cartes-filtres valides de l'onglet Postes (allow-list du clic). */
    private const CARD_FILTERS = [
        'active', 'enrolled', 'compliant', 'migrated', 'without_group', 'exceptions', 'silent',
    ];

    // Filtres groupes
    #[Url]
    public string $groupSearch = '';
    // 'all' (physiques + logiques), 'physical' ou 'logical'
    #[Url]
    public string $groupTypeFilter = 'all';

    // Sélection
    public array $selectedMachines = [];
    public array $selectedGroups = [];

    // Story 3.11 — Réinstallation OS de la sélection (multi-sélection inventaire).
    public bool $reinstallModalOpen = false;
    public string $reinstallTarget = '';
    public string $reinstallWhen = 'now';
    public ?string $reinstallScheduledAt = null;

    // Pagination
    #[Url]
    public int $machinesPerPage = 20;
    #[Url]
    public int $groupsPerPage = 20;
    public array $allowedPerPage = [10, 20, 50, 100];

    // Données
    public array $availableOs = [];
    /** @var Collection<WorkstationGroup> */
    public Collection $availableGroups;
    public array $machineStats = [];
    public array $groupStats = [];
    // Story 24.7 — compteurs de conformité agent (postes enrôlés du parc).
    public array $conformityStats = [];

    // États
    public bool $statsLoaded = false;
    public bool $showGroupModal = false;

    public function boot(WorkstationGroupService $parcService): void
    {
        $this->parcService = $parcService;
    }

    public function mount(): void
    {
        $this->availableGroups = collect();
        $this->loadFiltersData();

        if (session()->has('toast')) {
            $toastData = session('toast');
            $this->toast($toastData['type'] ?? 'info', $toastData['title'] ?? 'Notification', $toastData['message'] ?? '');
        }
    }

    public function loadFiltersData(): void
    {
        try {
            $this->availableOs = $this->parcService->getAvailableOs()->toArray();
            // Story 7.1 — Review #7 : scoper le dropdown "Filtrer par groupe"
            // au périmètre du user courant pour éviter la fuite des noms de salles.
            $this->availableGroups = $this->parcService->getRootGroupsForSelect($this->scopedUser());
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur chargement filtres: ' . $e->getMessage());
            $this->availableOs = [];
            $this->availableGroups = collect();
        }
    }

    public function getMachineActionsProperty(): Collection
    {
        return collect($this->parcService->getAvailableMachineActions())
            ->map(static fn(array $action): object => (object) $action)
            ->values();
    }

    public function loadStats(): void
    {
        if ($this->statsLoaded) {
            return;
        }

        try {
            // Les tuiles sont des filtres rapides (cliquables) : leurs
            // compteurs restent l'inventaire du parc (scopé OS/groupe pour le
            // dénominateur « Postes migrés »), indépendamment de la carte
            // active — sinon cliquer une carte fausserait son propre chiffre.
            $this->machineStats = $this->parcService->getMachineStats(
                os: $this->osFilter ?: null,
                groupId: $this->groupFilter,
            );
            $this->groupStats = $this->parcService->getGroupStats();
            // Story 24.7 — compteurs de conformité agent (périmètre = postes
            // enrôlés du parc), en requêtes agrégées (zéro N+1, piège 11).
            $this->conformityStats = app(ConformityService::class)->summary();
            $this->statsLoaded = true;
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur chargement stats: ' . $e->getMessage());
            $this->machineStats = [];
            $this->groupStats = [];
            $this->conformityStats = [];
            $this->statsLoaded = true;
        }
    }

    /**
     * Story 24.7 — worst-status de conformité par poste pour la PAGE courante
     * (badge tableau) : UNE requête agrégée sur les ids paginés (piège 11),
     * jamais une relation lazy par ligne. Retourne `[id => statut affichable]`
     * où le statut est l'enum, un dérivé (never_reported/silent) ou 'neutral'
     * (poste non enrôlé). La résolution silent/never-reported reproduit la
     * précédence de {@see ConformityService::summary()}.
     *
     * @return array<int, string>
     */
    public function getMachineConformityProperty(): array
    {
        $machines = $this->machines;
        if (!($machines instanceof \Illuminate\Pagination\LengthAwarePaginator)) {
            return [];
        }

        $enrolled = $machines->filter(fn($m) => $m->isAgentEnrolled());
        if ($enrolled->isEmpty()) {
            return [];
        }

        $worst = app(ConformityService::class)->worstStatusFor($enrolled->pluck('id')->all());

        $map = [];
        foreach ($enrolled as $machine) {
            if ($machine->isAgentSilent()) {
                $map[$machine->id] = ConformityService::DERIVED_SILENT;
                continue;
            }
            $map[$machine->id] = $worst[$machine->id] ?? ConformityService::DERIVED_NEVER_REPORTED;
        }

        return $map;
    }

    /**
     * Story 16.13bis — Correction Q2 / Opus-A : invalider le cache stats
     * dès qu'un filtre machines change pour que le compteur "Postes migrés"
     * suive l'UI.
     */
    public function updatedOsFilter(): void
    {
        $this->statsLoaded = false;
    }

    public function updatedGroupFilter(): void
    {
        $this->statsLoaded = false;
    }

    public function updatedPresenceFilter(): void
    {
        // Le filtre présence ne modifie pas les compteurs globaux (inventaire),
        // il ne fait que restreindre le listing — pas de reload des stats.
        $this->resetPage();
    }

    /**
     * Clic sur une tuile de statistique : bascule le filtre rapide « carte »
     * sur la catégorie cliquée (« montre uniquement ce type »). Re-cliquer la
     * carte active la désélectionne (retour à « tous »). Les compteurs restent
     * l'inventaire du parc — pas de reload des stats, juste un reset de page.
     */
    public function filterByCard(string $card): void
    {
        $this->cardFilter = ($this->cardFilter === $card || !in_array($card, self::CARD_FILTERS, true))
            ? ''
            : $card;
        $this->selectedMachines = [];
        $this->resetPage();
    }

    public function getMachinesProperty()
    {
        try {
            // Story 7.1 : scope par user — les délégués ne voient que les machines
            // des WorkstationGroups sur lesquels ils ont `computer.view`.
            return $this->parcService->listMachines(
                perPage: $this->machinesPerPage,
                search: $this->machineSearch ?: null,
                os: $this->osFilter ?: null,
                groupId: $this->groupFilter,
                scopeFor: $this->scopedUser(),
                presenceFilter: $this->presenceFilter ?: null,
                cardFilter: $this->cardFilter ?: null,
            );
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur chargement machines: ' . $e->getMessage());
            return collect();
        }
    }

    public function getGroupsProperty()
    {
        try {
            // Story 7.1 : scope par user — les délégués ne voient que leurs
            // WorkstationGroups autorisés par délégation ou droit global.
            return $this->parcService->listGroups(
                perPage: $this->groupsPerPage,
                search: $this->groupSearch ?: null,
                isPhysical: match ($this->groupTypeFilter) {
                    'physical' => true,
                    'logical' => false,
                    default => null,
                },
                scopeFor: $this->scopedUser(),
            );
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur chargement groupes: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * Story 7.1 — Renvoie l'User Eloquent connecté pour le scoping des listings.
     *
     * Retour null si l'user courant n'est pas un `App\Models\User` (dans les
     * cas où un guard legacy injecte un Authenticatable non-Eloquent) : le
     * service retombe alors sur le comportement historique (aucune restriction).
     */
    private function scopedUser(): ?\App\Models\User
    {
        $user = auth()->user();
        return $user instanceof \App\Models\User ? $user : null;
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'groups';
        $this->resetPage();
    }

    public function resetMachineFilters(): void
    {
        $this->machineSearch = '';
        $this->osFilter = '';
        $this->groupFilter = null;
        $this->cardFilter = '';
        $this->presenceFilter = '';
        $this->selectedMachines = [];
        // Story 16.13bis — Correction Q2 / Opus-A : recharger les stats
        // pour refléter le nouveau périmètre global après reset.
        $this->statsLoaded = false;
        $this->resetPage();
    }

    public function resetGroupFilters(): void
    {
        $this->groupSearch = '';
        $this->groupTypeFilter = 'all';
        $this->selectedGroups = [];
        $this->resetPage();
    }

    public function updatedMachinesPerPage(): void
    {
        $this->resetPage();
    }

    public function updatedGroupsPerPage(): void
    {
        $this->resetPage();
    }

    // Actions sur les machines
    public function addMachinesToGroup(int $groupId): void
    {
        if (empty($this->selectedMachines)) {
            $this->toastError('Aucune machine sélectionnée');
            return;
        }

        try {
            // Story 4.11 (AC8) — une salle physique impose la règle 1-salle-max :
            // chaque poste passe par le swap transactionnel du service (detach de
            // l'ancienne salle + attach), pas par un attach pivot brut.
            $targetGroup = WorkstationGroup::find($groupId);
            if ($targetGroup && $targetGroup->is_physical) {
                $count = 0;
                foreach ($this->selectedMachines as $machineId) {
                    if ($this->parcService->assignMachineToPhysicalRoom((int) $machineId, $groupId)) {
                        $count++;
                    }
                }
            } else {
                $count = $this->parcService->bulkAddMachinesToGroup($this->selectedMachines, $groupId);
            }
            $this->toastSuccess("{$count} machine(s) ajoutée(s) au groupe");
            $this->selectedMachines = [];
        } catch (\App\Exceptions\ControlHub\UpstreamLockCollisionException $e) {
            // Story 30.5 — collision verrou/verrou prédite : message explicite.
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur ajout machines au groupe: ' . $e->getMessage());
            $this->toastError('Erreur lors de l\'ajout des machines');
        }
    }

    public function removeMachinesFromGroup(int $groupId): void
    {
        if (empty($this->selectedMachines)) {
            $this->toastError('Aucune machine sélectionnée');
            return;
        }

        try {
            $count = $this->parcService->bulkRemoveMachinesFromGroup($this->selectedMachines, $groupId);
            $this->toastSuccess("{$count} machine(s) retirée(s) du groupe");
            $this->selectedMachines = [];
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur retrait machines du groupe: ' . $e->getMessage());
            $this->toastError('Erreur lors du retrait des machines');
        }
    }

    public function executeSelectedMachinesAction(string $action): void
    {
        if (empty($this->selectedMachines)) {
            $this->toastError('Aucune machine sélectionnée');
            return;
        }

        try {
            $result = $this->parcService->executeMachinesAction($this->selectedMachines, $action);
            $actionLabel = $this->parcService->getMachineActionLabel($action);

            if ($result['requested_count'] === 0) {
                $this->toastWarning('Aucune machine valide à traiter');
                return;
            }

            if ($result['failed_count'] === 0) {
                $this->toastSuccess("Action de {$actionLabel} lancée sur {$result['success_count']}/{$result['requested_count']} machine(s)");
            } elseif ($result['success_count'] > 0) {
                $this->toastWarning("Action partielle ({$actionLabel}) : {$result['success_count']}/{$result['requested_count']} machine(s) traitée(s)");
            } else {
                $this->toastError("Échec de l'action de {$actionLabel} sur les machines sélectionnées");
            }

            $this->selectedMachines = [];
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur action machines: ' . $e->getMessage(), [
                'action' => $action,
                'machines' => $this->selectedMachines,
            ]);
            $this->toastError('Erreur lors de l\'exécution de l\'action');
        }
    }

    /* ================================================================
     * Story 3.11 — Réinstallation OS de la sélection (fan-out inventaire).
     * ================================================================ */

    public function getReinstallOsCatalogProperty(): array
    {
        return app(\App\Services\Parc\WorkstationReinstallService::class)->osCatalog();
    }

    public function openReinstallModal(): void
    {
        if (!Gate::allows('computer.install')) {
            $this->toastError("Vous n'avez pas le droit de réinstaller ces postes.");
            return;
        }
        if (empty($this->selectedMachines)) {
            $this->toastWarning('Aucune machine sélectionnée.');
            return;
        }
        $catalog = $this->reinstallOsCatalog;
        $this->reinstallTarget = $this->reinstallTarget ?: (string) ($catalog[0]['enum'] ?? '');
        $this->reinstallWhen = 'now';
        $this->reinstallScheduledAt = null;
        $this->reinstallModalOpen = true;
    }

    public function closeReinstallModal(): void
    {
        $this->reinstallModalOpen = false;
    }

    public function armReinstall(): void
    {
        if (!Gate::allows('computer.install')) {
            $this->toastError("Vous n'avez pas le droit de réinstaller ces postes.");
            return;
        }
        if (empty($this->selectedMachines)) {
            $this->toastWarning('Aucune machine sélectionnée.');
            return;
        }

        $scheduledAt = null;
        if ($this->reinstallWhen === 'schedule') {
            if (empty($this->reinstallScheduledAt)) {
                $this->toastError('Veuillez saisir une date et une heure de planification.');
                return;
            }
            try {
                $scheduledAt = \Carbon\Carbon::parse($this->reinstallScheduledAt, config('app.timezone'));
            } catch (\Throwable $e) {
                $this->toastError('Date de planification invalide.');
                return;
            }
            if ($scheduledAt->lessThanOrEqualTo(\Carbon\Carbon::now())) {
                $this->toastError('La date de planification doit être dans le futur.');
                return;
            }
        }

        try {
            $machines = \App\Models\Workstation::whereIn('id', $this->selectedMachines)->get();
            $result = app(\App\Services\Parc\WorkstationReinstallService::class)->armForMachines(
                $machines,
                $this->reinstallTarget,
                $scheduledAt,
                'user:' . (auth()->id() ?? 0),
                auth()->id(),
            );

            $this->reinstallModalOpen = false;
            $this->selectedMachines = [];

            $this->toastSuccess(sprintf(
                '%d poste(s) armé(s), %d déjà en cours, %d protégé(s) ignoré(s).',
                $result['armed_count'],
                $result['skipped_duplicate'],
                $result['skipped_protected'],
            ));
        } catch (\InvalidArgumentException $e) {
            $this->toastError($e->getMessage());
        } catch (\Throwable $e) {
            Log::error('[Parc] Erreur armement réinstallation sélection: ' . $e->getMessage());
            $this->toastError("Erreur lors de l'armement de la réinstallation.");
        }
    }

    // Actions sur les groupes
    public function deleteGroup(int $groupId): void
    {
        try {
            $this->parcService->deleteGroup($groupId);
            $this->toastSuccess('Groupe supprimé avec succès');
            $this->loadFiltersData();
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur suppression groupe: ' . $e->getMessage());
            $this->toastError($e->getMessage());
        }
    }

    public function deleteGroups(): void
    {
        if (empty($this->selectedGroups)) {
            $this->toastError('Aucun groupe sélectionné');
            return;
        }

        try {
            $count = 0;
            foreach ($this->selectedGroups as $groupId) {
                $this->parcService->deleteGroup((int) $groupId);
                $count++;
            }
            $this->toastSuccess("{$count} groupe(s) supprimé(s) avec succès");
            $this->selectedGroups = [];
            $this->loadFiltersData();
            $this->statsLoaded = false;
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur suppression groupes: ' . $e->getMessage());
            $this->toastError($e->getMessage());
        }
    }

    /**
     * Action groupée — déclare l'environnement (nature des postes, Story 26.1)
     * des groupes sélectionnés. Remplace l'ancien onglet « Environnement » de
     * parc-settings : la propriété s'édite désormais là où l'on gère les groupes.
     *
     * `$value` vide = « non déclaré » → null (distinct de shared_local, le défaut
     * étant résolu côté serveur). Une valeur non vide doit appartenir à l'enum
     * fermé. La gate `update-workstationGroup` (= computer.install) est vérifiée
     * PAR groupe : la route /parc n'exige que la lecture, on protège donc
     * l'écriture ressource par ressource (un délégué scopé ne touche que les
     * groupes autorisés).
     */
    public function setGroupsEnvironment(string $value): void
    {
        if (empty($this->selectedGroups)) {
            $this->toastError('Aucun groupe sélectionné');
            return;
        }

        if ($value !== '' && WorkstationEnvironment::tryFrom($value) === null) {
            $this->toastError("Valeur d'environnement invalide.");
            return;
        }

        $environment = $value === '' ? null : WorkstationEnvironment::from($value);

        $updated = 0;
        $skipped = 0;
        foreach ($this->selectedGroups as $groupId) {
            $group = WorkstationGroup::find((int) $groupId);
            if (!$group) {
                continue;
            }

            if (!Gate::allows('update-workstationGroup', $group)) {
                $skipped++;
                continue;
            }

            $group->environment = $environment;
            $group->save();
            $updated++;
        }

        $label = $environment?->label() ?? 'Non déclaré (partagé par défaut)';

        if ($updated === 0) {
            $this->toastError(
                $skipped > 0
                    ? "Aucun groupe modifié — {$skipped} non autorisé(s)."
                    : 'Aucun groupe modifié.',
            );
            return;
        }

        $message = "Environnement « {$label} » appliqué à {$updated} groupe(s)";
        if ($skipped > 0) {
            $message .= " — {$skipped} ignoré(s) (non autorisé)";
        }
        $this->toastSuccess($message);
        $this->selectedGroups = [];
    }

    // Synchronisation depuis AD
    public function syncFromAd(): void
    {
        try {
            // Utiliser dispatch_sync pour exécuter le job de manière synchrone
            // Laravel injectera automatiquement le WorkstationGroupRepository
            SyncWorkstationGroupsFromAd::dispatchSync();

            // Rafraîchir le statut de synchronisation des WorkstationGroups
            $this->dispatch('refresh-sync-status-workstation-groups');

            $this->toastSuccess('Synchronisation depuis l\'AD terminée avec succès');
            $this->loadFiltersData();
            $this->statsLoaded = false;
        } catch (\Exception $e) {
            Log::error('[Parc] Erreur sync AD: ' . $e->getMessage());
            $this->toastError('Erreur lors de la synchronisation: ' . $e->getMessage());
        }
    }
};
?>

<x-organisms.page title="Gestion du Parc" :scrollable="false"
    description="Gérez les postes et groupes de postes de votre établissement">

    <x-slot:actions>
        {{-- Dropdown d'actions contextuel : les entrées dépendent de l'onglet actif. --}}
        <div class="dropdown dropdown-end">
            <label tabindex="0" class="btn btn-primary">
                <i class="fa-solid fa-bolt"></i>
                Actions
                <i class="fa-solid fa-chevron-down text-xs ml-1"></i>
            </label>
            <ul tabindex="0"
                class="dropdown-content z-[50] menu p-2 shadow bg-base-100 rounded-box w-64 border border-base-300 mt-2">
                @if ($tab === 'groups')
                    <li>
                        <button type="button" wire:click="$dispatch('open-group-modal')">
                            <i class="fa-solid fa-folder-plus"></i>
                            Nouveau groupe
                        </button>
                    </li>
                    <li>
                        <button type="button" wire:click="syncFromAd">
                            <i class="fa-solid fa-rotate"></i>
                            Synchroniser depuis l'AD
                        </button>
                    </li>
                @elseif ($tab === 'machines')
                    <li>
                        <button type="button" wire:click="syncFromAd">
                            <i class="fa-solid fa-rotate"></i>
                            Synchroniser depuis l'AD
                        </button>
                    </li>
                @elseif ($tab === 'printers')
                    @can('manage-printer')
                        <li>
                            <button type="button" wire:click="$dispatch('parc-add-printer')">
                                <i class="fa-solid fa-plus"></i>
                                Ajouter une imprimante
                            </button>
                        </li>
                    @else
                        <li class="menu-disabled"><span class="text-base-content/50">Aucune action disponible</span></li>
                    @endcan
                @endif
            </ul>
        </div>
    </x-slot:actions>

    <!-- Chargement asynchrone des stats -->
    <div wire:init="loadStats"></div>

    <div class="h-full flex flex-col gap-4">
        <!-- Onglets -->
        @php
            $parcTabs = [
                'groups' => ['label' => 'Groupes', 'icon' => 'fa-solid fa-folder-tree'],
                'machines' => ['label' => 'Postes', 'icon' => 'fa-solid fa-computer'],
                'printers' => ['label' => 'Imprimantes', 'icon' => 'fa-solid fa-print'],
            ];
        @endphp
        <x-molecules.tabs :tabs="$parcTabs" :active="$tab" />

        <!-- Contenu des onglets -->
        <div class="flex-1 min-h-0 flex flex-col">
            @if ($tab === 'machines')
                @include('pages.parc._partials.machines-tab')
            @elseif ($tab === 'printers')
                <livewire:pages::parc._partials.printers-tab />
            @else
                {{-- Vérification synchronisation AD/SQL --}}
                <div class="flex-shrink-0">
                    <livewire:components::molecules.workstation-group-sync-status />
                </div>
                @include('pages.parc._partials.groups-tab')
            @endif
        </div>
    </div>

    {{-- Modale réutilisable de création / édition de groupe (ici : mode création). --}}
    <livewire:pages::parc.groups._partials.group-form-modal />

    {{-- Story 3.11 — Modale de réinstallation de la sélection (fan-out inventaire). --}}
    @can('computer.install')
        @include('pages.parc._partials.reinstall-modal', [
            'reinstallTitle' => 'Réinstaller la sélection',
            'confirmTitle' => 'Confirmer la réinstallation de la sélection',
            'confirmMessage' => 'Cette opération EFFACE le disque de TOUS les postes sélectionnés et réinstalle',
            // Fix review #5 — compte réactif : nombre EXACT de postes sélectionnés,
            // lu côté Alpine au clic (les protégés sont skippés à l'armement et
            // rapportés dans le toast de retour).
            'confirmCountExpr' => '$wire.selectedMachines.length',
        ])
    @endcan
</x-organisms.page>
