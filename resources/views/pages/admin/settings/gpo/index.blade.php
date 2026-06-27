<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Enums\GpoHealthStatus;
use App\Gpo\Services\GpoService;
use App\Gpo\Dto\GpoSummary;
use App\Gpo\Support\CachedGpoLookups;
use App\Gpo\Support\GpoExportSerializer;
use App\Gpo\Support\GpoHealthStatusCalculator;
use App\Gpo\Support\GpoLogger;
use App\Gpo\Support\NativeSectionResolver;
use App\Repositories\OrganizationalUnitRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Page Livewire SFC — Listing des GPOs Active Directory.
 *
 * Story 16.2 + Story 16.9 — Listing GPO sous `/admin/settings/gpo`.
 * Story 16.14 — Card hero (A) + filtres avancés (B) + exports CSV/JSON (B).
 * Convention maison filesystem-based router.
 * Consomme GpoService::list() (posé par Story 16.1).
 * Périmètre : lecture seule. Les mutations passent par le shim legacy (bouton dédié page détail).
 */
new #[Title('Gestion des GPOs - SE4FS')] class extends Component {
    use WithToasts;

    // --- Propriétés réactives synchronisées avec l'URL (filtres 16.2) ---
    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    #[Url]
    public string $sortBy = 'displayName';

    #[Url]
    public string $sortDirection = 'asc';

    #[Url]
    public int $currentPage = 1;

    #[Url]
    public int $perPage = 20;

    // --- Filtres avancés 16.14 (B) ---
    #[Url(keep: true)]
    public string $filterType = '';

    #[Url(keep: true)]
    public string $filterOu = '';

    #[Url(keep: true)]
    public ?int $filterVersionMin = null;

    #[Url(keep: true)]
    public ?int $filterVersionMax = null;

    /**
     * Story 16.14 — Q1 arbitré Henri 2026-05-20 : filtre santé MULTI-valeur (AC2.2).
     * Synchronisé URL en tableau (Livewire sérialise en CSV).
     *
     * @var list<string>
     */
    #[Url(keep: true)]
    public array $filterHealthStatuses = [];

    #[Url(keep: true)]
    public bool $filterNativeOnly = false;

    // Auto-complete OU
    public string $filterOuSearch = '';
    public array $ouSuggestions = [];
    private array $allOus = [];

    // --- Card hero (16.14 A) ---
    public bool $heroDismissed = false;

    // --- Données ---
    /** @var array<array{name:string,displayName:string,versionNumber:?int,dn:?string,path:?string}> */
    public array $gpos = [];
    public int $totalGpos = 0;
    public int $totalFiltered = 0;
    public bool $hasError = false;
    public ?array $pagination = null;
    public array $allowedPerPage = [10, 20, 50, 100];

    /**
     * Story 16.14 Q2/Q3 — précompute santé via cache.
     *
     * @var array<string, int>  // GUID → nombre de liens AD
     */
    public array $linksCountByGuid = [];

    /** @var array<string, list<string>>  GUID → liste des container DNs liés (Q3). */
    public array $linkedContainersByGuid = [];

    /** @var array<string, ?int>  GUID → versionNumber réel (cache). */
    public array $versionByGuid = [];

    /** @var array<string, string>  GUID → health_status (calculé via cache). */
    public array $healthStatusByGuid = [];

    // Story 27.14 — la publication étage 2 (SYSVOL) via `GpoPublisher` (une GPO
    // ou batch) a été supprimée avec le canal de config legacy. Le listing GPO
    // reste en consultation read-only.

    private GpoService $gpoService;
    private ?CachedGpoLookups $cache = null;
    private ?OrganizationalUnitRepository $ouRepo = null;

    /**
     * Livewire invoque boot() avant mount() à chaque cycle (initial + interactions),
     * c'est l'endroit canonique du projet pour injecter les services.
     */
    public function boot(
        GpoService $service,
        CachedGpoLookups $cache,
    ): void {
        $this->gpoService = $service;
        $this->cache = $cache;
    }

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        // Récupérer l'état session de la card hero
        $this->heroDismissed = (bool) session('gpo.hero.dismissed', false);

        $this->loadGpos();
        $this->loadOus();
    }

    private function loadOus(): void
    {
        try {
            /** @var OrganizationalUnitRepository $repo */
            $repo = app(OrganizationalUnitRepository::class);
            $this->allOus = $repo->listAll(300);
        } catch (\Throwable) {
            $this->allOus = [];
        }
    }

    public function loadGpos(): void
    {
        $this->hasError = false;
        try {
            $all = $this->gpoService->list();
            $this->totalGpos = $all->count();
            $this->gpos = $all->map(fn(GpoSummary $g) => $g->toArray())->all();

            // Story 16.14 Q2/Q3/Q5 — précompute via cache (TTL 24h).
            // Tous ces appels sont cache-hit en régime nominal (warm-up 22h),
            // donc coût négligeable. Premier load (cache miss) = N appels samba-tool.
            $this->precomputeHealthAndLinks();
        } catch (\Throwable $e) {
            $this->hasError = true;
            $this->gpos = [];
            $this->totalGpos = 0;
            $this->linksCountByGuid = [];
            $this->linkedContainersByGuid = [];
            $this->versionByGuid = [];
            $this->healthStatusByGuid = [];
            $this->toast('error', 'Erreur', 'Impossible de charger les GPOs : ' . $e->getMessage());
        }
        $this->applyFiltersAndPagination();
    }

    /**
     * Story 16.14 Q2 — Précompute les comptages santé via cache 24h.
     *
     * Lit `linksCountByGuid` + `versionByGuid` via `CachedGpoLookups`. Les
     * appels samba-tool en dessous ne sont déclenchés que sur cache miss
     * (warm-up daily 22h les couvre).
     */
    private function precomputeHealthAndLinks(): void
    {
        if ($this->cache === null) {
            // Sécurité défensive : si DI n'a pas fourni le cache, fallback no-op.
            return;
        }

        $this->linksCountByGuid = [];
        $this->linkedContainersByGuid = [];
        $this->versionByGuid = [];

        foreach ($this->gpos as $g) {
            $guid = $g['name'];
            try {
                $links = $this->cache->getLinksFor($guid);
                $this->linksCountByGuid[$guid] = count($links);
                $this->linkedContainersByGuid[$guid] = array_values(array_map(
                    fn($link) => $link->containerDn,
                    $links,
                ));

                $cachedVersion = $this->cache->getVersionNumberFor($guid);
                $this->versionByGuid[$guid] = $cachedVersion;
                // Si la version est connue via cache, on l'injecte dans la copie locale
                // pour que les filtres version min/max + exports voient la vraie valeur.
                if ($cachedVersion !== null) {
                    foreach ($this->gpos as $i => $gpo) {
                        if (($gpo['name'] ?? null) === $guid) {
                            $this->gpos[$i]['versionNumber'] = $cachedVersion;
                            break;
                        }
                    }
                }
            } catch (\Throwable) {
                // best-effort : on continue avec les autres GPOs.
                $this->linksCountByGuid[$guid] = 0;
                $this->linkedContainersByGuid[$guid] = [];
                $this->versionByGuid[$guid] = null;
            }
        }

        // Calcul du statut santé batch (incluant détection cross-GPO de `conflicting`).
        $rawStatuses = GpoHealthStatusCalculator::calculateBatch(
            collect($this->gpos),
            $this->linksCountByGuid,
        );
        $this->healthStatusByGuid = [];
        foreach ($rawStatuses as $guid => $enum) {
            $this->healthStatusByGuid[$guid] = $enum->value;
        }
    }

    public function refresh(): void
    {
        $this->loadGpos();
        if (!$this->hasError) {
            $this->toast('success', 'Liste rafraîchie', $this->totalGpos . ' GPO(s) chargée(s)');
        }
    }

    /**
     * Story 16.14 Q2 — Rafraîchit le cache santé (flush + reload lazy).
     *
     * Action admin (server.admin) — émet log `gpo.cache.flush` avec actor_user_id.
     */
    public function refreshHealthCache(): void
    {
        $log = GpoLogger::action('gpo.cache.flush', context: [
            'actor_user_id' => auth()->id(),
            'invoked_by' => 'ui_button',
        ]);

        try {
            if ($this->cache !== null) {
                $this->cache->forgetAll();
            } else {
                app(CachedGpoLookups::class)->forgetAll();
            }
            // Re-lecture lazy : les appels suivants déclencheront cache miss → samba-tool.
            $this->loadGpos();
            $log->success(['count' => $this->totalGpos]);
            $this->toast('info', 'Cache santé rafraîchi', 'Les statuts santé ont été recalculés.');
        } catch (\Throwable $e) {
            $log->failure($e);
            $this->toast('error', 'Erreur', 'Impossible de rafraîchir le cache : ' . $e->getMessage());
        }
    }

    // --- Card hero (16.14 A) ---

    public function dismissHero(): void
    {
        session()->put('gpo.hero.dismissed', true);
        $this->heroDismissed = true;
    }

    public function showHero(): void
    {
        session()->forget('gpo.hero.dismissed');
        $this->heroDismissed = false;
    }

    // --- Filtres avancés (16.14 B) ---

    public function updatedFilterOuSearch(): void
    {
        if (strlen($this->filterOuSearch) < 2) {
            $this->ouSuggestions = [];
            return;
        }
        $search = strtolower($this->filterOuSearch);
        $this->ouSuggestions = array_slice(
            array_filter(
                $this->allOus,
                fn($ou) => str_contains(strtolower($ou), $search)
            ),
            0,
            20
        );
    }

    public function selectFilterOu(string $ou): void
    {
        $this->filterOu = $ou;
        $this->filterOuSearch = $ou;
        $this->ouSuggestions = [];
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function clearFilterOu(): void
    {
        $this->filterOu = '';
        $this->filterOuSearch = '';
        $this->ouSuggestions = [];
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function updatedFilterType(): void
    {
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function updatedFilterHealthStatuses(): void
    {
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function updatedFilterVersionMin(): void
    {
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function updatedFilterVersionMax(): void
    {
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function updatedFilterNativeOnly(): void
    {
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function resetAdvancedFilters(): void
    {
        $this->filterType = '';
        $this->filterOu = '';
        $this->filterOuSearch = '';
        $this->ouSuggestions = [];
        $this->filterVersionMin = null;
        $this->filterVersionMax = null;
        $this->filterHealthStatuses = [];
        $this->filterNativeOnly = false;
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function getAdvancedFiltersCountProperty(): int
    {
        $count = 0;
        if (!empty($this->filterType)) {
            $count++;
        }
        if (!empty($this->filterOu)) {
            $count++;
        }
        if ($this->filterVersionMin !== null) {
            $count++;
        }
        if ($this->filterVersionMax !== null) {
            $count++;
        }
        if (!empty($this->filterHealthStatuses)) {
            $count++;
        }
        if ($this->filterNativeOnly) {
            $count++;
        }
        return $count;
    }

    /**
     * Réinitialise les filtres (recherche + statut) et la pagination.
     * Utilisé par le bouton "Effacer les filtres" de l'état vide (AC1.8).
     */
    public function clearFilters(): void
    {
        $this->search = '';
        $this->statusFilter = 'all';
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    /**
     * Réinitialise TOUS les filtres : basiques ET avancés (finding #15).
     * Utilisé par le bouton "Tout effacer" dans le panneau de filtres avancés.
     */
    public function resetAllFilters(): void
    {
        $this->clearFilters();
        $this->resetAdvancedFilters();
    }

    public function updatedSearch(): void
    {
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function updatedStatusFilter(): void
    {
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function updatedPerPage(): void
    {
        if (!in_array($this->perPage, $this->allowedPerPage)) {
            $this->perPage = 20;
        }
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDirection = 'asc';
        }
        $this->currentPage = 1;
        $this->applyFiltersAndPagination();
    }

    public function goToPage(int $page): void
    {
        $lastPage = max(1, (int) ceil($this->totalFiltered / $this->perPage));
        $this->currentPage = max(1, min($page, $lastPage));
        $this->applyFiltersAndPagination();
    }

    // --- Exports (16.14 B) ---

    public function exportCsv(): mixed
    {
        $collection = $this->getFilteredCollection();
        $gposCollection = collect($this->gpos);
        $filtered = $gposCollection->filter(fn($g) => $collection->contains('name', $g['name']));

        $count = $filtered->count();
        $filename = 'gpo-export-' . now()->utc()->format('Y-m-d-His') . '.csv';

        $log = GpoLogger::action('gpo.export.csv', context: ['count' => $count, 'actor_user_id' => auth()->id()]);

        try {
            // Story 16.14 Q5 — passer les comptages réels (depuis cache Q2)
            // pour que version_major/minor + ou_links_count + health_status soient corrects.
            $statusByGuid = $this->buildHealthEnumMap();
            $csvContent = GpoExportSerializer::toCsvString(
                $filtered,
                $this->linksCountByGuid,
                $statusByGuid,
            );
            $log->success(['count' => $count]);
            $this->toastSuccess("Export CSV généré ({$count} GPOs)");
        } catch (\Throwable $e) {
            $log->failure($e);
            $this->toast('error', 'Erreur', 'Impossible de générer le CSV : ' . $e->getMessage());
            return null;
        }

        return Response::streamDownload(
            function () use ($csvContent) {
                echo $csvContent;
            },
            $filename,
            [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }

    public function exportJson(): mixed
    {
        $collection = $this->getFilteredCollection();
        $gposCollection = collect($this->gpos);
        $filtered = $gposCollection->filter(fn($g) => $collection->contains('name', $g['name']));

        $count = $filtered->count();
        $filename = 'gpo-export-' . now()->utc()->format('Y-m-d-His') . '.json';

        $log = GpoLogger::action('gpo.export.json', context: ['count' => $count, 'actor_user_id' => auth()->id()]);

        try {
            // Story 16.14 Q5 — comptages réels via cache Q2.
            $statusByGuid = $this->buildHealthEnumMap();
            $jsonContent = GpoExportSerializer::toJsonString(
                $filtered,
                $this->linksCountByGuid,
                $statusByGuid,
            );
            $log->success(['count' => $count]);
            $this->toastSuccess("Export JSON généré ({$count} GPOs)");
        } catch (\Throwable $e) {
            $log->failure($e);
            $this->toast('error', 'Erreur', 'Impossible de générer le JSON : ' . $e->getMessage());
            return null;
        }

        return Response::streamDownload(
            function () use ($jsonContent) {
                echo $jsonContent;
            },
            $filename,
            [
                'Content-Type' => 'application/json',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ]
        );
    }

    /**
     * Convertit $healthStatusByGuid (string values) en map GUID → GpoHealthStatus enum
     * pour le sérialiseur d'export.
     *
     * @return array<string, GpoHealthStatus>
     */
    private function buildHealthEnumMap(): array
    {
        $map = [];
        foreach ($this->healthStatusByGuid as $guid => $value) {
            $enum = GpoHealthStatus::tryFrom((string) $value);
            if ($enum !== null) {
                $map[$guid] = $enum;
            }
        }
        return $map;
    }

    private function applyFiltersAndPagination(): void
    {
        $collection = $this->getFilteredCollection();
        $this->totalFiltered = $collection->count();

        // Pagination
        $lastPage = max(1, (int) ceil($this->totalFiltered / $this->perPage));
        $this->currentPage = min($this->currentPage, $lastPage);
        $offset = ($this->currentPage - 1) * $this->perPage;
        $from = $this->totalFiltered > 0 ? $offset + 1 : 0;
        $to = min($offset + $this->perPage, $this->totalFiltered);

        $this->pagination = [
            'current_page' => $this->currentPage,
            'per_page' => $this->perPage,
            'total' => $this->totalFiltered,
            'last_page' => $lastPage,
            'from' => $from,
            'to' => $to,
            'has_more_pages' => $this->currentPage < $lastPage,
            'items' => $collection->slice($offset, $this->perPage)->values()->all(),
        ];
    }

    private function getFilteredCollection(): Collection
    {
        $collection = collect($this->gpos);

        // Filtre recherche (case-insensitive, substring)
        if (!empty($this->search)) {
            $search = $this->search;
            $collection = $collection->filter(
                fn($g) => mb_stripos($g['displayName'], $search) !== false
            );
        }

        // Filtre statut (16.2)
        if ($this->statusFilter === 'active') {
            $collection = $collection->filter(fn($g) => isset($g['versionNumber']) && $g['versionNumber'] > 0);
        } elseif ($this->statusFilter === 'inactive') {
            $collection = $collection->filter(fn($g) => !isset($g['versionNumber']) || $g['versionNumber'] === 0 || $g['versionNumber'] === null);
        }

        // Filtre avancé : Type (D12 heuristique préfixes)
        if (!empty($this->filterType)) {
            $type = $this->filterType;
            $collection = $collection->filter(function ($g) use ($type) {
                $lower = strtolower($g['displayName'] ?? '');
                return match ($type) {
                    'machine' => str_starts_with($lower, 'se4_app') || str_starts_with($lower, 'se4_machine'),
                    'user'    => str_starts_with($lower, 'se4_user'),
                    'logon'   => str_starts_with($lower, 'se4_logon'),
                    'other'   => !str_starts_with($lower, 'se4_app')
                        && !str_starts_with($lower, 'se4_machine')
                        && !str_starts_with($lower, 'se4_user')
                        && !str_starts_with($lower, 'se4_logon'),
                    default   => true,
                };
            });
        }

        // Filtre avancé : Version min/max (sur versionNumber brut — major = version >> 16)
        if ($this->filterVersionMin !== null) {
            $min = $this->filterVersionMin;
            $collection = $collection->filter(function ($g) use ($min) {
                $v = $g['versionNumber'] ?? 0;
                $major = $v >> 16;
                return $major >= $min;
            });
        }
        if ($this->filterVersionMax !== null) {
            $max = $this->filterVersionMax;
            $collection = $collection->filter(function ($g) use ($max) {
                $v = $g['versionNumber'] ?? 0;
                $major = $v >> 16;
                return $major <= $max;
            });
        }

        // Story 16.14 Q1 — Filtre santé MULTI-valeur (AC2.2).
        // Utilise les statuts pré-calculés via cache (Q2) — vraies données.
        if (!empty($this->filterHealthStatuses)) {
            $targets = array_values(array_filter(
                $this->filterHealthStatuses,
                fn($v) => is_string($v) && $v !== '',
            ));
            if (!empty($targets)) {
                $statuses = $this->healthStatusByGuid;
                $collection = $collection->filter(function ($g) use ($targets, $statuses) {
                    $guid = $g['name'] ?? '';
                    $status = $statuses[$guid] ?? GpoHealthStatus::Healthy->value;
                    return in_array($status, $targets, true);
                });
            }
        }

        // Filtre avancé : Sections natives uniquement
        if ($this->filterNativeOnly) {
            $collection = $collection->filter(
                fn($g) => NativeSectionResolver::hasMatch($g['displayName'] ?? '')
            );
        }

        // Story 16.14 Q3 — Filtre OU liée listing principal via cache Q2.
        // Validation : la valeur sélectionnée doit appartenir à $allOus connue ;
        // sinon on ignore le filtre (anti-injection URL — finding #17).
        if (!empty($this->filterOu) && in_array($this->filterOu, $this->allOus, true)) {
            $ouFilter = $this->filterOu;
            $linkedByGuid = $this->linkedContainersByGuid;
            $collection = $collection->filter(function ($g) use ($ouFilter, $linkedByGuid) {
                $guid = $g['name'] ?? '';
                $linked = $linkedByGuid[$guid] ?? [];
                // Match si la GPO est liée à $ouFilter OU à un descendant
                // (un DN child contient son parent — `OU=Classe,OU=Eleves,DC=...`
                // matche aussi un filtre sur `OU=Eleves,DC=...`).
                foreach ($linked as $containerDn) {
                    if ($containerDn === $ouFilter || str_ends_with($containerDn, ',' . $ouFilter)) {
                        return true;
                    }
                    // Inversement : le containerDn est un parent du filtre → la GPO
                    // s'applique aussi (héritage descendant). Best-effort substring.
                    if (str_ends_with($ouFilter, ',' . $containerDn)) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Tri
        $sortBy = $this->sortBy;
        $sortDirection = $this->sortDirection;
        $collection = $collection->sortBy(
            fn($g) => match ($sortBy) {
                'version' => $g['versionNumber'] ?? 0,
                default => strtolower($g['displayName'] ?? ''),
            },
            SORT_REGULAR,
            $sortDirection === 'desc',
        );

        return $collection;
    }

    /** @return list<array> */
    public function paginatedGpos(): array
    {
        return $this->pagination['items'] ?? [];
    }

    // Story 27.14 — Publication étage 2 (SYSVOL) SUPPRIMÉE avec le canal de
    // config legacy : `isGpoPublishable`, `getPublishableCountProperty`,
    // `openPublishOne`, `openPublishAll`, `closePublishModal`, `confirmPublish`,
    // `runSinglePublish`, `runBatchPublish`, `invalidateGpoCache` (consommé
    // uniquement par la publication) retirés. Le listing GPO reste read-only.

    public function formatVersion(?int $version): string
    {
        if ($version === null) {
            return '—';
        }
        $major = $version >> 16;
        $minor = $version & 0xFFFF;
        return $major . '.' . $minor;
    }
};
?>

@php
    $hasFilters = !empty($search) || $statusFilter !== 'all';
    $advancedFiltersCount = $this->getAdvancedFiltersCountProperty();
    $paginatedItems = $this->paginatedGpos();
    $emptyStateMessage = $hasFilters || $advancedFiltersCount > 0
        ? 'Aucune GPO ne correspond aux critères de recherche.'
        : 'Aucune GPO trouvée dans le domaine AD.';
@endphp

<x-organisms.page title="Gestion des GPOs" :scrollable="false"
    description="Consultez et gérez les Group Policy Objects (GPO) Active Directory de l'établissement.">

    <x-slot:actions>
        {{-- Toutes les actions de la page regroupées dans un seul dropdown (pattern /users). --}}
        <x-molecules.action-menu label="Actions" icon="fa-bars" width="w-72" testid="gpo-actions-menu">
            {{-- Story 27.14 — action « Publier tout » retirée avec l'extinction
                 du canal de config legacy. --}}
            <li class="menu-title text-xs opacity-60">Données</li>
            <li>
                <button type="button" wire:click="exportCsv" wire:loading.attr="disabled"
                    data-testid="export-csv-btn">
                    <i class="fa-solid fa-file-csv w-4"></i>
                    Export CSV
                </button>
            </li>
            <li>
                <button type="button" wire:click="exportJson" wire:loading.attr="disabled"
                    data-testid="export-json-btn">
                    <i class="fa-solid fa-file-code w-4"></i>
                    Export JSON
                </button>
            </li>
            <li class="menu-title text-xs opacity-60">Rafraîchir</li>
            <li>
                <button type="button" wire:click="refreshHealthCache" wire:loading.attr="disabled"
                    wire:target="refreshHealthCache" data-testid="refresh-health-cache-btn"
                    title="Vide le cache santé GPO (links + versionNumber) et recalcule à la demande.">
                    <i class="fa-solid fa-heart-pulse w-4"></i>
                    Rafraîchir le cache santé
                </button>
            </li>
            <li>
                <button type="button" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">
                    <i class="fa-solid fa-arrows-rotate w-4"></i>
                    Rafraîchir la liste
                </button>
            </li>
        </x-molecules.action-menu>
    </x-slot:actions>

    <div id="gpo-listing" class="h-full">
        <div class="flex flex-col h-full gap-4">

            {{-- Card hero onboarding (16.14 A) --}}
            <x-molecules.gpo-onboarding-card :dismissed="$heroDismissed" />

            {{-- Panneau filtres avancés (16.14 B) --}}
            @include('pages.admin.settings.gpo._partials.advanced-filters-panel', [
                'advancedFiltersCount' => $advancedFiltersCount,
                'ouSuggestions' => $ouSuggestions,
            ])

            {{-- Barre de recherche et filtres (16.2) --}}
            <div class="space-y-3" id="listing-gpos">
                <div class="flex flex-wrap gap-4 items-center">
                    {{-- Recherche --}}
                    <div class="flex-1 min-w-48">
                        <x-atoms.searchInput wire:model.live.debounce.500ms="search" id="gpoSearch"
                            placeholder="Rechercher par nom de GPO…" icon="fa-magnifying-glass" class="w-full" />
                    </div>

                    {{-- Filtre statut --}}
                    <div class="form-control min-w-36">
                        <select wire:model.live="statusFilter" class="select select-bordered select-sm">
                            <option value="all">Toutes les GPOs</option>
                            <option value="active">Actives (version > 0)</option>
                            <option value="inactive">Inactives (version = 0)</option>
                        </select>
                    </div>

                    {{-- Compteur --}}
                    <div class="text-sm text-base-content/60 whitespace-nowrap">
                        {{ $totalFiltered }} GPO(s) sur {{ $totalGpos }}
                    </div>
                </div>

                {{-- Badges filtres actifs --}}
                @if ($hasFilters)
                    <div class="flex flex-wrap gap-2">
                        @if (!empty($search))
                            <div class="badge badge-primary gap-2">
                                <span class="text-xs opacity-70">Recherche :</span>
                                <span>{{ $search }}</span>
                            </div>
                        @endif
                        @if ($statusFilter !== 'all')
                            <div class="badge badge-primary gap-2">
                                <span class="text-xs opacity-70">Statut :</span>
                                <span>{{ $statusFilter === 'active' ? 'Actives' : 'Inactives' }}</span>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Erreur de chargement --}}
            @if ($hasError)
                <div class="alert alert-error shadow-sm">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <div>
                        <p class="font-medium">Impossible de charger la liste des GPOs</p>
                        <p class="text-sm opacity-80">Vérifiez que samba-tool est disponible et que le ticket Kerberos est valide.</p>
                    </div>
                    <button type="button" class="btn btn-sm btn-error" wire:click="refresh">
                        <i class="fa-solid fa-arrows-rotate"></i> Réessayer
                    </button>
                </div>
            @endif

            {{-- Tableau --}}
            @if (!$hasError)
                <div class="card bg-base-100 shadow-sm overflow-hidden">
                    <div class="px-4 py-3 border-b border-base-300 text-sm text-base-content/70">
                        @if ($hasFilters || $advancedFiltersCount > 0)
                            Résultats filtrés : {{ $totalFiltered }} GPO(s) sur {{ $totalGpos }}
                        @else
                            {{ $totalGpos }} GPO(s) dans le domaine AD
                        @endif
                    </div>

                    <div class="overflow-x-auto">
                        <table class="table table-zebra">
                            <thead>
                                <tr>
                                    <th>
                                        <button type="button" class="flex items-center gap-1 hover:text-primary"
                                            wire:click="sort('displayName')">
                                            Nom de la GPO
                                            @if ($sortBy === 'displayName')
                                                <i class="fa-solid fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }} text-xs text-primary"></i>
                                            @else
                                                <i class="fa-solid fa-sort text-xs opacity-40"></i>
                                            @endif
                                        </button>
                                    </th>
                                    <th>
                                        <button type="button" class="flex items-center gap-1 hover:text-primary"
                                            wire:click="sort('version')">
                                            Version
                                            @if ($sortBy === 'version')
                                                <i class="fa-solid fa-sort-{{ $sortDirection === 'asc' ? 'up' : 'down' }} text-xs text-primary"></i>
                                            @else
                                                <i class="fa-solid fa-sort text-xs opacity-40"></i>
                                            @endif
                                        </button>
                                    </th>
                                    <th>GUID</th>
                                    <th>Path SYSVOL</th>
                                    <th>
                                        <span class="flex items-center gap-1">
                                            <i class="fa-solid fa-circle-check text-success text-xs"></i>
                                            Édition native
                                        </span>
                                    </th>
                                    {{-- Story 27.14 — colonne « Actions » (publication SYSVOL) retirée. --}}
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($paginatedItems as $gpo)
                                    @php
                                        $version = $gpo['versionNumber'] ?? 0;
                                        $isActive = $version > 0;
                                        // Strip accolades du GUID Microsoft `{...}` : Laravel/Symfony UrlGenerator
                                        // ré-interprète les `{` `}` comme placeholders et lève UrlGenerationException.
                                        $detailUrl = route('admin.gpo.show', ['guid' => trim((string) $gpo['name'], '{}')]);
                                        $nativeMatches = \App\Gpo\Support\NativeSectionResolver::resolve($gpo['displayName'] ?? '');
                                        $nativeCount = count($nativeMatches);
                                    @endphp
                                    <tr class="hover:bg-sky-50 cursor-pointer"
                                        onclick="if (!event.target.closest('.native-edit-cell') && !event.target.closest('.gpo-actions-cell')) window.location.href='{{ $detailUrl }}'">
                                        <td>
                                            <div class="flex flex-col gap-0.5">
                                                <span class="font-semibold">{{ $gpo['displayName'] }}</span>
                                                <span class="badge badge-xs {{ $isActive ? 'badge-success' : 'badge-ghost' }}">
                                                    {{ $isActive ? 'Active' : 'Inactive' }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="font-mono text-sm">
                                            {{ $this->formatVersion($gpo['versionNumber'] ?? null) }}
                                        </td>
                                        <td>
                                            <x-atoms.tooltip position="top">
                                                <x-slot name="trigger">
                                                    <span class="font-mono text-xs text-base-content/60">
                                                        {{ substr($gpo['name'] ?? '', 0, 10) }}…
                                                    </span>
                                                </x-slot>
                                                <span class="font-mono text-xs">{{ $gpo['name'] }}</span>
                                            </x-atoms.tooltip>
                                        </td>
                                        <td>
                                            @if (isset($gpo['path']) && $gpo['path'])
                                                <x-atoms.tooltip position="top">
                                                    <x-slot name="trigger">
                                                        <span class="font-mono text-xs text-base-content/60">
                                                            {{ Str::limit(basename($gpo['path'] ?? ''), 30) }}
                                                        </span>
                                                    </x-slot>
                                                    <span class="font-mono text-xs">{{ $gpo['path'] }}</span>
                                                </x-atoms.tooltip>
                                            @else
                                                <span class="text-base-content/30 text-xs">—</span>
                                            @endif
                                        </td>
                                        {{-- Story 16.3a — Colonne "Édition native" (AC3.1 / D3) --}}
                                        <td class="native-edit-cell" data-testid="native-edit-cell">
                                            @if ($nativeCount === 0)
                                                <span class="text-base-content/30" data-testid="native-empty">—</span>
                                            @elseif ($nativeCount === 1)
                                                @php
                                                    $singleKey = array_key_first($nativeMatches);
                                                    $singleSection = $nativeMatches[$singleKey];
                                                @endphp
                                                <x-atoms.tooltip position="top">
                                                    <x-slot name="trigger">
                                                        <a href="{{ \App\Gpo\Support\NativeSectionResolver::buildUrl($singleKey, $gpo['name']) }}"
                                                            class="badge badge-success badge-sm gap-1 hover:badge-outline cursor-pointer"
                                                            data-testid="native-chip-single">
                                                            <i class="fa-solid {{ $singleSection['icon'] }} text-xs"></i>
                                                            1 section
                                                        </a>
                                                    </x-slot>
                                                    <span class="text-xs">{{ $singleSection['label'] }} — cliquer pour éditer nativement</span>
                                                </x-atoms.tooltip>
                                            @else
                                                <details class="dropdown dropdown-end" data-testid="native-chip-multi">
                                                    <summary class="badge badge-success badge-sm gap-1 cursor-pointer list-none hover:badge-outline">
                                                        <i class="fa-solid fa-circle-check text-xs"></i>
                                                        {{ $nativeCount }} sections
                                                    </summary>
                                                    <ul class="dropdown-content z-10 menu p-2 shadow bg-base-100 rounded-box w-64 border border-base-200 mt-1">
                                                        <li class="menu-title text-xs opacity-60">Sections gérables nativement</li>
                                                        @foreach ($nativeMatches as $key => $section)
                                                            <li>
                                                                <a href="{{ \App\Gpo\Support\NativeSectionResolver::buildUrl($key, $gpo['name']) }}"
                                                                    class="text-sm"
                                                                    data-testid="native-multi-link-{{ $key }}">
                                                                    <i class="fa-solid {{ $section['icon'] }} w-4"></i>
                                                                    {{ $section['label'] }}
                                                                </a>
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            @endif
                                        </td>
                                        {{-- Story 27.14 — colonne Actions (publication SYSVOL) retirée. --}}
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="text-center py-12 text-base-content/60">
                                            <div class="flex flex-col items-center gap-3">
                                                <svg class="w-12 h-12 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                        d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                                <span>{{ $emptyStateMessage }}</span>
                                                @if ($hasFilters || $advancedFiltersCount > 0)
                                                    <div class="flex gap-2">
                                                        @if ($hasFilters)
                                                            <button type="button" class="btn btn-outline btn-sm"
                                                                wire:click="clearFilters">
                                                                Effacer les filtres simples
                                                            </button>
                                                        @endif
                                                        @if ($advancedFiltersCount > 0)
                                                            <button type="button" class="btn btn-outline btn-sm"
                                                                wire:click="resetAdvancedFilters">
                                                                Réinitialiser les filtres avancés
                                                            </button>
                                                        @endif
                                                    </div>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($pagination && $pagination['total'] > 0)
                        <div class="px-4 py-3 border-t border-base-300">
                            <x-molecules.pagination
                                :currentPage="$pagination['current_page']"
                                :lastPage="$pagination['last_page']"
                                :total="$pagination['total']"
                                :from="$pagination['from']"
                                :to="$pagination['to']"
                                :perPage="$perPage"
                                :allowedPerPage="$allowedPerPage"
                                onPageChange="goToPage"
                                perPageModel="perPage"
                                itemLabel="GPO"
                                itemLabelPlural="GPOs" />
                        </div>
                    @endif
                </div>
            @endif

        </div>
    </div>

    {{-- Story 27.14 — la modale « Publier l'étage 2 (SYSVOL) » a été retirée
         avec l'extinction du canal de config legacy. --}}
</x-organisms.page>
