{{--
    Story 15.5 / AC3 — Dashboard d'état déploiement WPKG.
    Filesystem-based router : `/app/wpkg/deployments`.

    Permissions :
      - Gate `viewAny-workstationGroup` : lecture (cohérence 15.4).
      - Permission `wpkg.assign` : bouton « Forcer une re-évaluation » (drill-down vue détail poste).
--}}
<?php

use App\Components\Traits\WithToasts;
use App\Wpkg\Deployment\Services\WpkgDashboardQueryService;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Déploiement WPKG — Tableau de bord')] class extends Component {
    use WithToasts;
    use WithPagination;

    public bool $kpisLoaded = false;

    /** @var array<string,mixed> */
    public array $kpis = [];

    /** @var list<array<string,mixed>> */
    public array $groupAggregates = [];

    /** @var list<array<string,mixed>> */
    public array $profileAggregates = [];

    #[Url]
    public string $severityFilter = '';

    public int $perPage = 50;

    public function mount(): void
    {
        // Skeleton instantané : `loadStats` est déclenché côté front via wire:init.
    }

    public function loadStats(WpkgDashboardQueryService $queries): void
    {
        $this->kpis = $queries->kpis();
        $this->groupAggregates = $queries->groupAggregates();
        $this->profileAggregates = $queries->profileAggregates();
        $this->kpisLoaded = true;
    }

    public function refreshStats(WpkgDashboardQueryService $queries): void
    {
        $this->kpisLoaded = false;
        $this->loadStats($queries);
        $this->toastSuccess('Données actualisées.');
    }

    public function updatingSeverityFilter(): void
    {
        $this->resetPage();
    }

    public function getIncidentsProperty()
    {
        // Story 15.5 / Fix #11 — déduplication par workstation_id via le service.
        $statuses = $this->severityFilter !== ''
            ? [$this->severityFilter]
            : ['partial', 'failed', 'unknown'];

        return app(WpkgDashboardQueryService::class)
            ->recentIncidentsPaginated($this->perPage, $statuses);
    }
};
?>

<x-organisms.page
    title="Déploiement WPKG"
    description="État global du déploiement WPKG — postes sains, partiels, en échec, silencieux."
    wire:init="loadStats">

    <x-slot:actions>
        <a href="{{ route('app.wpkg.deployments.list') }}" class="btn btn-outline btn-sm" data-test="link-deployments-list">
            <i class="fa-solid fa-clock-rotate-left mr-1"></i>
            Voir tous les déploiements admin
        </a>
        <button wire:click="refreshStats" class="btn btn-outline btn-primary btn-sm" data-test="btn-refresh-stats">
            <i class="fa-solid fa-arrows-rotate mr-1"></i>
            Actualiser
        </button>
    </x-slot:actions>

    <div class="space-y-6" wire:loading.remove.delay>
        {{-- KPIs --}}
        @include('pages::wpkg.deployments._partials.kpi-cards')

        {{-- Vue par parc --}}
        @include('pages::wpkg.deployments._partials.group-aggregates-table')

        {{-- Vue par profil --}}
        @include('pages::wpkg.deployments._partials.profile-aggregates-table')

        {{-- Incidents 24h --}}
        @php $incidents = $this->incidents; @endphp
        @include('pages::wpkg.deployments._partials.incidents-table')
    </div>

    {{-- Skeleton initial / pendant loadStats --}}
    <div wire:loading.delay class="text-center py-10">
        <span class="loading loading-spinner loading-lg text-primary"></span>
        <p class="text-base-content/60 mt-3">Chargement des indicateurs…</p>
    </div>
</x-organisms.page>
