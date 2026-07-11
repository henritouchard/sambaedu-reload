<?php

use App\Services\Network\DhcpImportService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Rapport d\'import DHCP')] class extends Component {
    public string $uuid = '';

    public function mount(string $uuid): void
    {
        if (Gate::denies('viewAny-dhcp')) {
            abort(403);
        }
        $this->uuid = $uuid;
    }

    public function with(): array
    {
        $report = app(DhcpImportService::class)->fetchReport($this->uuid);
        if ($report === null) {
            abort(404, 'Rapport d\'import introuvable ou expiré (24h).');
        }
        return ['report' => $report];
    }
};
?>

<x-organisms.page :backUrl="route('app.network.dhcp', ['tab' => 'reservations'])" title="Rapport d'import CSV — DHCP"
    backText="Retour à la liste">

    <div class="space-y-4">
        <div class="stats shadow w-full">
            <div class="stat">
                <div class="stat-title">Total lignes</div>
                <div class="stat-value">{{ $report->total }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Créées</div>
                <div class="stat-value text-success">{{ $report->ok }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Mises à jour</div>
                <div class="stat-value text-info">{{ $report->updated }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Erreurs</div>
                <div class="stat-value text-error">{{ $report->errors }}</div>
            </div>
            <div class="stat">
                <div class="stat-title">Ignorées</div>
                <div class="stat-value text-base-content/50">{{ $report->skipped }}</div>
            </div>
        </div>

        <p class="text-sm text-base-content/60">
            Rapport généré le {{ $report->createdAt }} — UUID <code>{{ $report->uuid }}</code> —
            persisté 24h en cache.
        </p>

        @include('pages.network.dhcp.import._partials.import-report-table', ['report' => $report])
    </div>
</x-organisms.page>
