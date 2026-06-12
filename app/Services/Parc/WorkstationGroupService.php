<?php

namespace App\Services\Parc;

use App\Config\LdapDnHelper;
use App\Facades\SEConfig;
use App\Jobs\AdSync\WorkstationMembershipAdSyncJob;
use App\Jobs\DispatchMachinePowerActionJob;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\DeviceGroupTagModel;
use App\Models\MachinePowerActionTask;
use App\Services\Ldap\EstablishmentWorkstationScope;
use App\Services\WorkstationService;
use App\Services\PermissionService;
use App\Models\User;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Repositories\WorkstationGroupRepository;
use App\Services\Parc\RemoteAccessService;
use App\Enums\LockReason;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service pour la gestion des groupes de postes de travail (WorkstationGroups)
 * 
 * Fournit la logique métier pour la gestion des postes et groupes de postes.
 * Utilise le WorkstationGroupRepository pour l'accès aux données.
 */
class WorkstationGroupService
{
    /** @var array<int, string> */
    private const SUPPORTED_MACHINE_ACTIONS = ['wake', 'shutdown', 'shutdown-force', 'restart', 'remote'];

    /** @var array<string, string> */
    private const MACHINE_ACTION_LABELS = [
        'wake' => 'allumage',
        'shutdown' => 'extinction',
        'shutdown-force' => 'extinction forcée',
        'restart' => 'redémarrage',
        'remote' => 'accès distant',
    ];

    public function __construct(
        private WorkstationGroupRepository $repository,
        private WorkstationService $workstationService,
        private RemoteAccessService $remoteAccessService,
    ) {
    }

    // ========================================
    // MACHINES
    // ========================================

    /**
     * Liste les machines avec filtres et pagination.
     *
     * Story 7.1 — paramètre optionnel `$scopeFor` :
     *  - `null` (défaut) : comportement historique, aucune restriction de périmètre.
     *  - `User` fourni :
     *      · si l'user a le droit global `computer.view` via Spatie → pas de
     *        restriction (équivalent admin).
     *      · sinon → restreindre aux machines appartenant aux WorkstationGroups
     *        sur lesquels l'user a une délégation `computer.view` active et
     *        non-négateée (via `PermissionService::getAuthorizedWorkstationGroups`).
     *        Si la liste des groupes autorisés est vide → pagination vide.
     *
     * Les appelants existants qui n'injectent pas `$scopeFor` ne sont pas affectés
     * (contrat backward-compat garanti).
     */
    public function listMachines(
        int $perPage = 20,
        ?string $search = null,
        ?string $os = null,
        ?int $groupId = null,
        ?User $scopeFor = null,
        ?string $migrationFilter = null,
        ?string $conformityFilter = null
    ): LengthAwarePaginator {
        $authorizedGroupIds = $this->resolveAuthorizedGroupIds($scopeFor);

        // $authorizedGroupIds === null : pas de scope demandé ou user a le droit
        // global — on retombe sur le comportement historique.
        if ($authorizedGroupIds === null) {
            return $this->repository->getMachines($perPage, $search, $os, $groupId, $migrationFilter, $conformityFilter);
        }

        // Si un groupId explicite est demandé mais qu'il n'est pas dans le
        // périmètre autorisé → retour vide (ne pas fuiter l'existence du group).
        if ($groupId !== null && !in_array($groupId, $authorizedGroupIds, true)) {
            return $this->emptyMachinesPaginator($perPage);
        }

        // Si aucun group autorisé → rien à afficher.
        if (empty($authorizedGroupIds)) {
            return $this->emptyMachinesPaginator($perPage);
        }

        return $this->repository->getMachinesScoped(
            perPage: $perPage,
            search: $search,
            os: $os,
            groupId: $groupId,
            authorizedGroupIds: $authorizedGroupIds,
            migrationFilter: $migrationFilter,
            conformityFilter: $conformityFilter,
        );
    }

    /**
     * Récupère une machine par son ID
     */
    public function getWorkstation(int $id): ?Workstation
    {
        return $this->repository->findMachine($id);
    }

    /**
     * Récupère une machine par son nom
     */
    public function getWorkstationByName(string $name): ?Workstation
    {
        return $this->repository->findMachineByName($name);
    }

    /**
     * Récupère les statistiques des machines.
     *
     * Story 16.13bis — Correction Q2 / Opus-A (2026-05-20) : `$os`, `$groupId`
     * et `$migrationFilter` permettent de **scoper** le compteur "Postes
     * migrés" + le total aux mêmes filtres que ceux appliqués au listing
     * Livewire (cohérence UX). Les autres compteurs (active, without_group,
     * by_os, salles physiques, parcs logiques) restent globaux car ils
     * répondent à des questions d'inventaire transverses.
     *
     * Le `$scopeFor` reste géré côté composant Livewire qui n'appelle
     * `getMachineStats()` qu'une fois — les filtres d'autorisation par
     * délégation n'altèrent pas le compteur global de migration (un admin
     * non-délégué voit "X/Y postes du parc complet").
     *
     * Compat : appelants sans arg → comportement historique (compteurs globaux).
     *
     * @param string|null $os              Filtre OS actif ('windows', 'linux', etc.).
     * @param int|null    $groupId         Filtre groupe actif (ID WorkstationGroup).
     * @param string|null $migrationFilter '', 'migrated', 'not-migrated'.
     */
    public function getMachineStats(
        ?string $os = null,
        ?int $groupId = null,
        ?string $migrationFilter = null,
    ): array {
        $total = $this->repository->countMachines();
        $withoutGroup = $this->repository->getMachinesWithoutGroup()->count();
        $osList = $this->repository->getDistinctOs();

        $osCounts = [];
        foreach ($osList as $osName) {
            $osCounts[$osName] = Workstation::where('os', $osName)->count();
        }

        // Story 16.13bis — compteur X/Y postes migrés scoped aux filtres actifs.
        // Si aucun filtre actif → comptage global (parité legacy).
        // Best-effort try/catch si la table `workstations_migration_status`
        // n'existe pas (cas tests non-16.11).
        $migrated = 0;
        $scopedTotal = $total;
        try {
            $hasScope = ($os !== null && $os !== '')
                || ($groupId !== null)
                || ($migrationFilter !== null && $migrationFilter !== '');

            if ($hasScope) {
                $scopedQuery = $this->buildScopedMachineQuery($os, $groupId);
                // Le compteur "Postes migrés" est toujours l'intersection
                // (filtres actifs) ∩ (migrated). Si l'admin a aussi pose
                // migrationFilter='not-migrated', l'intersection est 0 (cohérent).
                $migratedQuery = (clone $scopedQuery)->migrated();
                if ($migrationFilter === 'not-migrated') {
                    $migrated = 0;
                } else {
                    $migrated = $migratedQuery->count();
                }
                // Le total visible = total après application des filtres
                // (OS / groupe / migration) — c'est le dénominateur cohérent
                // avec la pagination Livewire.
                $scopedTotal = $this->applyMigrationFilterToQuery(
                    $this->buildScopedMachineQuery($os, $groupId),
                    $migrationFilter,
                )->count();
            } else {
                $migrated = Workstation::migrated()->count();
            }
        } catch (\Throwable $e) {
            $migrated = 0;
        }

        $active = Workstation::where('status', 'active')->orWhere('status', 1)->count();

        return [
            'total' => $scopedTotal,
            'active' => $active,
            'without_group' => $withoutGroup,
            'by_os' => $osCounts,
            'migrated' => $migrated,
        ];
    }

