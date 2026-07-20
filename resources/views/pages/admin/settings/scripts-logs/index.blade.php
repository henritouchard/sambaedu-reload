<?php

use App\Components\Traits\WithToasts;
use App\ScriptsOs\Enums\ScriptExecutionAction;
use App\ScriptsOs\Enums\ScriptExecutionOs;
use App\ScriptsOs\Enums\ScriptExecutionStatus;
use App\ScriptsOs\Models\ScriptExecutionLog;
use App\ScriptsOs\Services\ScriptExecutionLogStatsService;
use App\ScriptsOs\Support\Humanize;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Story 16.12 — AC4.1 / AC4.2 / AC4.3 / D5 / D7.
 *
 * Page Livewire SFC index — `/admin/settings/scripts-logs/`.
 *
 *  - Filtres `#[Url(history: true)]` (Livewire 3) — deeplinking + bouton
 *    retour navigateur fonctionnel + URL partageable entre admins.
 *  - Pagination 50/page.
 *  - Bandeau d'indicateurs en tête (jauge taux échec 24h + top 5 postes +
 *    top 5 scripts + bouton "Voir uniquement les échecs").
 *  - Permission `server.admin` checké en mount() (en plus du middleware route).
 */
new #[Title('Logs exécution scripts - SE4FS')] class extends Component {
    use WithToasts;
    use WithPagination;

    #[Url(history: true)]
    public string $filterWorkstationUuid = '';

    #[Url(history: true)]
    public ?int $filterScriptId = null;

    /** @var array<int,string> */
    #[Url(history: true)]
    public array $filterActions = [];

    #[Url(history: true)]
    public string $filterOs = '';

    #[Url(history: true)]
    public string $filterStatus = '';

    #[Url(history: true)]
    public string $filterDateFrom = '';

    #[Url(history: true)]
    public string $filterDateTo = '';

    #[Url(history: true)]
    public bool $filterFailuresOnly = false;

    #[Url(history: true)]
    public string $sortBy = 'started_at';

    #[Url(history: true)]
    public string $sortDir = 'desc';

    public function mount(): void
    {
        abort_unless(
            auth()->check() && Gate::allows('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        if ($this->filterDateFrom === '') {
            $this->filterDateFrom = Carbon::now()->subDays(7)->toDateString();
        }
        if ($this->filterDateTo === '') {
            $this->filterDateTo = Carbon::now()->toDateString();
        }
    }

    public function updating(string $name): void
    {
        // Reset pagination dès qu'un filtre change.
        if (str_starts_with($name, 'filter') || $name === 'sortBy' || $name === 'sortDir') {
            $this->resetPage();
        }
    }

    public function sortByColumn(string $column): void
    {
        $allowed = ['started_at', 'workstation_uuid', 'action', 'os', 'status', 'exit_code', 'duration_ms'];
        if (! in_array($column, $allowed, true)) {
            return;
        }
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'desc';
        }
    }

    public function clearFilters(): void
    {
        $this->filterWorkstationUuid = '';
        $this->filterScriptId = null;
        $this->filterActions = [];
        $this->filterOs = '';
        $this->filterStatus = '';
        $this->filterFailuresOnly = false;
        $this->filterDateFrom = Carbon::now()->subDays(7)->toDateString();
        $this->filterDateTo = Carbon::now()->toDateString();
        $this->sortBy = 'started_at';
        $this->sortDir = 'desc';
        $this->resetPage();
    }

    public function toggleFailuresOnly(): void
    {
        $this->filterFailuresOnly = ! $this->filterFailuresOnly;
        $this->resetPage();
    }

    public function selectWorkstationFilter(string $uuid): void
    {
        $this->filterWorkstationUuid = $uuid;
        $this->resetPage();
    }

    public function selectScriptFilter(int $scriptId): void
    {
        $this->filterScriptId = $scriptId;
        $this->resetPage();
    }

    public function with(ScriptExecutionLogStatsService $stats): array
    {
        $allowedSort = ['started_at', 'workstation_uuid', 'action', 'os', 'status', 'exit_code', 'duration_ms'];
        $sortBy = in_array($this->sortBy, $allowedSort, true) ? $this->sortBy : 'started_at';
        $sortDir = $this->sortDir === 'asc' ? 'asc' : 'desc';

        // Bornes date — parse défensive (en cas de query-string malformée).
        try {
            $from = Carbon::parse($this->filterDateFrom)->startOfDay();
        } catch (\Throwable) {
            $from = Carbon::now()->subDays(7)->startOfDay();
        }
        try {
            $to = Carbon::parse($this->filterDateTo)->endOfDay();
        } catch (\Throwable) {
            $to = Carbon::now()->endOfDay();
        }

        $query = ScriptExecutionLog::query()
            ->when($this->filterWorkstationUuid !== '', fn ($q) => $q->forWorkstation($this->filterWorkstationUuid))
            ->when($this->filterScriptId !== null, fn ($q) => $q->forScript((int) $this->filterScriptId))
            ->when($this->filterActions !== [], fn ($q) => $q->forAction($this->filterActions))
            ->when($this->filterOs !== '', fn ($q) => $q->where('os', $this->filterOs))
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterFailuresOnly, fn ($q) => $q->failed())
            ->whereBetween('started_at', [$from, $to])
            ->orderBy($sortBy, $sortDir);

        return [
            'logs' => $query->paginate(50),
            'stats' => $stats->dashboard24h(),
            'topFailingWorkstations' => $stats->topFailingWorkstations(5),
            'topFailingScripts' => $stats->topFailingScripts(5),
            'availableActions' => ScriptExecutionAction::values(),
            'availableOs' => ScriptExecutionOs::values(),
            'availableStatuses' => ScriptExecutionStatus::values(),
        ];
    }

};
?>

