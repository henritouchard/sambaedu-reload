<?php

declare(strict_types=1);

use App\Models\Application;
use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Components\Traits\WithToasts;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Log;

new #[Title('Rapports WPKG - Déploiement Windows')] class extends Component {
    use WithToasts;
    use WithPagination;

    // ── Filtres ────────────────────────────────────────────────────────────
    #[Url]
    public string $search = '';

    #[Url]
    public string $packageSearch = '';

    #[Url]
    public string $statusFilter = ''; // '' | 'installed' | 'error' | 'not-installed'

    // ── Pagination ─────────────────────────────────────────────────────────
    #[Url]
    public int $perPage = 20;

    // ── Onglet ─────────────────────────────────────────────────────────────
    #[Url]
    public string $tab = 'machines'; // 'machines' | 'packages'

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedPackageSearch(): void { $this->resetPage(); }
    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->search        = '';
        $this->packageSearch = '';
        $this->statusFilter  = '';
        $this->resetPage();
    }

    // ── Données : vue par machine ─────────────────────────────────────────
    public function getWorkstationsProperty()
    {
        $query = Workstation::query()
            ->withCount([
                'applicationStatuses as total_apps',
                'applicationStatuses as installed_apps' => fn($q) => $q->where('status', 'installed'),
                'applicationStatuses as error_apps'     => fn($q) => $q->where('status', 'error'),
                'applicationStatuses as missing_apps'   => fn($q) => $q->where('status', 'not-installed'),
            ])
            ->whereNotNull('last_report_at');

        if (!empty($this->search)) {
            $query->where('name', 'ILIKE', '%' . $this->search . '%');
        }

        if (!empty($this->packageSearch)) {
            $query->whereHas('applicationStatuses.application', function ($q) {
                $q->where('app_id', 'ILIKE', '%' . $this->packageSearch . '%')
                  ->orWhere('name', 'ILIKE', '%' . $this->packageSearch . '%');
            });
        }

        if (!empty($this->statusFilter)) {
            $query->whereHas('applicationStatuses', function ($q) {
                $q->where('status', $this->statusFilter);
            });
        }

        return $query->orderByDesc('last_report_at')->paginate($this->perPage);
    }

    // ── Données : vue par package ──────────────────────────────────────────
    public function getPackageSummaryProperty()
    {
        $query = Application::query()
            ->withCount([
                'workstationStatuses as total_count',
                'workstationStatuses as installed_count' => fn($q) => $q->where('status', 'installed'),
                'workstationStatuses as error_count' => fn($q) => $q->where('status', 'error'),
            ])
            // Filtrer sur les applications qui ont au moins 1 statut (sous-requête compatible PostgreSQL)
            ->whereHas('workstationStatuses');

        if (!empty($this->packageSearch)) {
            $query->where(function ($q) {
                $q->where('app_id', 'ILIKE', '%' . $this->packageSearch . '%')
                  ->orWhere('name', 'ILIKE', '%' . $this->packageSearch . '%');
            });
        }

        return $query->orderByRaw('error_count DESC NULLS LAST')->orderBy('name')->paginate($this->perPage);
    }

    /**
     * Calcule le statut global d'un poste (pastille couleur) à partir des withCount.
     *   - 'error' si au moins un statut 'error'
     *   - 'partial' si au moins un 'not-installed'
     *   - 'ok' si tout est installé
     *   - 'empty' si aucun statut
     *
     * Utilise les attributs withCount (total_apps, error_apps, missing_apps)
     * pour éviter le chargement de la relation complète (anti N+1).
     */
    public function globalStatus(Workstation $ws): string
    {
        $total   = (int) ($ws->total_apps ?? 0);
        $errors  = (int) ($ws->error_apps ?? 0);
        $missing = (int) ($ws->missing_apps ?? 0);

        if ($total === 0) {
            return 'empty';
        }
        if ($errors > 0) {
            return 'error';
        }
        if ($missing > 0) {
            return 'partial';
        }
        return 'ok';
    }
};
?>