    /**
     * Story 16.13bis — Correction Q2 / Opus-A : construit une query Workstation
     * avec les filtres OS + groupe actifs (sans migrationFilter — appliqué
     * séparément). Utilisé par `getMachineStats()` pour scoper le compteur.
     */
    private function buildScopedMachineQuery(?string $os, ?int $groupId): \Illuminate\Database\Eloquent\Builder
    {
        $query = Workstation::query();

        if ($os !== null && $os !== '') {
            $query->where('os', $os);
        }

        if ($groupId !== null) {
            $query->whereHas('groups', function ($q) use ($groupId): void {
                $q->where('workstation_groups.id', $groupId);
            });
        }

        return $query;
    }

    /**
     * Applique le `$migrationFilter` à la query Workstation passée en
     * argument (idem `WorkstationGroupRepository::applyMigrationFilter`).
     */
    private function applyMigrationFilterToQuery(
        \Illuminate\Database\Eloquent\Builder $query,
        ?string $migrationFilter,
    ): \Illuminate\Database\Eloquent\Builder {
        if ($migrationFilter === 'migrated') {
            return $query->migrated();
        }
        if ($migrationFilter === 'not-migrated') {
            return $query->notMigrated();
        }

        return $query;
    }

    /**
     * Récupère les OS disponibles
     */
    public function getAvailableOs(): Collection
    {
        return $this->repository->getDistinctOs();
    }

    /**
     * Retourne les actions machines disponibles côté interface
     *
     * @return array<int, array{key: string, label: string, icon: string, requires_confirmation: bool}>
     */
    public function getAvailableMachineActions(): array
    {
        return [
            [
                'key' => 'wake',
                'label' => 'Allumer',
                'icon' => 'fa-solid fa-power-off',
                'requires_confirmation' => false,
            ],
            [
                'key' => 'shutdown',
                'label' => 'Éteindre',
                'icon' => 'fa-solid fa-stop',
                'requires_confirmation' => true,
            ],
            [
                'key' => 'shutdown-force',
                'label' => 'Forcer l\'extinction',
                'icon' => 'fa-solid fa-triangle-exclamation',
                'requires_confirmation' => true,
            ],
            [
                'key' => 'restart',
                'label' => 'Redémarrer',
                'icon' => 'fa-solid fa-rotate-right',
                'requires_confirmation' => true,
            ],
            [
                'key' => 'remote',
                'label' => 'Accès distant',
                'icon' => 'fa-solid fa-desktop',
                'requires_confirmation' => false,
            ],
        ];
    }

    /**
     * Libellé lisible d'une action machine
     */
    public function getMachineActionLabel(string $action): string
    {
        return self::MACHINE_ACTION_LABELS[$action] ?? $action;
    }

    /**
     * Exécute une action de puissance sur une sélection de machines
     *
     * Note story 4-3 : pour les actions power (`wake|shutdown|shutdown-force|restart`)
     * le dispatch transite désormais par `DispatchMachinePowerActionJob` (1 task par
     * machine), retourne immédiatement le contrat typé avec `results[i].task_id`
     * pour permettre au composant Livewire appelant de poller l'état. L'action
     * `remote` reste synchrone (génération de token Guacamole).
     *
     * @param array<int|string> $machineIds
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int}>}
     */
    public function executeMachinesAction(array $machineIds, string $action, ?string $initiatedBy = null): array
    {
        $normalizedIds = $this->normalizeMachineIds($machineIds);
        $machines = $this->repository->findMachinesByIds($normalizedIds);

        return $this->executeMachineActionOnCollection($machines, $action, $normalizedIds, $initiatedBy);
    }

    /**
     * Exécute une action de puissance sur une machine précise
     *
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int}>}
     */
    public function executeMachineAction(int $machineId, string $action, ?string $initiatedBy = null): array
    {
        $normalizedIds = $this->normalizeMachineIds([$machineId]);
        $machines = $this->repository->findMachinesByIds($normalizedIds);

        return $this->executeMachineActionOnCollection($machines, $action, $normalizedIds, $initiatedBy);
    }

    /**
     * Exécute une action de puissance sur des machines appartenant à un groupe
     *
     * Story 4-3 : refonte du pipeline en async par machine pour les actions
     * power. Pour chaque machine éligible (présente dans le groupe + pas de
     * task active), on crée une ligne `machine_power_action_tasks` et on
     * dispatche un `DispatchMachinePowerActionJob`. Les machines déjà en
     * action (status ∈ ACTIVE_STATUSES) sont filtrées et comptées comme
     * `failed_count` avec `code=409, reason='already-running'`.
     *
     * Story 4-4 (crons planifiés) : ajout du paramètre optionnel
     * `$initiatedBy` pour permettre au scheduler cron de tracer l'origine
     * des tasks (`'schedule:<id>'`) et les distinguer des actions manuelles
     * (`'user:<id>'`). Backward-compat : si null, fallback sur auth()->user()->name
     * ou session('login') — comportement historique.
     *
     * Contrat de retour strictement préservé : `{action, requested_count,
     * success_count, failed_count, results[]}`. Enrichissements rétrocompat :
     *   - `results[i].task_id` (int, présent pour les actions power dispatchées)
     *   - `results[i].reason` (string, présent pour les échecs structurés)
     *
     * `action=remote` conserve le flux synchrone via `executeRemoteAccessAction`
     * (D5 story 4-3).
     *
     * @param array<int|string> $machineIds
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int}>}
     */
    public function executeGroupMachinesAction(int $groupId, array $machineIds, string $action, ?string $initiatedBy = null): array
    {
        $normalizedIds = $this->normalizeMachineIds($machineIds);
        $machines = $this->repository->findGroupMachinesByIds($groupId, $normalizedIds);

        return $this->executeMachineActionOnCollection($machines, $action, $normalizedIds, $initiatedBy);
    }

    // ========================================
    // GROUPES DE MACHINES
    // ========================================

