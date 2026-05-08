{{--
    Story 15.5 / AC4 — Vue détail poste WPKG (drill-down depuis le dashboard).

    Note divergence : la story décrivait une « extension » de la page 9.4
    `windows-deploy.reports.show` mais cette page n'existe pas encore dans le
    code (la story 9.4 livre l'API mais pas la vue détail). On crée donc la
    vue détail dans le namespace 15.5 avec une route dédiée
    `wpkg.deployments.workstation`. La story 15.7 arbitrera l'unification le
    cas échéant.

    Permissions :
      - Gate `viewAny-workstationGroup` : lecture (cohérence dashboard).
      - Permission `wpkg.assign` : bouton « Forcer une re-évaluation ».
--}}
<?php

use App\Components\Traits\WithToasts;
use App\Models\Workstation;
use App\Wpkg\Deployment\Events\WorkstationManualReevaluationRequested;
use App\Wpkg\Deployment\Models\WpkgDeploymentWorkstationStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Déploiement WPKG — Détail poste')] class extends Component {
    use WithToasts;
    use WithPagination;

    public int $workstationId;
    public string $hostname = '';

    /** @var array<string,mixed>|null */
    public ?array $latestStatus = null;

    #[Locked]
    public bool $canForceReevaluation = false;

    public function mount(int $workstation): void
    {
        $w = Workstation::find($workstation);

        if ($w === null) {
            abort(404, "Poste introuvable.");
        }

        $this->workstationId = $w->id;
        $this->hostname = $w->name;

        $this->canForceReevaluation = auth()->user()?->can('wpkg.assign') ?? false;

        $this->loadLatestStatus();
    }

    public function loadLatestStatus(): void
    {
        $latest = WpkgDeploymentWorkstationStatus::query()
            ->where('workstation_id', $this->workstationId)
            ->orderByDesc('client_reported_at')
            ->first();

        if ($latest === null) {
            $this->latestStatus = null;

            return;
        }

        $this->latestStatus = [
            'client_status' => $latest->client_status,
            'client_reported_at' => $latest->client_reported_at?->toIso8601String(),
            'details' => is_array($latest->details) ? $latest->details : [],
            'error_message' => $latest->error_message,
            'deployment_id' => $latest->deployment_id,
        ];
    }

    public function getReportsHistoryProperty()
    {
        return WpkgDeploymentWorkstationStatus::query()
            ->where('workstation_id', $this->workstationId)
            ->orderByDesc('client_reported_at')
            ->paginate(10);
    }

    public function forceReevaluation(): void
    {
        // Défense en profondeur : #[Locked] empêche déjà la mutation côté Livewire,
        // mais on re-vérifie la permission runtime au cas où le user l'aurait perdue
        // depuis le mount() initial.
        abort_unless(auth()->user()?->can('wpkg.assign'), 403);

        if (! $this->canForceReevaluation) {
            $this->toastError('Permission refusée.');

            return;
        }

        $userId = (int) auth()->id();
        event(new WorkstationManualReevaluationRequested($this->workstationId, $userId));

        Log::channel('wpkg-deploy')->info('[wpkg.deployments.workstation] re-évaluation manuelle déclenchée', [
            'event' => 'wpkg_manual_reevaluation',
            'workstation_id' => $this->workstationId,
            'hostname' => $this->hostname,
            'triggered_by_user_id' => $userId,
        ]);

        $this->toastSuccess('Re-évaluation déclenchée — la config sera servie au prochain login client.');
    }
};
?>