<x-organisms.page title="Rapports WPKG" description="Logs d'installation et rapports par poste">

    {{-- ── Filtres ─────────────────────────────────────────────────────────── --}}
    <div class="card bg-base-100 shadow-sm border border-base-200 mb-6">
        <div class="card-body py-4">
            <div class="flex flex-wrap gap-3 items-end">
                <div class="flex-1 min-w-48">
                    <label class="label label-text text-sm">Recherche poste</label>
                    <input
                        type="text"
                        wire:model.live.debounce.500ms="search"
                        placeholder="PC-SALLE-01..."
                        class="input input-bordered input-sm w-full"
                    />
                </div>

                <div class="flex-1 min-w-48">
                    <label class="label label-text text-sm">Recherche package</label>
                    <input
                        type="text"
                        wire:model.live.debounce.500ms="packageSearch"
                        placeholder="firefox, libreoffice..."
                        class="input input-bordered input-sm w-full"
                    />
                </div>

                <div class="min-w-40">
                    <label class="label label-text text-sm">Statut</label>
                    <select wire:model.live="statusFilter" class="select select-bordered select-sm w-full">
                        <option value="">Tous</option>
                        <option value="installed">✓ Installé</option>
                        <option value="not-installed">✗ Non installé</option>
                        <option value="error">⚠ Erreur</option>
                    </select>
                </div>

                <div>
                    <button wire:click="resetFilters" class="btn btn-ghost btn-sm mt-4">
                        <i class="fa-solid fa-rotate-left"></i>
                        Réinitialiser
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Onglets ─────────────────────────────────────────────────────────── --}}
    <div class="tabs tabs-bordered mb-4">
        <button
            class="tab {{ $tab === 'machines' ? 'tab-active' : '' }}"
            wire:click="setTab('machines')"
        >
            <i class="fa-solid fa-desktop mr-2"></i>Par poste
        </button>
        <button
            class="tab {{ $tab === 'packages' ? 'tab-active' : '' }}"
            wire:click="setTab('packages')"
        >
            <i class="fa-solid fa-boxes-stacked mr-2"></i>Par package
        </button>
    </div>

    {{-- ── Vue par machine ─────────────────────────────────────────────────── --}}
    @if ($tab === 'machines')
        @php $workstations = $this->workstations; @endphp

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr class="bg-base-200">
                            <th>Poste</th>
                            <th>OS</th>
                            <th>Dernier rapport</th>
                            <th class="text-center">Packages</th>
                            <th class="text-center">Statut global</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($workstations as $ws)
                            @php $gStatus = $this->globalStatus($ws); @endphp
                            <tr class="hover">
                                <td class="font-mono font-semibold">{{ $ws->name }}</td>
                                <td class="text-sm text-base-content/70">{{ $ws->os ?? '—' }}</td>
                                <td class="text-sm">
                                    @if ($ws->last_report_at)
                                        <span title="{{ $ws->last_report_at }}">
                                            {{ $ws->last_report_at->diffForHumans() }}
                                        </span>
                                    @else
                                        <span class="text-base-content/40">Jamais</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-ghost">{{ $ws->total_apps ?? 0 }}</span>
                                </td>
                                <td class="text-center">
                                    @switch($gStatus)
                                        @case('ok')
                                            <span class="badge badge-success badge-sm">OK</span>
                                            @break
                                        @case('error')
                                            <span class="badge badge-error badge-sm">Erreur</span>
                                            @break
                                        @case('partial')
                                            <span class="badge badge-warning badge-sm">Partiel</span>
                                            @break
                                        @default
                                            <span class="badge badge-ghost badge-sm">—</span>
                                    @endswitch
                                </td>
                                <td>
                                    <a
                                        href="{{ route('app.windows-deploy.reports.show', ['workstation' => $ws->id]) }}"
                                        class="btn btn-xs btn-outline"
                                    >
                                        Détail
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-base-content/50 py-8">
                                    Aucun poste avec rapport WPKG.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($workstations->hasPages())
                <div class="card-footer px-4 py-3">
                    {{ $workstations->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- ── Vue par package ─────────────────────────────────────────────────── --}}
    @if ($tab === 'packages')
        @php $summary = $this->packageSummary; @endphp

        <div class="card bg-base-100 shadow-sm border border-base-200">
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr class="bg-base-200">
                            <th>Application</th>
                            <th class="text-center">Postes avec rapport</th>
                            <th class="text-center text-success">Installé</th>
                            <th class="text-center text-error">Erreur</th>
                            <th class="text-center">Taux succès</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($summary as $app)
                            @php
                                $total     = $app->total_count ?? 0;
                                $installed = $app->installed_count ?? 0;
                                $errors    = $app->error_count ?? 0;
                                $rate      = $total > 0 ? round(($installed / $total) * 100) : 0;
                            @endphp
                            <tr class="hover {{ $errors > 0 ? 'bg-error/5' : '' }}">
                                <td>
                                    <div class="font-semibold">{{ $app->name ?? $app->app_id }}</div>
                                    <div class="text-xs text-base-content/50 font-mono">{{ $app->app_id }}</div>
                                </td>
                                <td class="text-center">{{ $total }}</td>
                                <td class="text-center text-success font-semibold">{{ $installed }}</td>
                                <td class="text-center {{ $errors > 0 ? 'text-error font-semibold' : '' }}">
                                    {{ $errors }}
                                </td>
                                <td class="text-center">
                                    <div class="flex items-center gap-2 justify-center">
                                        <progress
                                            class="progress {{ $rate >= 80 ? 'progress-success' : ($rate >= 50 ? 'progress-warning' : 'progress-error') }} w-16"
                                            value="{{ $rate }}"
                                            max="100"
                                        ></progress>
                                        <span class="text-sm">{{ $rate }}%</span>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="text-center text-base-content/50 py-8">
                                    Aucune donnée de déploiement disponible.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($summary->hasPages())
                <div class="card-footer px-4 py-3">
                    {{ $summary->links() }}
                </div>
            @endif
        </div>
    @endif

</x-organisms.page>