{{-- Corps seul (sans chrome de page) : embarqué comme onglet « Logs scripts »
     de /admin/settings/migration. --}}
{{-- Réinitialisation et raccourci « échecs seuls » ont rejoint la barre de filtre
     (convention projet : les contrôles de filtrage vivent dans la barre, le reset
     aligné à droite) — cette rangée d'actions n'a plus lieu d'être. --}}
<div class="flex flex-col gap-6">
    <div class="space-y-6">

        {{-- ================ Bandeau d'indicateurs ================ --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4" data-testid="dashboard-banner">
            {{-- Jauge taux d'échec 24h --}}
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body py-4">
                    <h3 class="card-title text-sm flex items-center gap-2">
                        <i class="fa-solid fa-gauge text-primary"></i>
                        Taux d'échec 24h
                    </h3>
                    @php
                        $rate = (float) ($stats['rate'] ?? 0.0);
                        $rateBadge = $rate < 0.05 ? 'badge-success' : ($rate < 0.15 ? 'badge-warning' : 'badge-error');
                    @endphp
                    <div class="flex items-baseline gap-3">
                        <span class="text-3xl font-mono font-bold" data-testid="failure-rate">
                            {{ number_format($rate * 100, 1) }}%
                        </span>
                        <span class="badge {{ $rateBadge }} badge-lg">
                            {{ $stats['failures'] }} / {{ $stats['total'] }}
                        </span>
                    </div>
                    <p class="text-xs text-base-content/60">
                        Sur 24h glissantes. Rafraichi toutes les 60s.
                    </p>
                </div>
            </div>

            {{-- Top 5 postes en échec --}}
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body py-4">
                    <h3 class="card-title text-sm flex items-center gap-2">
                        <i class="fa-solid fa-desktop text-secondary"></i>
                        Top 5 postes en échec (24h)
                    </h3>
                    @if ($topFailingWorkstations->isEmpty())
                        <p class="text-sm text-base-content/60">Aucun échec sur 24h.</p>
                    @else
                        <ul class="text-sm space-y-1" data-testid="top-failing-workstations">
                            @foreach ($topFailingWorkstations as $row)
                                <li class="flex items-center justify-between gap-2">
                                    <button type="button"
                                        wire:click="selectWorkstationFilter('{{ $row->workstation_uuid }}')"
                                        class="font-mono text-xs link link-hover truncate">
                                        {{ \Illuminate\Support\Str::limit($row->workstation_uuid, 24, '…') }}
                                    </button>
                                    <span class="badge badge-error badge-sm">{{ $row->failures_count }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            {{-- Top 5 scripts en échec --}}
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body py-4">
                    <h3 class="card-title text-sm flex items-center gap-2">
                        <i class="fa-solid fa-code text-accent"></i>
                        Top 5 scripts en échec (24h)
                    </h3>
                    @if ($topFailingScripts->isEmpty())
                        <p class="text-sm text-base-content/60">Aucun script managé en échec.</p>
                    @else
                        <ul class="text-sm space-y-1" data-testid="top-failing-scripts">
                            @foreach ($topFailingScripts as $row)
                                <li class="flex items-center justify-between gap-2">
                                    <button type="button"
                                        wire:click="selectScriptFilter({{ (int) $row->script_id }})"
                                        class="font-mono text-xs link link-hover">
                                        script #{{ $row->script_id }}
                                    </button>
                                    <span class="badge badge-error badge-sm">{{ $row->failures_count }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>
        {{-- ================ Filtres ================ --}}
        {{-- 7 filtres : au-delà de ce qu'une rangée peut porter, la barre laisse
             les contrôles passer à la ligne (flex-wrap) plutôt que de les enfermer
             dans une modale — ces filtres se combinent, on veut les voir ensemble. --}}
        <x-molecules.filter-bar data-testid="filters-panel" reset="clearFilters">
            <div class="flex-1 min-w-[240px]">
                <x-atoms.search-input model="filterWorkstationUuid" placeholder="Poste (UUID complet ou partiel)"
                    testid="filter-workstation-uuid" class="font-mono" />
            </div>

            <input type="number" class="input input-bordered input-sm w-32" placeholder="Script ID"
                wire:model.live.debounce.400ms="filterScriptId" aria-label="Script ID"
                data-testid="filter-script-id">

            {{-- Sélection multiple : reste un <select multiple>, aucun dropdown simple
                 ne rend ce cas. --}}
            <select class="select select-bordered select-sm w-40" multiple wire:model.live="filterActions"
                aria-label="Actions" data-testid="filter-actions">
                @foreach ($availableActions as $action)
                    <option value="{{ $action }}">{{ $action }}</option>
                @endforeach
            </select>

            <x-molecules.filter-select model="filterOs" :options="$availableOs" placeholder="Tous les OS"
                width="w-36" data-testid="filter-os" />

            <x-molecules.filter-select model="filterStatus" :options="$availableStatuses"
                placeholder="Tous les statuts" width="w-40" data-testid="filter-status" />

            <div class="flex items-center gap-2">
                <span class="text-xs text-base-content/60 shrink-0">Du</span>
                <input type="date" class="input input-bordered input-sm w-36" wire:model.live="filterDateFrom"
                    aria-label="Date de début" data-testid="filter-date-from">
                <span class="text-xs text-base-content/60 shrink-0">au</span>
                <input type="date" class="input input-bordered input-sm w-36" wire:model.live="filterDateTo"
                    aria-label="Date de fin" data-testid="filter-date-to">
            </div>

            <x-slot:actions>
                {{-- Raccourci « échecs seuls » : c'est un filtre, sa place est dans la
                     barre et non dans la rangée d'actions de la page. --}}
                <button type="button"
                    class="btn btn-sm {{ $filterFailuresOnly ? 'btn-error' : 'btn-outline btn-error' }}"
                    wire:click="toggleFailuresOnly" aria-pressed="{{ $filterFailuresOnly ? 'true' : 'false' }}"
                    data-testid="toggle-failures-only">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    {{ $filterFailuresOnly ? 'Tous les logs' : 'Échecs seuls' }}
                </button>
            </x-slot:actions>
        </x-molecules.filter-bar>

        {{-- ================ Tableau ================ --}}
        <div class="card bg-base-100 shadow-sm overflow-hidden">
            <div class="px-4 py-3 border-b border-base-300 text-sm text-base-content/70">
                Affichage des logs d'exécution ({{ $logs->total() }} résultat{{ $logs->total() > 1 ? 's' : '' }} —
                page {{ $logs->currentPage() }}/{{ max(1, $logs->lastPage()) }})
            </div>

            <div class="overflow-auto max-h-[60vh]">
                <table class="table table-zebra" data-testid="logs-table">
                        <thead class="sticky top-0 z-10 bg-base-100 shadow-sm">
                            <tr>
                                <th><button type="button" class="link link-hover"
                                    wire:click="sortByColumn('started_at')">
                                    Started at
                                    @if ($sortBy === 'started_at')
                                        <i class="fa-solid fa-arrow-{{ $sortDir === 'asc' ? 'up' : 'down' }} text-xs"></i>
                                    @endif
                                </button></th>
                                <th>Poste</th>
                                <th>Script</th>
                                <th><button type="button" class="link link-hover"
                                    wire:click="sortByColumn('action')">Action</button></th>
                                <th><button type="button" class="link link-hover"
                                    wire:click="sortByColumn('os')">OS</button></th>
                                <th><button type="button" class="link link-hover"
                                    wire:click="sortByColumn('status')">Statut</button></th>
                                <th class="text-right"><button type="button" class="link link-hover"
                                    wire:click="sortByColumn('exit_code')">Exit</button></th>
                                <th class="text-right"><button type="button" class="link link-hover"
                                    wire:click="sortByColumn('duration_ms')">Durée</button></th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($logs as $log)
                                @php
                                    $statusBadge = match ($log->status?->value) {
                                        'success' => 'badge-success',
                                        'failure' => 'badge-error',
                                        'timeout' => 'badge-warning',
                                        'skipped' => 'badge-neutral',
                                        default => 'badge-ghost',
                                    };
                                @endphp
                                <tr class="cursor-pointer"
                                    onclick="window.location.href='{{ route('admin.scripts-logs.show', ['id' => $log->id]) }}'"
                                    data-testid="log-row">
                                    <td class="font-mono text-xs whitespace-nowrap"
                                        title="{{ $log->started_at?->toIso8601String() }}">
                                        {{ $log->started_at?->diffForHumans() }}
                                    </td>
                                    <td class="font-mono text-xs">
                                        {{ \Illuminate\Support\Str::limit($log->workstation_uuid, 16, '…') }}
                                    </td>
                                    <td class="font-mono text-xs">
                                        @if ($log->script_id !== null)
                                            #{{ $log->script_id }}
                                        @else
                                            <span class="text-base-content/50">{{ $log->script_source?->value }}</span>
                                        @endif
                                    </td>
                                    <td><span class="badge badge-ghost badge-sm">{{ $log->action?->value }}</span></td>
                                    <td><span class="badge badge-outline badge-sm">{{ $log->os?->value }}</span></td>
                                    <td><span class="badge {{ $statusBadge }} badge-sm">{{ $log->status?->value }}</span></td>
                                    <td class="text-right font-mono text-xs">
                                        {{ $log->exit_code ?? '—' }}
                                    </td>
                                    <td class="text-right font-mono text-xs">
                                        {{ Humanize::duration((int) $log->duration_ms) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center py-8 text-base-content/60"
                                        data-testid="empty-state">
                                        Aucun log d'exécution pour ces critères.
                                    </td>
                                </tr>
                            @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-base-300">
                {{ $logs->links() }}
            </div>
        </div>

    </div>
</div>