    /**
     * Liste les groupes avec filtres et pagination.
     *
     * Story 7.1 — paramètre optionnel `$scopeFor` : même sémantique que
     * `listMachines()`. Si l'user délégué n'a pas le droit global
     * `computer.view`, on contraint la liste aux WorkstationGroups sur
     * lesquels il a une délégation positive active non-négateée.
     * Le périmètre est calculé côté `PermissionService::getAuthorizedWorkstationGroups`.
     *
     * Appelants non-scopés (null) → comportement historique préservé.
     */
    public function listGroups(
        int $perPage = 20,
        ?string $search = null,
        ?int $parentId = null,
        ?bool $isPhysical = null,
        ?User $scopeFor = null
    ): LengthAwarePaginator {
        $authorizedGroupIds = $this->resolveAuthorizedGroupIds($scopeFor);

        if ($authorizedGroupIds === null) {
            return $this->repository->getGroups($perPage, $search, $parentId, $isPhysical);
        }

        if (empty($authorizedGroupIds)) {
            return $this->emptyGroupsPaginator($perPage);
        }

        return $this->repository->getGroupsScoped(
            perPage: $perPage,
            search: $search,
            parentId: $parentId,
            isPhysical: $isPhysical,
            authorizedGroupIds: $authorizedGroupIds,
        );
    }

    /**
     * Résout la liste d'IDs de WorkstationGroups autorisés pour un scope user.
     *
     * Retour :
     *  - `null`   → pas de scope demandé (user null) OU user a le droit global
     *               `computer.view` → aucun filtre à appliquer en aval.
     *  - `int[]`  → liste (possiblement vide) des IDs autorisés par délégation.
     *
     * @return array<int,int>|null
     */
    private function resolveAuthorizedGroupIds(?User $scopeFor): ?array
    {
        if ($scopeFor === null) {
            return null;
        }

        // Short-circuit admin : droit global ET aucune exclusion active → aucun filtre.
        // Une exclusion scopée (is_negative) doit écraser le droit global sur un group
        // précis, donc on ne peut pas retourner null si l'user en a au moins une.
        $hasNegativeScope = \App\Models\Delegation::forUser($scopeFor)
            ->forPermission('computer.view')
            ->negative()
            ->active()
            ->exists();

        if (!$hasNegativeScope && $scopeFor->can('computer.view')) {
            return null;
        }

        // Sinon, liste des WorkstationGroups autorisés (positives − négatives, ou
        // "tout sauf exclusions" si droit global + exclusions actives).
        return app(PermissionService::class)
            ->getAuthorizedWorkstationGroups($scopeFor, 'computer.view')
            ->pluck('id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Construit un paginator vide (pour périmètre sans groupes autorisés).
     */
    private function emptyGroupsPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: collect(),
            total: 0,
            perPage: $perPage,
            currentPage: 1,
            options: ['path' => request()?->url() ?? ''],
        );
    }