<x-organisms.page
    title="Détail déploiement WPKG"
    description="Historique des rapports + état du poste {{ $hostname }}.">

    <x-slot:actions>
        <a href="{{ route('app.wpkg.deployments') }}" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-arrow-left mr-1"></i>
            Retour au tableau de bord
        </a>

        @if ($canForceReevaluation)
            <button
                type="button"
                class="btn btn-warning btn-sm"
                data-test="btn-force-reevaluation"
                x-data
                @click="$dispatch('open-confirm-modal', {
                    title: 'Forcer la re-évaluation',
                    body: 'Forcer la régénération des XML/INI pour {{ $hostname }} ? Le client WPKG appliquera la nouvelle config au prochain démarrage.',
                    confirmText: 'Forcer',
                    confirmEvent: 'confirm-force-reevaluation',
                })">
                <i class="fa-solid fa-rotate mr-1"></i>
                Forcer une re-évaluation
            </button>
        @endif
    </x-slot:actions>

    {{-- Modale de confirmation réutilisable --}}
    <x-molecules.confirm-modal />

    {{-- Listener Livewire du confirm-event de la modale --}}
    <div x-data
         @confirm-force-reevaluation.window="$wire.forceReevaluation()"></div>

    {{-- Statut courant --}}
    <div class="card bg-base-100 shadow-sm" data-test="latest-status-card">
        <div class="card-body">
            <h2 class="card-title text-lg">
                <i class="fa-solid fa-gauge mr-2"></i>
                Dernier rapport
            </h2>

            @if ($latestStatus === null)
                <p class="text-base-content/60 py-2">Aucun rapport reçu pour ce poste.</p>
            @else
                @php
                    $details = $latestStatus['details'] ?? [];
                    $counters = $details['counters'] ?? [];
                    $statusBadge = match ($latestStatus['client_status']) {
                        'success' => 'badge-success',
                        'partial' => 'badge-warning',
                        'failed' => 'badge-error',
                        'unknown' => 'badge-ghost',
                        default => 'badge-ghost',
                    };
                @endphp
                <div class="flex flex-wrap items-center gap-4">
                    <span class="badge {{ $statusBadge }} badge-lg">{{ $latestStatus['client_status'] }}</span>
                    @if (! empty($latestStatus['client_reported_at']))
                        <span class="text-sm text-base-content/70">
                            Reporté
                            {{ \Illuminate\Support\Carbon::parse($latestStatus['client_reported_at'])->diffForHumans() }}
                        </span>
                    @endif
                    @if (! empty($counters))
                        <span class="text-sm">
                            <strong>{{ $counters['total'] ?? 0 }}</strong> apps —
                            <span class="text-success">{{ $counters['success'] ?? 0 }} OK</span> /
                            <span class="text-error">{{ $counters['failed'] ?? 0 }} échec</span>
                            @if (! empty($counters['reboot']))
                                / <span class="text-warning">{{ $counters['reboot'] }} reboot</span>
                            @endif
                        </span>
                    @endif
                </div>

                @if (! empty($latestStatus['error_message']))
                    <div class="alert alert-error mt-3 text-sm">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        {{ $latestStatus['error_message'] }}
                    </div>
                @endif
            @endif
        </div>
    </div>

    {{-- Historique --}}
    <div class="card bg-base-100 shadow-sm mt-4" data-test="reports-history">
        <div class="card-body">
            <h2 class="card-title text-lg">
                <i class="fa-solid fa-clock-rotate-left mr-2"></i>
                Historique des rapports
            </h2>

            @php $history = $this->reportsHistory; @endphp

            @if ($history->isEmpty())
                <p class="text-base-content/60 py-2">Aucun rapport historique.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-zebra w-full text-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Statut</th>
                                <th>Apps OK / Échec / Total</th>
                                <th>Déploiement admin</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($history as $row)
                                @php
                                    $details = is_array($row->details) ? $row->details : [];
                                    $counters = $details['counters'] ?? [];
                                    $badge = match ($row->client_status) {
                                        'success' => 'badge-success',
                                        'partial' => 'badge-warning',
                                        'failed' => 'badge-error',
                                        'unknown' => 'badge-ghost',
                                        default => 'badge-ghost',
                                    };
                                @endphp
                                <tr>
                                    <td>{{ $row->client_reported_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    <td>
                                        <span class="badge {{ $badge }} badge-sm">{{ $row->client_status }}</span>
                                    </td>
                                    <td>
                                        {{ ($counters['success'] ?? 0) }} /
                                        {{ ($counters['failed'] ?? 0) }} /
                                        {{ ($counters['total'] ?? 0) }}
                                    </td>
                                    <td class="text-xs font-mono text-base-content/60">
                                        {{ $row->deployment_id ? \Illuminate\Support\Str::limit($row->deployment_id, 8, '…') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3">{{ $history->links() }}</div>
            @endif
        </div>
    </div>
</x-organisms.page>
