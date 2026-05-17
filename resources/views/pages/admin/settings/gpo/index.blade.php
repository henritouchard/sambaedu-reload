<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Services\GpoService;
use App\Gpo\Dto\GpoSummary;
use Illuminate\Support\Collection;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Page Livewire SFC — Listing des GPOs Active Directory.
 *
 * Story 16.2 + Story 16.9 — Listing GPO sous `/admin/settings/gpo`.
 * Convention maison filesystem-based router.
 * Consomme GpoService::list() (posé par Story 16.1).
 * Périmètre : lecture seule. Les mutations passent par le shim legacy (bouton dédié page détail).
 */
new #[Title('Gestion des GPOs - SE4FS')] class extends Component {
    use WithToasts;

    // --- Propriétés réactives synchronisées avec l'URL ---
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

    // --- Données ---
    /** @var array<array{name:string,displayName:string,versionNumber:?int,dn:?string,path:?string}> */
    public array $gpos = [];
    public int $totalGpos = 0;
    public int $totalFiltered = 0;
    public bool $hasError = false;
    public ?array $pagination = null;
    public array $allowedPerPage = [10, 20, 50, 100];

    private GpoService $gpoService;

    /**
     * Livewire invoque boot() avant mount() à chaque cycle (initial + interactions),
     * c'est l'endroit canonique du projet pour injecter les services (cf. pattern
     * pages/users/[login]/index.blade.php). Ne pas dupliquer dans mount().
     */
    public function boot(GpoService $service): void
    {
        $this->gpoService = $service;
    }

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->loadGpos();
    }

    public function loadGpos(): void
    {
        $this->hasError = false;
        try {
            $all = $this->gpoService->list();
            $this->totalGpos = $all->count();
            $this->gpos = $all->map(fn(GpoSummary $g) => $g->toArray())->all();
        } catch (\Throwable $e) {
            $this->hasError = true;
            $this->gpos = [];
            $this->totalGpos = 0;
            $this->toast('error', 'Erreur', 'Impossible de charger les GPOs : ' . $e->getMessage());
        }
        $this->applyFiltersAndPagination();
    }

    public function refresh(): void
    {
        $this->loadGpos();
        if (!$this->hasError) {
            $this->toast('success', 'Liste rafraîchie', $this->totalGpos . ' GPO(s) chargée(s)');
        }
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

    private function applyFiltersAndPagination(): void
    {
        $collection = collect($this->gpos);

        // Filtre recherche (case-insensitive, substring)
        if (!empty($this->search)) {
            $search = $this->search;
            $collection = $collection->filter(
                fn($g) => mb_stripos($g['displayName'], $search) !== false
            );
        }

        // Filtre statut
        if ($this->statusFilter === 'active') {
            $collection = $collection->filter(fn($g) => isset($g['versionNumber']) && $g['versionNumber'] > 0);
        } elseif ($this->statusFilter === 'inactive') {
            $collection = $collection->filter(fn($g) => !isset($g['versionNumber']) || $g['versionNumber'] === 0 || $g['versionNumber'] === null);
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

    /** @return list<array> */
    public function paginatedGpos(): array
    {
        return $this->pagination['items'] ?? [];
    }

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
    $paginatedItems = $this->paginatedGpos();
    $emptyStateMessage = $hasFilters
        ? 'Aucune GPO ne correspond aux critères de recherche.'
        : 'Aucune GPO trouvée dans le domaine AD.';
@endphp

<x-organisms.page title="Gestion des GPOs" :scrollable="false"
    description="Consultez et gérez les Group Policy Objects (GPO) Active Directory de l'établissement.">

    <x-slot:actions>
        <div class="flex gap-2">
            {{-- Story 16.5 — AC7.2 : encart "Créer une GPO (ancienne UI)".
                 Création GPO native en pause (Story 16-4) — on expose le shim
                 legacy en bouton secondaire visible du header listing. --}}
            @can('server.admin')
                <a href="{{ legacy_url('/gpo/gpo-maj.php') }}" target="_blank" rel="noopener noreferrer"
                    class="btn btn-outline btn-sm" data-testid="create-gpo-legacy-cta">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i>
                    Créer une GPO (ancienne UI)
                </a>
            @endcan
            <button type="button" class="btn btn-outline btn-sm" wire:click="refresh" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="refresh">
                    <i class="fa-solid fa-arrows-rotate"></i>
                    Rafraîchir
                </span>
                <span wire:loading wire:target="refresh">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    Chargement…
                </span>
            </button>
        </div>
    </x-slot:actions>

    <div id="gpo-listing" class="h-full">
        <div class="flex flex-col h-full gap-4">

            {{-- Barre de recherche et filtres --}}
            <div class="space-y-3">
                <div class="flex flex-wrap gap-4 items-end">
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

            {{-- Tableau ou état vide --}}
            @if (count($paginatedItems) > 0)
                {{--
                    Story 16.3a — AC3.1/AC3.3 : Colonne "Édition native" ajoutée avant "Actions".
                    Résolution NativeSectionResolver sur $paginatedItems (déjà paginé en mémoire) —
                    aucun appel samba-tool additionnel, O(N) sur ≤ perPage GPOs.
                --}}
                <x-organisms.data-table
                    colgroup="<colgroup><col style='width:24%'><col style='width:10%'><col style='width:8%'><col style='width:24%'><col style='width:18%'><col style='width:16%'></colgroup>">
                    <x-slot:header>
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
                        {{-- Story 16.3a — Colonne "Édition native" (AC3.1) --}}
                        <th>
                            <span class="flex items-center gap-1">
                                <i class="fa-solid fa-circle-check text-success text-xs"></i>
                                Édition native
                            </span>
                        </th>
                        <th>Actions</th>
                    </x-slot:header>

                    @foreach ($paginatedItems as $gpo)
                        @php
                            $version = $gpo['versionNumber'] ?? 0;
                            $isActive = $version > 0;
                            // Strip accolades du GUID Microsoft `{...}` : Laravel/Symfony UrlGenerator
                            // ré-interprète les `{` `}` de la valeur substituée comme placeholders
                            // et lève UrlGenerationException. La regex de la route accepte les 2 formes.
                            $detailUrl = route('admin.gpo.show', ['guid' => trim((string) $gpo['name'], '{}')]);
                            // Story 16.3a — AC3.1/AC3.3 : résolution heuristique sur la collection
                            // paginée (déjà en mémoire) — aucun appel I/O supplémentaire.
                            $nativeMatches = \App\Gpo\Support\NativeSectionResolver::resolve($gpo['displayName'] ?? '');
                            $nativeCount = count($nativeMatches);
                        @endphp
                        <tr class="hover:bg-sky-50">
                            <td>
                                <div class="flex flex-col gap-0.5">
                                    <a href="{{ $detailUrl }}"
                                        class="font-semibold link link-primary hover:underline">
                                        {{ $gpo['displayName'] }}
                                    </a>
                                    <span class="badge badge-xs {{ $isActive ? 'badge-success' : 'badge-ghost' }}">
                                        {{ $isActive ? 'Active' : 'Inactive' }}
                                    </span>
                                </div>
                            </td>
                            <td>
                                <a href="{{ $detailUrl }}" class="font-mono text-sm hover:underline">
                                    {{ $this->formatVersion($gpo['versionNumber'] ?? null) }}
                                </a>
                            </td>
                            <td>
                                <x-atoms.tooltip position="top">
                                    <x-slot name="trigger">
                                        <a href="{{ $detailUrl }}" class="font-mono text-xs text-base-content/60 hover:underline">
                                            {{ substr($gpo['name'] ?? '', 0, 10) }}…
                                        </a>
                                    </x-slot>
                                    <span class="font-mono text-xs">{{ $gpo['name'] }}</span>
                                </x-atoms.tooltip>
                            </td>
                            <td>
                                @if (isset($gpo['path']) && $gpo['path'])
                                    <x-atoms.tooltip position="top">
                                        <x-slot name="trigger">
                                            <a href="{{ $detailUrl }}" class="font-mono text-xs text-base-content/60 hover:underline">
                                                {{ Str::limit(basename($gpo['path'] ?? ''), 30) }}
                                            </a>
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
                                    {{-- Pas de match → cellule vide discrète --}}
                                    <span class="text-base-content/30" data-testid="native-empty">—</span>
                                @elseif ($nativeCount === 1)
                                    {{-- Match unique → lien direct (AC3.1) --}}
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
                                    {{-- Multi-match → dropdown DaisyUI (AC3.1 / D3) --}}
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

                            {{-- Colonne "Actions" --}}
                            <td class="actions-cell">
                                <div class="flex gap-2 items-center">
                                    <a href="{{ $detailUrl }}"
                                        class="btn btn-xs btn-outline btn-primary">
                                        <i class="fa-solid fa-eye text-xs"></i>
                                        Détail
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-organisms.data-table>

                {{-- Pagination --}}
                @if ($pagination)
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
                @endif

            @elseif (!$hasError)
                {{-- État vide --}}
                <div class="card flex-1 flex flex-col justify-center items-center mt-8">
                    <div class="card-body flex-col justify-center items-center flex-0 py-16">
                        <svg class="w-16 h-16 mx-auto mb-4 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                            </path>
                        </svg>
                        <h3 class="text-lg font-semibold mb-2">Aucune GPO trouvée</h3>
                        <p class="text-base-content/60 text-base mb-6">{{ $emptyStateMessage }}</p>
                        <div class="text-center flex flex-wrap gap-2 justify-center">
                            @if ($hasFilters)
                                <button type="button" class="btn btn-outline btn-sm"
                                    wire:click="clearFilters">
                                    Effacer les filtres
                                </button>
                            @endif
                            <a href="{{ legacy_url('/gpo/gpo-maj.php') }}" target="_blank" rel="noopener noreferrer"
                                class="btn btn-primary btn-sm">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                Créer une GPO dans l'ancienne UI
                            </a>
                        </div>
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-organisms.page>