    /**
     * Construit un paginator vide (pour périmètre sans machines autorisées).
     */
    private function emptyMachinesPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: collect(),
            total: 0,
            perPage: $perPage,
            currentPage: 1,
            options: ['path' => request()?->url() ?? ''],
        );
    }

    /**
     * Récupère l'arborescence complète des groupes
     */
    public function getGroupsTree(): Collection
    {
        return $this->repository->getGroupsTree();
    }

    /**
     * Récupère un groupe par son ID
     */
    public function getGroup(int $id): ?WorkstationGroup
    {
        return $this->repository->findGroup($id);
    }

    /**
     * Crée un nouveau groupe
     * 
     * Note: La création automatique de l'AppProfile (si app_profile_name est rempli)
     * est gérée par le WorkstationGroupObserver.
     */
    public function createGroup(array $data): WorkstationGroup
    {
        $this->validateGroupData($data);

        return DB::transaction(function () use ($data) {
            $group = $this->repository->createGroup($data);

            Log::info('Groupe de machines créé', [
                'group_id' => $group->id,
                'group_name' => $group->name,
                'app_profile_name' => $group->app_profile_name,
            ]);

            return $group;
        });
    }

    /**
     * Met à jour un groupe
     * 
     * Note: Le renommage automatique de l'AppProfile associé
     * est géré par le WorkstationGroupObserver.
     * 
     * @throws \RuntimeException Si le groupe est verrouillé
     */
    public function updateGroup(int $id, array $data): WorkstationGroup
    {
        $group = $this->repository->findGroup($id);

        if (!$group) {
            throw new \InvalidArgumentException("Groupe non trouvé: {$id}");
        }

        if ($group->isLocked()) {
            throw new \RuntimeException("Groupe verrouillé: {$group->locked}");
        }

        $this->validateGroupData($data, $group);

        DB::transaction(function () use ($group, $data) {
            $this->repository->updateGroup($group, $data);

            Log::info('Groupe de machines mis à jour', [
                'group_id' => $group->id,
                'name' => $group->name,
            ]);
        });

        return $group->fresh();
    }

    /**
     * Supprime un groupe
     * 
     * Note: La suppression automatique de l'AppProfile associé
     * est gérée par le WorkstationGroupObserver.
     * 
     * @throws \RuntimeException Si le groupe est verrouillé
     */
    public function deleteGroup(int $id): bool
    {
        $group = $this->repository->findGroup($id);

        if (!$group) {
            throw new \InvalidArgumentException("Groupe non trouvé: {$id}");
        }

        if ($group->isLocked()) {
            throw new \RuntimeException("Groupe verrouillé: {$group->locked}");
        }

        return DB::transaction(function () use ($group) {
            $name = $group->name;

            $result = $this->repository->deleteGroup($group);

            Log::info('Groupe de machines supprimé', [
                'group_id' => $group->id,
                'name' => $name,
            ]);

            return $result;
        });
    }

    /**
     * Récupère les groupes synchronisés avec AD
     */
    public function getGroupsSyncedWithAd(): Collection
    {
        return WorkstationGroup::syncedWithAd()->get();
    }

    /**
     * Récupère les groupes racine pour les sélecteurs.
     *
     * Story 7.1 — Review #7 : paramètre optionnel `$scopeFor` pour filtrer
     * le dropdown "Filtrer par groupe" aux seuls groupes autorisés.
     *  - `null` (défaut) : comportement historique, toutes les racines.
     *  - `User` fourni :
     *      · si l'user a le droit global `computer.view` via Spatie → pas de
     *        restriction (équivalent admin).
     *      · sinon → restreindre aux WorkstationGroups autorisés par délégation.
     *        Si la liste des groupes autorisés est vide → collection vide.
     *
     * Contrairement à `listGroups`/`listMachines`, on filtre sur `id`
     * directement (les racines autorisées peuvent figurer dans la liste
     * déléguée — on n'essaye pas de remonter au root d'un sous-groupe délégué
     * car la hiérarchie logique n'est pas cible de délégation en 7.1).
     */
    public function getRootGroupsForSelect(?User $scopeFor = null): Collection
    {
        $authorizedGroupIds = $this->resolveAuthorizedGroupIds($scopeFor);

        // $authorizedGroupIds === null : pas de scope ou user admin → comportement historique.
        if ($authorizedGroupIds === null) {
            return $this->repository->getRootGroups();
        }

        // Liste vide : aucun groupe visible → collection vide (évite leak de noms).
        if (empty($authorizedGroupIds)) {
            return collect();
        }

        return $this->repository->getRootGroups()
            ->filter(static fn (WorkstationGroup $g) => in_array((int) $g->id, $authorizedGroupIds, true))
            ->values();
    }

    /**
     * Récupère les statistiques des groupes
     */
    public function getGroupStats(): array
    {
        $total = $this->repository->countGroups();
        $synced = WorkstationGroup::syncedWithAd()->count();
        $physicalRooms = WorkstationGroup::physical()->count();
        $logicalGroups = WorkstationGroup::logical()->count();

        return [
            'total' => $total,
            'physical_rooms' => $physicalRooms,
            'logical_groups' => $logicalGroups,
            'synced_with_ad' => $synced,
            'not_synced' => $total - $synced,
        ];
    }

    /**
     * @param array<int|string> $machineIds
     * @return array<int>
     */
    private function normalizeMachineIds(array $machineIds): array
    {
        $normalizedIds = array_map(
            static fn (mixed $id): int => (int) $id,
            $machineIds
        );

        $normalizedIds = array_filter(
            $normalizedIds,
            static fn (int $id): bool => $id > 0
        );

        return array_values(array_unique($normalizedIds));
    }

    /**
     * @param array<int> $requestedIds IDs normalisés demandés initialement (pour calculer `requested_count` même si des IDs sont absents de la collection).
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int}>}
     */
    private function executeMachineActionOnCollection(Collection $machines, string $action, array $requestedIds = [], ?string $initiatedBy = null): array
    {
        if (!in_array($action, self::SUPPORTED_MACHINE_ACTIONS, true)) {
            throw new \InvalidArgumentException("Action machine non supportée: {$action}");
        }

        // Gestion spéciale pour l'accès distant — reste synchrone (token Guacamole).
        if ($action === 'remote') {
            return $this->executeRemoteAccessAction($machines);
        }

        // Story 4-3 : pipeline async par machine. Chaque machine résolue crée
        // une MachinePowerActionTask + un DispatchMachinePowerActionJob. Les
        // machines déjà en action (idempotence D4) sont skippées et remontées
        // en failed_count avec code=409.
        return $this->dispatchAsyncActionForMachines($machines, $action, $requestedIds, $initiatedBy);
    }

    /**
     * Dispatch async d'une action power sur une collection de machines (story 4-3).
     *
     * - Crée une `MachinePowerActionTask` + dispatche un `DispatchMachinePowerActionJob`
     *   pour chaque machine éligible.
     * - Filtre en amont les machines qui ont déjà une task active (idempotence D4)
     *   via un unique SELECT sur `machine_power_action_tasks` pour éviter les N+1.
     * - Comptabilise les machines non résolues (ID demandé mais absent de la
     *   collection — par ex. machine supprimée, ou pas dans le groupe) en
     *   `failed_count` avec `code=404, reason='not-found'`.
     *
     * Contrat de retour préservé — seuls des champs rétrocompat sont ajoutés :
     *   - `results[i].task_id` (int, si dispatché)
     *   - `results[i].reason`  (string, si échec structuré)
     *
     * @param Collection<int, Workstation> $machines Machines résolues en DB (pouvant être un sous-ensemble des IDs demandés).
     * @param array<int> $requestedIds IDs normalisés initialement demandés (pour le compte "not-found").
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array<string, mixed>>}
     */
    private function dispatchAsyncActionForMachines(Collection $machines, string $action, array $requestedIds, ?string $initiatedByOverride = null): array
    {
        $resolvedIds = $machines
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        // Un seul SELECT pour repérer les machines déjà en action active
        // (AC7 idempotence). whereIn sur la liste résolue, pluck les
        // workstation_id. Si la liste est vide on saute le SELECT.
        $alreadyRunningIds = [];
        if (!empty($resolvedIds)) {
            $alreadyRunningIds = MachinePowerActionTask::query()
                ->whereIn('workstation_id', $resolvedIds)
                ->whereIn('status', MachinePowerActionTask::ACTIVE_STATUSES)
                ->pluck('workstation_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        // Story 4-4 : si $initiatedByOverride est fourni (ex. 'schedule:<id>'
        // par le scheduler cron), il prime sur la résolution auth() pour que
        // l'audit trail distingue actions manuelles vs cron.
        $initiatedBy = $initiatedByOverride
            ?? auth()->user()?->name
            ?? session('login')
            ?? 'system';
        $restartPhase = $action === 'restart'
            ? MachinePowerActionTask::RESTART_PHASE_WAITING_DOWN
            : null;

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        // 1. Traiter les machines effectivement résolues en DB.
        foreach ($machines as $machine) {
            $machineId = (int) $machine->id;
            $machineName = (string) ($machine->name ?? "id:{$machineId}");

            if (in_array($machineId, $alreadyRunningIds, true)) {
                $results[] = [
                    'machine' => $machineName,
                    'success' => false,
                    'code' => 409,
                    'reason' => 'already-running',
                ];
                $failedCount++;
                continue;
            }

            try {
                $task = MachinePowerActionTask::create([
                    'workstation_id' => $machineId,
                    'action' => $action,
                    'status' => MachinePowerActionTask::STATUS_QUEUED,
                    'initiated_by' => $initiatedBy,
                    'initiated_at' => now(),
                    'restart_phase' => $restartPhase,
                ]);

                DispatchMachinePowerActionJob::dispatch($task->id);

                $results[] = [
                    'machine' => $machineName,
                    'success' => true,
                    'code' => 202,
                    'task_id' => (int) $task->id,
                ];
                $successCount++;
            } catch (\Throwable $e) {
                Log::error('[WorkstationGroupService] Dispatch async action machine échoué', [
                    'machine_id' => $machineId,
                    'machine' => $machineName,
                    'action' => $action,
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'machine' => $machineName,
                    'success' => false,
                    'code' => 500,
                    'reason' => 'dispatch-failed',
                ];
                $failedCount++;
            }
        }

        // 2. Comptabiliser les IDs demandés mais non résolus (404 not-found).
        $unresolvedIds = array_values(array_diff($requestedIds, $resolvedIds));
        foreach ($unresolvedIds as $missingId) {
            $results[] = [
                'machine' => "id:{$missingId}",
                'success' => false,
                'code' => 404,
                'reason' => 'not-found',
            ];
            $failedCount++;
        }

        $requestedCount = !empty($requestedIds) ? count($requestedIds) : $machines->count();

        return [
            'action' => $action,
            'requested_count' => $requestedCount,
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * Exécute l'action d'accès distant sur une collection de machines
     * 
     * @param Collection $machines
     * @return array{action: string, requested_count: int, success_count: int, failed_count: int, results: array<int, array{machine: string, success: bool, code: int, url?: string}>}
     */
    private function executeRemoteAccessAction(Collection $machines): array
    {
        if (!$this->remoteAccessService->hasRemoteAccessRights()) {
            throw new \InvalidArgumentException('Droits insuffisants pour l\'accès distant');
        }

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($machines as $machine) {
            try {
                $connectionType = RemoteAccessService::DEFAULT_CONNECTION_TYPE;
                $remoteUrl = $this->remoteAccessService->generateRemoteToken($machine->name, $connectionType);

                if ($remoteUrl) {
                    $results[] = [
                        'machine' => $machine->name,
                        'success' => true,
                        'code' => 200,
                        'url' => $remoteUrl,
                    ];
                    $successCount++;
                } else {
                    $results[] = [
                        'machine' => $machine->name,
                        'success' => false,
                        'code' => 500,
                    ];
                    $failedCount++;
                }
            } catch (\Exception $e) {
                Log::error('[WorkstationGroupService] Erreur accès distant machine: ' . $e->getMessage(), [
                    'machine' => $machine->name,
                ]);
                $results[] = [
                    'machine' => $machine->name,
                    'success' => false,
                    'code' => 500,
                ];
                $failedCount++;
            }
        }

        return [
            'action' => 'remote',
            'requested_count' => $machines->count(),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ];
    }

    // ========================================
    // GESTION DES RELATIONS
    // ========================================

    /**
     * Ajoute une machine à un groupe
     */
    public function addMachineToGroup(int $machineId, int $groupId): void
    {
        $this->repository->addMachineToGroup($machineId, $groupId);

        Log::info('Machine ajoutée au groupe', [
            'machine_id' => $machineId,
            'group_id' => $groupId,
        ]);
    }

    /**
     * Retire une machine d'un groupe
     */
    public function removeMachineFromGroup(int $machineId, int $groupId): void
    {
        $this->repository->removeMachineFromGroup($machineId, $groupId);

        Log::info('Machine retirée du groupe', [
            'machine_id' => $machineId,
            'group_id' => $groupId,
        ]);
    }

    /**
     * Définit les groupes d'une machine
     */
    public function setMachineGroups(int $machineId, array $groupIds): void
    {
        $this->repository->setMachineGroups($machineId, $groupIds);

        Log::info('Groupes de la machine mis à jour', [
            'machine_id' => $machineId,
            'group_ids' => $groupIds,
        ]);
    }

    /**
     * Définit les machines d'un groupe
     */
    public function setGroupMachines(int $groupId, array $machineIds): void
    {
        $this->repository->setGroupMachines($groupId, $machineIds);

        Log::info('Machines du groupe mises à jour', [
            'group_id' => $groupId,
            'machine_count' => count($machineIds),
        ]);
    }

    /**
     * Déplace plusieurs machines vers un groupe
     */
    public function bulkAddMachinesToGroup(array $machineIds, int $groupId): int
    {
        $count = 0;

        DB::transaction(function () use ($machineIds, $groupId, &$count) {
            foreach ($machineIds as $machineId) {
                try {
                    $this->repository->addMachineToGroup($machineId, $groupId);
                    $count++;
                } catch (\Exception $e) {
                    Log::warning('Erreur lors de l\'ajout de la machine au groupe', [
                        'machine_id' => $machineId,
                        'group_id' => $groupId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Machines ajoutées en masse au groupe', [
            'group_id' => $groupId,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Retire plusieurs machines d'un groupe
     */
    public function bulkRemoveMachinesFromGroup(array $machineIds, int $groupId): int
    {
        $count = 0;

        DB::transaction(function () use ($machineIds, $groupId, &$count) {
            foreach ($machineIds as $machineId) {
                try {
                    $this->repository->removeMachineFromGroup($machineId, $groupId);
                    $count++;
                } catch (\Exception $e) {
                    Log::warning('Erreur lors du retrait de la machine du groupe', [
                        'machine_id' => $machineId,
                        'group_id' => $groupId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });

        Log::info('Machines retirées en masse du groupe', [
            'group_id' => $groupId,
            'count' => $count,
        ]);

        return $count;
    }

    // ========================================
    // GESTION DES SALLES PHYSIQUES
    // ========================================

    /**
     * Récupère les salles physiques disponibles
     */
    public function getPhysicalRooms(): Collection
    {
        return WorkstationGroup::physical()->active()->orderBy('name')->get();
    }

    /**
     * Assigne une machine à une salle physique (ou la détache de toute salle).
     *
     * Story 4.11 — point d'écriture UNIQUE de l'appartenance « salle » (D2).
     * L'appartenance vit dans le pivot global `workstation_group_workstation` ;
     * l'invariant « 1 salle max par poste » (D3, app-only) est garanti ici par
     * un swap transactionnel : detach de TOUTE salle physique courante + attach
     * de la cible, dans la même transaction (impossible d'observer 0 ou 2
     * salles depuis une autre connexion).
     *
     * `$roomId === null` → simple detach de la (des) salle(s) courante(s).
     *
     * Propagation OU AD (gap comblé par 4.11) : si la salle change réellement,
     * `WorkstationMembershipAdSyncJob::move` est dispatché après commit.
     * `$dispatchAdSync = false` la désactive — cas import AD (post-review 4.11
     * #3) : les données VIENNENT d'AD, l'OU y est déjà la bonne, un move
     * serait un no-op par poste importé.
     *
     * @throws \InvalidArgumentException machine/salle introuvable ou non physique
     */
    public function assignMachineToPhysicalRoom(int $machineId, ?int $roomId, bool $dispatchAdSync = true): bool
    {
        $machine = $this->repository->findMachine($machineId);
        if (!$machine) {
            throw new \InvalidArgumentException("Machine non trouvée: {$machineId}");
        }

        if ($roomId !== null) {
            $room = $this->repository->findGroup($roomId);
            if (!$room) {
                throw new \InvalidArgumentException("Salle non trouvée: {$roomId}");
            }
            if (!$room->is_physical) {
                throw new \InvalidArgumentException("Le groupe '{$room->name}' n'est pas une salle physique");
            }
        }

        // Capture AVANT la transaction. En cas de swaps concurrents du même
        // poste, $oldRoomId peut être rassis et inhiber le dispatch move
        // ci-dessous — risque accepté (review 4.11 #2) : n'affecte que la
        // propagation OU AD (job idempotent, tries=3), jamais l'intégrité du
        // pivot, et le scénario est quasi inexistant en pratique.
        $oldRoomId = $machine->physicalRoom?->id;

        DB::transaction(function () use ($machine, $roomId) {
            // Detach de TOUTES les salles physiques courantes (en pratique <= 1,
            // mais on nettoie défensivement un éventuel état double).
            $currentPhysicalIds = $machine->physicalRooms()->pluck('workstation_groups.id')->all();
            if (!empty($currentPhysicalIds)) {
                $machine->groups()->detach($currentPhysicalIds);
            }

            if ($roomId !== null) {
                // syncWithoutDetaching ne touche pas aux parcs logiques.
                $machine->groups()->syncWithoutDetaching([$roomId]);
            }
        });

        Log::info('Salle physique de la machine mise à jour', [
            'machine_id' => $machineId,
            'old_room_id' => $oldRoomId,
            'new_room_id' => $roomId,
        ]);

        // Propagation OU AD : uniquement si la cible existe ET diffère de
        // l'ancienne salle (pas de move parasite, ni sur un simple detach).
        if ($dispatchAdSync && $roomId !== null && $oldRoomId !== $roomId) {
            WorkstationMembershipAdSyncJob::dispatch($machineId, $roomId, WorkstationMembershipAdSyncJob::ACTION_MOVE);
        }

        return true;
    }

    /**
     * Vérifie si une machine nécessite une confirmation pour être déplacée.
     *
     * Story 4.11 — lecture de la salle courante via le pivot (`physicalRoom`).
     */
    public function checkPhysicalRoomConflict(int $machineId, int $targetGroupId): ?array
    {
        $machine = $this->repository->findMachine($machineId);
        if (!$machine) {
            return null;
        }

        $currentRoom = $machine->physicalRoom;
        if ($currentRoom === null) {
            return null;
        }

        $targetGroup = $this->repository->findGroup($targetGroupId);
        if (!$targetGroup || !$targetGroup->is_physical) {
            return null;
        }

        if ($currentRoom->id === $targetGroupId) {
            return null;
        }

        return [
            'machine_id' => $machineId,
            'machine_name' => $machine->name,
            'current_room_id' => $currentRoom->id,
            'current_room_name' => $currentRoom->name ?? 'Inconnue',
            'target_room_id' => $targetGroupId,
            'target_room_name' => $targetGroup->name,
            'message' => "La machine '{$machine->name}' est actuellement dans la salle physique '{$currentRoom->name}'. Voulez-vous la déplacer vers '{$targetGroup->name}' ?",
        ];
    }

    /**
     * Déplace une machine vers une nouvelle salle physique avec confirmation
     */
    public function moveMachineToPhysicalRoom(int $machineId, int $newRoomId, bool $confirmed = false): array
    {
        $conflict = $this->checkPhysicalRoomConflict($machineId, $newRoomId);

        if ($conflict && !$confirmed) {
            return [
                'success' => false,
                'requires_confirmation' => true,
                'conflict' => $conflict,
            ];
        }

        $this->assignMachineToPhysicalRoom($machineId, $newRoomId);

        return [
            'success' => true,
            'requires_confirmation' => false,
            'message' => 'Machine déplacée avec succès',
        ];
    }

    // ========================================
    // VALIDATION
    // ========================================

    // ========================================
    // IMPORT DEPUIS L'AD (MIGRATION INITIALE)
    // ========================================

    /**
     * Importe les groupes de postes depuis l'Active Directory vers la base de données SQL.
     * 
     * ⚠️ WARNING: Cette méthode ne devrait être utilisée QUE pour l'initialisation initiale
     * de la base de données Laravel. Une fois l'import effectué, SQL devient la source de vérité
     * et les modifications doivent être faites via l'interface Laravel, qui synchronisera
     * automatiquement vers l'AD via les observers.
     * 
     * @param callable|null $logCallback Callback pour les logs (fn(string $level, string $message) => void)
     * @return array Statistiques d'import ['created' => int, 'updated' => int, 'skipped' => int, 'errors' => array]
     */
    public function importFromAd(?callable $logCallback = null): array
    {
        Log::warning('WorkstationGroupService::importFromAd() appelé - Cette méthode ne devrait être utilisée que pour l\'initialisation initiale. SQL est la source de vérité.');

        $log = $logCallback ?? fn(string $level, string $message) => Log::log($level, $message);
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'linked' => 0,
            'etab_excluded' => 0,
            'errors' => [],
        ];

        try {
            $dnHelper = app(LdapDnHelper::class);
            $computersDn = $dnHelper->computers();
            $log('info', "Recherche dans: {$computersDn}");

            $establishmentCode = SEConfig::getCurrentEstablishmentCode();
            $establishmentDn = null;
            $allowedOuNames = null; // null = pas de filtrage, sinon set de noms d'OU lowercase autorisés
            $scopedWorkstationDns = null;
            if (! empty($establishmentCode) && $establishmentCode !== '0') {
                $establishmentDn = SEConfig::ldap()->etablissementDn($establishmentCode);
                $scope = app(EstablishmentWorkstationScope::class);
                $allowedOuNames = array_flip($scope->parentOuNames($establishmentDn));
                $scopedWorkstationDns = array_flip($scope->workstationDns($establishmentDn));
                $log('info', sprintf(
                    'Filtre établissement actif: %s (%d OU autorisées, %d postes scopés)',
                    $establishmentCode,
                    count($allowedOuNames),
                    count($scopedWorkstationDns)
                ));
            } else {
                $log('info', 'Aucun établissement sélectionné — import en mode domaine entier');
            }

            // Récupérer les OU depuis l'AD
            $groupsAd = DeviceGroupModel::in($computersDn)->get();
            $log('info', count($groupsAd) . ' groupes (OU) trouvés dans l\'AD');

            // Désactiver la synchronisation AD pendant l'import
            WorkstationGroupObserver::disableSync();

            try {
                DB::beginTransaction();

                // Première passe : créer/mettre à jour les groupes
                foreach ($groupsAd as $group) {
                    try {
                        $name = $group->getGroupName();
                        if (empty($name)) {
                            continue;
                        }

                        if ($allowedOuNames !== null && ! isset($allowedOuNames[strtolower($name)])) {
                            $stats['etab_excluded']++;
                            continue;
                        }

                        $dn = $group->getDn();
                        $rawGuid = $group->getFirstAttribute('objectguid');
                        $uuid = $rawGuid ? $this->convertGuidToString($rawGuid) : null;
                        $description = $group->getGroupDescription();

                        $existing = WorkstationGroup::where('name', $name)->first();

                        if ($existing) {
                            $updated = false;
                            if (empty($existing->ad_guid) && !empty($uuid)) {
                                $existing->ad_guid = $uuid;
                                $updated = true;
                            }
                            if (empty($existing->ad_dn) && !empty($dn)) {
                                $existing->ad_dn = $dn;
                                $updated = true;
                            }
                            if (empty($existing->description) && !empty($description)) {
                                $existing->description = $description;
                                $updated = true;
                            }
                            if ($name === 'computers' && empty($existing->locked)) {
                                $existing->locked = LockReason::ROOT->value;
                                $updated = true;
                            }
                            // S'assurer que is_physical est true pour les groupes importés depuis OU=Computers
                            if (!$existing->is_physical) {
                                $existing->is_physical = true;
                                $updated = true;
                            }

                            if ($updated) {
                                $existing->save();
                                $stats['updated']++;
                                $log('info', "Mis à jour: {$name}");
                            } else {
                                $stats['skipped']++;
                            }
                        } else {
                            WorkstationGroup::create([
                                'name' => $name,
                                'is_physical' => true, // Groupe physique (OU dans OU=Computers)
                                'description' => $description,
                                'ad_dn' => $dn,
                                'ad_guid' => $uuid,
                                'is_active' => true,
                            ]);

                            $stats['created']++;
                            $log('success', "Créé: {$name}");
                        }
                    } catch (\Exception $e) {
                        $groupName = $group->getGroupName() ?? 'inconnu';
                        $stats['errors'][] = "Erreur pour {$groupName}: " . $e->getMessage();
                        $log('error', "Erreur pour {$groupName}: " . $e->getMessage());
                    }
                }

                // Deuxième passe : établir les liens parent_id depuis les DN
                $allGroups = WorkstationGroup::physical()->get()->keyBy(fn($g) => strtolower($g->name));
                foreach ($allGroups as $group) {
                    if (empty($group->ad_dn)) {
                        continue;
                    }
                    $parentName = $this->extractParentGroupFromDn($group->ad_dn);
                    if ($parentName && $allGroups->has(strtolower($parentName))) {
                        $parent = $allGroups->get(strtolower($parentName));
                        if ($group->parent_id !== $parent->id) {
                            $group->parent_id = $parent->id;
                            $group->save();
                            $stats['linked']++;
                        }
                    }
                }

                // Troisième passe : lier workstation <-> salle physique via le
                // swap du service (post-review 4.11 #3) — point d'écriture
                // unique D2, invariant 1-salle-max. L'ancien attach pivot brut
                // gardé par `wherePivot('physical', true)` était aveugle aux
                // lignes posées par le swap (colonne morte non écrite) : le
                // re-attach violait `wg_ws_unique` et rollbackait tout l'import.
                // `dispatchAdSync: false` : les données viennent d'AD, l'OU y
                // est déjà la bonne.
                $workstations = Workstation::whereNotNull('ad_dn')->get();
                $stats['workstation_links'] = 0;
                foreach ($workstations as $workstation) {
                    // Si l'on a un scope étab actif, ne lier que les postes appartenant à l'étab.
                    if ($scopedWorkstationDns !== null && ! isset($scopedWorkstationDns[strtolower(trim((string) $workstation->ad_dn))])) {
                        continue;
                    }
                    $groupName = $this->extractParentGroupFromDn($workstation->ad_dn);
                    if ($groupName && $allGroups->has(strtolower($groupName))) {
                        $group = $allGroups->get(strtolower($groupName));
                        if (!$group->is_physical) {
                            continue;
                        }
                        if ($workstation->physicalRoom?->id !== $group->id) {
                            $this->assignMachineToPhysicalRoom((int) $workstation->id, (int) $group->id, dispatchAdSync: false);
                            $stats['workstation_links']++;
                        }
                    }
                }
                $log('info', "{$stats['workstation_links']} liens workstation-groupe créés");

                DB::commit();

            } finally {
                WorkstationGroupObserver::enableSync();
            }

            $log('info', "Résultat: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} ignorés");

        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors'][] = 'Erreur globale: ' . $e->getMessage();
            $log('error', 'Erreur lors de l\'import: ' . $e->getMessage());
            Log::error('WorkstationGroupService::importFromAd erreur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $stats;
    }

    /**
     * Importe les groupes logiques depuis OU=Parcs vers la base de données SQL.
     * 
     * ⚠️ WARNING: Cette méthode ne devrait être utilisée QUE pour l'initialisation initiale.
     * 
     * @deprecated Utiliser uniquement pour la migration initiale AD → SQL
     * @param callable|null $logCallback Callback pour les logs
     * @return array Statistiques d'import
     */
    public function importLogicalGroupsFromAd(?callable $logCallback = null): array
    {
        Log::warning('WorkstationGroupService::importLogicalGroupsFromAd() appelé - Migration initiale.');

        $log = $logCallback ?? fn(string $level, string $message) => Log::log($level, $message);
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'etab_excluded' => 0,
            'errors' => [],
        ];

        try {
            $dnHelper = app(LdapDnHelper::class);
            $parcsDn = $dnHelper->parcs();
            $log('info', "Recherche des groupes logiques dans: {$parcsDn}");

            $establishmentCode = SEConfig::getCurrentEstablishmentCode();
            $establishmentDn = null;
            $scopedWorkstationDns = null;
            if (! empty($establishmentCode) && $establishmentCode !== '0') {
                $establishmentDn = SEConfig::ldap()->etablissementDn($establishmentCode);
                $scope = app(EstablishmentWorkstationScope::class);
                $scopedWorkstationDns = array_flip($scope->workstationDns($establishmentDn));
                $log('info', sprintf(
                    'Filtre établissement actif: %s (%d postes scopés)',
                    $establishmentCode,
                    count($scopedWorkstationDns)
                ));
            } else {
                $log('info', 'Aucun établissement sélectionné — import en mode domaine entier');
            }

            // Récupérer les groupes depuis OU=Parcs
            $groupsAd = DeviceGroupTagModel::in($parcsDn)->get();
            $log('info', count($groupsAd) . ' groupes logiques (CN) trouvés dans l\'AD');

            WorkstationGroupObserver::disableSync();

            try {
                DB::beginTransaction();

                foreach ($groupsAd as $group) {
                    try {
                        $name = $group->getParcName();
                        if (empty($name)) {
                            continue;
                        }

                        if ($scopedWorkstationDns !== null && ! $this->parcHasScopedMember($group, $scopedWorkstationDns)) {
                            $stats['etab_excluded']++;
                            continue;
                        }

                        $dn = $group->getDn();
                        $rawGuid = $group->getFirstAttribute('objectguid');
                        $uuid = $rawGuid ? $this->convertGuidToString($rawGuid) : null;
                        $description = $group->getDescription();

                        $existing = WorkstationGroup::where('name', $name)->first();

                        if ($existing) {
                            // Si le groupe existe déjà et est physique, on ne le modifie pas
                            // (un groupe physique est aussi un groupe logique dans l'AD)
                            if ($existing->is_physical) {
                                $stats['skipped']++;
                                $log('info', "Ignoré (groupe physique existant): {$name}");
                                continue;
                            }

                            $updated = false;
                            if (empty($existing->ad_guid) && !empty($uuid)) {
                                $existing->ad_guid = $uuid;
                                $updated = true;
                            }
                            if (empty($existing->ad_dn) && !empty($dn)) {
                                $existing->ad_dn = $dn;
                                $updated = true;
                            }
                            if (empty($existing->description) && !empty($description)) {
                                $existing->description = $description;
                                $updated = true;
                            }
                            if ($updated) {
                                $existing->save();
                                $stats['updated']++;
                                $log('info', "Mis à jour: {$name}");
                            } else {
                                $stats['skipped']++;
                            }
                        } else {
                            $locked = ($name === 'computers') ? LockReason::ROOT->value : null;
                            WorkstationGroup::create([
                                'name' => $name,
                                'is_physical' => false, // Groupe logique (CN dans OU=Parcs)
                                'description' => $description,
                                'ad_dn' => $dn,
                                'ad_guid' => $uuid,
                                'is_active' => true,
                            ]);

                            $stats['created']++;
                            $log('success', "Créé: {$name}");
                        }
                    } catch (\Exception $e) {
                        $groupName = $group->getParcName() ?? 'inconnu';
                        $stats['errors'][] = "Erreur pour {$groupName}: " . $e->getMessage();
                        $log('error', "Erreur pour {$groupName}: " . $e->getMessage());
                    }
                }

                // Deuxième passe : créer les liens workstation <-> groupe logique
                // Les groupes dans OU=Parcs ont un attribut 'member' avec les DN des machines
                $stats['workstation_links'] = 0;
                $allWorkstations = Workstation::all()->keyBy(fn($w) => strtolower($w->name));
                
                // Indexer les groupes AD par nom pour recherche rapide
                $adGroupsByName = [];
                foreach ($groupsAd as $adGroup) {
                    $name = $adGroup->getParcName();
                    if (!empty($name)) {
                        $adGroupsByName[strtolower($name)] = $adGroup;
                    }
                }

                $logicalGroups = WorkstationGroup::logical()->get();

                foreach ($logicalGroups as $sqlGroup) {
                    // Récupérer le groupe AD correspondant
                    $adGroup = $adGroupsByName[strtolower($sqlGroup->name)] ?? null;
                    if (!$adGroup) {
                        continue;
                    }

                    // Récupérer les membres du groupe AD
                    $members = $adGroup->getFirstAttribute('member');
                    if (empty($members)) {
                        continue;
                    }
                    
                    // member peut être un string ou un array
                    $memberDns = is_array($members) ? $members : [$members];

                    foreach ($memberDns as $memberDn) {
                        // Extraire le nom de la machine depuis le DN (CN=pc-xxx,...)
                        if (preg_match('/^CN=([^,]+),/i', $memberDn, $matches)) {
                            $machineName = strtolower(rtrim($matches[1], '$')); // Enlever le $ final si présent
                            
                            if ($allWorkstations->has($machineName)) {
                                $workstation = $allWorkstations->get($machineName);
                                
                                // Vérifier si le lien existe déjà
                                $existingLink = $workstation->groups()
                                    ->where('workstation_group_id', $sqlGroup->id)
                                    ->exists();
                                    
                                if (!$existingLink) {
                                    // Post-review 4.11 #N1 — la colonne pivot
                                    // `physical` est morte, on ne l'écrit plus.
                                    $workstation->groups()->attach($sqlGroup->id);
                                    $stats['workstation_links']++;
                                }
                            }
                        }
                    }
                }
                $log('info', "{$stats['workstation_links']} liens workstation-groupe logique créés");

                DB::commit();

            } finally {
                WorkstationGroupObserver::enableSync();
            }

            $log('info', "Résultat: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} ignorés");

        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors'][] = 'Erreur globale: ' . $e->getMessage();
            $log('error', 'Erreur lors de l\'import: ' . $e->getMessage());
            Log::error('WorkstationGroupService::importLogicalGroupsFromAd erreur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $stats;
    }

    /**
     * Convertit un GUID binaire en chaîne formatée
     */
    private function convertGuidToString(string $binaryGuid): string
    {
        $hex = bin2hex($binaryGuid);
        if (strlen($hex) !== 32) {
            return $hex;
        }
        return sprintf(
            '%s%s%s%s-%s%s-%s%s-%s-%s',
            substr($hex, 6, 2), substr($hex, 4, 2), substr($hex, 2, 2), substr($hex, 0, 2),
            substr($hex, 10, 2), substr($hex, 8, 2),
            substr($hex, 14, 2), substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Extrait le nom du groupe parent depuis le DN
     * Pour une OU: OU=chimie1,OU=labos,OU=Computers,DC=... => labos
     * Pour une machine: CN=pc-xxx,OU=techno,OU=Computers,DC=... => techno
     */
    /**
     * Indique si un parc (CN sous OU=Parcs) contient au moins un member appartenant
     * au set de DN postes scopés pour l'établissement courant.
     *
     * @param  array<string,int>  $scopedWorkstationDns  Map DN lowercase → 1
     */
    private function parcHasScopedMember(DeviceGroupTagModel $parc, array $scopedWorkstationDns): bool
    {
        $members = $parc->getAttribute('member') ?? [];
        if (is_array($members) && isset($members['count'])) {
            unset($members['count']);
            $members = array_values($members);
        }
        if (! is_array($members)) {
            $members = [$members];
        }

        foreach ($members as $memberDn) {
            if (! is_string($memberDn) || $memberDn === '') {
                continue;
            }
            if (isset($scopedWorkstationDns[strtolower(trim($memberDn))])) {
                return true;
            }
        }

        return false;
    }

    private function extractParentGroupFromDn(string $dn): ?string
    {
        // Pour une machine (CN=...,OU=groupe,...)
        if (preg_match('/^CN=[^,]+,OU=([^,]+),/i', $dn, $matches)) {
            $parent = $matches[1];
            if (strtolower($parent) !== 'computers') {
                return $parent;
            }
        }
        // Pour une OU (OU=...,OU=parent,...)
        elseif (preg_match('/^OU=[^,]+,OU=([^,]+),/i', $dn, $matches)) {
            $parent = $matches[1];
            if (strtolower($parent) !== 'computers') {
                return $parent;
            }
        }
        return null;
    }

    // ========================================
    // VALIDATION
    // ========================================

    /**
     * Valide les données d'un groupe
     */
    private function validateGroupData(array $data, ?WorkstationGroup $existingGroup = null): void
    {
        if (empty($data['name'])) {
            throw new \InvalidArgumentException('Le nom du groupe est requis');
        }

        $query = WorkstationGroup::where('name', $data['name']);

        if ($existingGroup) {
            $query->where('id', '!=', $existingGroup->id);
        }

        if ($query->exists()) {
            throw new \InvalidArgumentException("Un groupe avec le nom '{$data['name']}' existe déjà");
        }

        if (!empty($data['parent_id'])) {
            $parent = WorkstationGroup::find($data['parent_id']);

            if (!$parent) {
                throw new \InvalidArgumentException("Le groupe parent {$data['parent_id']} n'existe pas");
            }

            if ($existingGroup && $data['parent_id'] == $existingGroup->id) {
                throw new \InvalidArgumentException('Un groupe ne peut pas être son propre parent');
            }
        }
    }
}
