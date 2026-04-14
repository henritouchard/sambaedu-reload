<?php

declare(strict_types=1);

use App\Models\Workstation;
use App\Models\WorkstationApplicationStatus;
use App\Components\Traits\WithToasts;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Rapport poste - Déploiement Windows')] class extends Component {
    use WithToasts;
    use WithPagination;

    public Workstation $workstation;

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public int $perPage = 50;

    // Livewire v3 : implicit route model binding via typed public property
    // (le paramètre de route {workstation} est résolu automatiquement)

    public function updatedStatusFilter(): void { $this->resetPage(); }

    public function getStatusesProperty()
    {
        $query = WorkstationApplicationStatus::query()
            ->with('application')
            ->where('workstation_id', $this->workstation->id);

        if (!empty($this->statusFilter)) {
            $query->where('status', $this->statusFilter);
        }

        // Erreurs et non-installés en premier
        $query->orderByRaw(
            "CASE WHEN status = 'error' THEN 0 WHEN status = 'not-installed' THEN 1 ELSE 2 END"
        );

        return $query->paginate($this->perPage);
    }

    public function getCountsProperty(): array
    {
        return WorkstationApplicationStatus::query()
            ->where('workstation_id', $this->workstation->id)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();
    }
};
?>

<x-organisms.page
    title="Rapport : {{ $workstation->name }}"
    description="Statuts d'installation des applications WPKG"
>
    <x-slot:actions>
        <a href="{{ route('app.windows-deploy.reports.index') }}" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-arrow-left mr-1"></i>
            Retour aux rapports
        </a>
    </x-slot:actions>

    {{-- ── Informations poste ───────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="stat bg-base-100 border border-base-200 rounded-xl shadow-sm">
            <div class="stat-title text-xs">Poste</div>
            <div class="stat-value text-base font-mono">{{ $workstation->name }}</div>
        </div>
        <div class="stat bg-base-100 border border-base-200 rounded-xl shadow-sm">
            <div class="stat-title text-xs">Système d'exploitation</div>
            <div class="stat-value text-base">{{ $workstation->os ?? '—' }}</div>
        </div>
        <div class="stat bg-base-100 border border-base-200 rounded-xl shadow-sm">
            <div class="stat-title text-xs">Dernier rapport</div>
            <div class="stat-value text-sm">
                @if ($workstation->last_report_at)
                    <span title="{{ $workstation->last_report_at->format('d/m/Y H:i:s') }}">
                        {{ $workstation->last_report_at->diffForHumans() }}
                    </span>
                @else
                    <span class="text-base-content/40">Jamais</span>
                @endif
            </div>
        </div>
        <div class="stat bg-base-100 border border-base-200 rounded-xl shadow-sm">
            <div class="stat-title text-xs">IP / MAC</div>
            <div class="stat-value text-sm font-mono">{{ $workstation->ip ?? '—' }}</div>
            <div class="stat-desc text-xs font-mono">{{ $workstation->mac ?? '' }}</div>
        </div>
    </div>

    {{-- ── Badges de statut ─────────────────────────────────────────────────── --}}
    @php $counts = $this->counts; @endphp
    <div class="flex flex-wrap gap-2 mb-4">
        <button
            wire:click="$set('statusFilter', '')"
            class="badge badge-lg {{ empty($statusFilter) ? 'badge-neutral' : 'badge-ghost' }} cursor-pointer"
        >
            Tous ({{ array_sum($counts) }})
        </button>
        @if (!empty($counts['installed']))
        <button
            wire:click="$set('statusFilter', 'installed')"
            class="badge badge-lg {{ $statusFilter === 'installed' ? 'badge-success' : 'badge-ghost' }} cursor-pointer"
        >
            ✓ Installé ({{ $counts['installed'] }})
        </button>
        @endif
        @if (!empty($counts['error']))
        <button
            wire:click="$set('statusFilter', 'error')"
            class="badge badge-lg {{ $statusFilter === 'error' ? 'badge-error' : 'badge-ghost' }} cursor-pointer"
        >
            ⚠ Erreur ({{ $counts['error'] }})
        </button>
        @endif
        @if (!empty($counts['not-installed']))
        <button
            wire:click="$set('statusFilter', 'not-installed')"
            class="badge badge-lg {{ $statusFilter === 'not-installed' ? 'badge-warning' : 'badge-ghost' }} cursor-pointer"
        >
            ✗ Non installé ({{ $counts['not-installed'] }})
        </button>
        @endif
    </div>

    {{-- ── Tableau des statuts ──────────────────────────────────────────────── --}}
    @php $statuses = $this->statuses; @endphp
    <div class="card bg-base-100 shadow-sm border border-base-200">
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr class="bg-base-200">
                        <th>Application</th>
                        <th>Version installée</th>
                        <th class="text-center">Statut</th>
                        <th class="text-center">Reboot requis</th>
                        <th>Date rapport</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($statuses as $status)
                        @php
                            $isError = in_array($status->status, ['error', 'not-installed']);
                        @endphp
                        <tr class="{{ $isError ? 'bg-error/5 hover:bg-error/10' : 'hover' }}">
                            <td>
                                @if ($status->application)
                                    <div class="font-semibold">{{ $status->application->name ?? $status->application->app_id }}</div>
                                    <div class="text-xs text-base-content/50 font-mono">{{ $status->application->app_id }}</div>
                                @else
                                    <span class="text-base-content/40 italic">Application inconnue</span>
                                @endif
                            </td>
                            <td class="font-mono text-sm">{{ $status->installed_version ?: '—' }}</td>
                            <td class="text-center">
                                @switch($status->status)
                                    @case('installed')
                                        <span class="badge badge-success badge-sm">Installé</span>
                                        @break
                                    @case('error')
                                        <span class="badge badge-error badge-sm">Erreur</span>
                                        @break
                                    @case('not-installed')
                                        <span class="badge badge-warning badge-sm">Non installé</span>
                                        @break
                                    @case('upgrading')
                                        <span class="badge badge-info badge-sm">En cours</span>
                                        @break
                                    @default
                                        <span class="badge badge-ghost badge-sm">{{ $status->status }}</span>
                                @endswitch
                            </td>
                            <td class="text-center">
                                @if ($status->reboot_required)
                                    <span class="badge badge-warning badge-xs">
                                        <i class="fa-solid fa-rotate-right"></i>
                                    </span>
                                @else
                                    <span class="text-base-content/30">—</span>
                                @endif
                            </td>
                            <td class="text-sm text-base-content/70">
                                @if ($status->reported_at)
                                    <span title="{{ $status->reported_at }}">
                                        {{ $status->reported_at->format('d/m/Y H:i') }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-base-content/50 py-8">
                                Aucun statut d'application pour ce poste.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($statuses->hasPages())
            <div class="card-footer px-4 py-3">
                {{ $statuses->links() }}
            </div>
        @endif
    </div>

</x-organisms.page>
