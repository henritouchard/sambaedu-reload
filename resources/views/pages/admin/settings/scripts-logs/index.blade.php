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

<x-organisms.page title="Logs d'exécution scripts"
    icon="fa-solid fa-clipboard-list"
    description="Logs centralisés d'exécution des scripts user/system du parc.">

    <x-slot:actions>
        <div class="flex flex-wrap gap-2 items-center">
            <button type="button" class="btn btn-outline btn-sm" wire:click="clearFilters"
                data-testid="clear-filters">
                <i class="fa-solid fa-eraser"></i>
                Réinitialiser filtres
            </button>
            <button type="button"
                class="btn btn-sm {{ $filterFailuresOnly ? 'btn-error' : 'btn-outline btn-error' }}"
                wire:click="toggleFailuresOnly"
                data-testid="toggle-failures-only">
                <i class="fa-solid fa-triangle-exclamation"></i>
                {{ $filterFailuresOnly ? 'Tous les logs' : 'Voir uniquement les échecs' }}
            </button>
        </div>
    </x-slot:actions>

    <div class="space-y-6">

        {{-- ================ Bandeau d'indicateurs ================ --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4" data-testid="dashboard-banner">
            {{-- Jauge taux d'échec 24h --}}
            <div class="card bg-base-100 shadow-sm border border-base-200">
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
            <div class="card bg-base-100 shadow-sm border border-base-200">
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
            <div class="card bg-base-100 shadow-sm border border-base-200">
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
        <div class="card bg-base-100 shadow-sm border border-base-200" data-testid="filters-panel">
            <div class="card-body py-4">
                <h3 class="card-title text-sm flex items-center gap-2">
                    <i class="fa-solid fa-filter"></i>
                    Filtres
                </h3>
                <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-4 gap-3">
                    <label class="form-control">
                        <span class="label-text text-xs">Poste (UUID)</span>
                        <input type="text" class="input input-bordered input-sm font-mono"
                            placeholder="UUID complet ou partiel"
                            wire:model.live.debounce.400ms="filterWorkstationUuid"
                            data-testid="filter-workstation-uuid">
                    </label>

                    <label class="form-control">
                        <span class="label-text text-xs">Script ID</span>
                        <input type="number" class="input input-bordered input-sm"
                            placeholder="(any)"
                            wire:model.live.debounce.400ms="filterScriptId"
                            data-testid="filter-script-id">
                    </label>

                    <label class="form-control">
                        <span class="label-text text-xs">Actions</span>
                        <select class="select select-bordered select-sm" multiple
                            wire:model.live="filterActions"
                            data-testid="filter-actions">
                            @foreach ($availableActions as $action)
                                <option value="{{ $action }}">{{ $action }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="form-control">
                        <span class="label-text text-xs">OS</span>
                        <select class="select select-bordered select-sm"
                            wire:model.live="filterOs"
                            data-testid="filter-os">
                            <option value="">(any)</option>
                            @foreach ($availableOs as $os)
                                <option value="{{ $os }}">{{ $os }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="form-control">
                        <span class="label-text text-xs">Statut</span>
                        <select class="select select-bordered select-sm"
                            wire:model.live="filterStatus"
                            data-testid="filter-status">
                            <option value="">(any)</option>
                            @foreach ($availableStatuses as $status)
                                <option value="{{ $status }}">{{ $status }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label class="form-control">
                        <span class="label-text text-xs">Date début</span>
                        <input type="date" class="input input-bordered input-sm"
                            wire:model.live="filterDateFrom"
                            data-testid="filter-date-from">
                    </label>

                    <label class="form-control">
                        <span class="label-text text-xs">Date fin</span>
                        <input type="date" class="input input-bordered input-sm"
                            wire:model.live="filterDateTo"
                            data-testid="filter-date-to">
                    </label>
                </div>
            </div>
        </div>

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
                                <tr class="hover:bg-sky-50 cursor-pointer"
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
</x-organisms.page>
