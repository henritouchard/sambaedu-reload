{{--
    Story 15.5 / AC4.5 — Listing paginé des déploiements admin
    (`wpkg_deployments`) avec filtres status / user / période.

    Route : `wpkg.deployments.list` (`/app/wpkg/deployments/list`).
--}}
<?php

use App\Components\Traits\WithToasts;
use App\Models\User;
use App\Wpkg\Deployment\Models\WpkgDeployment;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Déploiement WPKG — Historique')] class extends Component {
    use WithToasts;
    use WithPagination;

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public ?int $userFilter = null;

    #[Url]
    public ?string $sinceDate = null;

    public int $perPage = 30;

    public function updatingStatusFilter(): void { $this->resetPage(); }
    public function updatingUserFilter(): void { $this->resetPage(); }
    public function updatingSinceDate(): void { $this->resetPage(); }

    public function getDeploymentsProperty()
    {
        $perPage = max(1, min(200, $this->perPage));

        $query = WpkgDeployment::query()
            ->with(['triggeredBy:id,name', 'workstationStatuses:deployment_id,client_status'])
            ->orderByDesc('triggered_at');

        if ($this->statusFilter !== '') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->userFilter !== null) {
            $query->where('triggered_by', $this->userFilter);
        }

        if (! empty($this->sinceDate)) {
            try {
                $query->where('triggered_at', '>=', \Illuminate\Support\Carbon::parse($this->sinceDate));
            } catch (\Throwable $e) {
                // sinceDate invalide → ignore
            }
        }

        return $query->paginate($perPage);
    }

    public function getUsersProperty()
    {
        return User::query()
            ->whereIn('id', WpkgDeployment::query()->select('triggered_by')->whereNotNull('triggered_by'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
};
?>

<x-organisms.page
    title="Déploiements WPKG — Historique"
    description="Liste des opérations admin (clones parc, bulks catégorie, re-évaluations) avec leur état d'exécution.">

    <x-slot:actions>
        <a href="{{ route('app.wpkg.deployments') }}" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-gauge-high mr-1"></i>
            Retour au tableau de bord
        </a>
    </x-slot:actions>

    <div class="card bg-base-100 shadow-sm">
        <div class="card-body">
            {{-- Filtres --}}
            <div class="flex flex-wrap gap-3 mb-4">
                <select wire:model.live="statusFilter" class="select select-bordered select-sm">
                    <option value="">Tous statuts</option>
                    <option value="pending">Pending</option>
                    <option value="running">Running</option>
                    <option value="completed">Completed</option>
                    <option value="partial">Partial</option>
                    <option value="failed">Failed</option>
                </select>

                <select wire:model.live="userFilter" class="select select-bordered select-sm">
                    <option value="">Tous initiateurs</option>
                    @foreach ($this->users as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>

                <input
                    type="date"
                    wire:model.live="sinceDate"
                    class="input input-bordered input-sm"
                    placeholder="Depuis le..."
                />
            </div>

            @php $deployments = $this->deployments; @endphp

            @if ($deployments->isEmpty())
                <div class="hero min-h-[160px]">
                    <div class="hero-content text-center">
                        <p class="text-base-content/60">Aucun déploiement à afficher.</p>
                    </div>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Initiateur</th>
                                <th>Cible (résumé)</th>
                                <th>Statut</th>
                                <th class="text-center">Reportés / Total</th>
                                <th>ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($deployments as $deployment)
                                @php
                                    $summary = $deployment->summary ?? [];
                                    $reported = $summary['reported'] ?? $deployment->workstationStatuses->count();
                                    $totalTargets = $summary['total_targets'] ?? 0;
                                    $scope = $deployment->target_scope ?? [];
                                    $scopeSummary = collect($scope)
                                        ->map(fn ($v, $k) => is_array($v) ? "{$k}: " . count($v) : "{$k}: {$v}")
                                        ->implode(', ');
                                    $statusBadge = match ($deployment->status) {
                                        'completed' => 'badge-success',
                                        'partial' => 'badge-warning',
                                        'failed' => 'badge-error',
                                        'running' => 'badge-info',
                                        default => 'badge-ghost',
                                    };
                                @endphp
                                <tr data-test="deployment-row" data-deployment-id="{{ $deployment->id }}">
                                    <td class="text-sm">
                                        {{ $deployment->triggered_at?->format('Y-m-d H:i') ?? '—' }}
                                    </td>
                                    <td class="text-sm">
                                        {{ $deployment->triggeredBy?->name ?? 'système' }}
                                    </td>
                                    <td class="text-sm text-base-content/70">
                                        {{ $scopeSummary !== '' ? $scopeSummary : '—' }}
                                    </td>
                                    <td>
                                        <span class="badge {{ $statusBadge }} badge-sm">{{ $deployment->status }}</span>
                                    </td>
                                    <td class="text-center text-sm">
                                        {{ $reported }} / {{ $totalTargets ?: '?' }}
                                    </td>
                                    <td class="text-xs text-base-content/50 font-mono">
                                        {{ \Illuminate\Support\Str::limit($deployment->id, 8, '…') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4">
                    {{ $deployments->links() }}
                </div>
            @endif
        </div>
    </div>
</x-organisms.page>
