<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Components\Traits\WithToasts;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

new #[Title('Tableau de bord - Instance SE4FS')] class extends Component {
    use WithToasts;

    // Statistiques
    public array $stats = [];
    public array $recentActivity = [];
    public array $mariaDbStatus = [];
    public array $user = [];

    // État de chargement
    public bool $statsLoaded = false;

    public function mount()
    {
        $this->loadData();
    }

    /**
     * Charger les données du dashboard
     */
    public function loadData()
    {
        // Récupération des données utilisateur depuis la session
        $this->user = session('sambaedu_user', []);

        // Statistiques du dashboard
        $this->stats = $this->getDashboardStats();

        // Activité récente
        $this->recentActivity = $this->getRecentActivity();

        // MariaDB status
        $this->mariaDbStatus = ['status' => true, 'message' => 'OK', 'details' => []];

        $this->statsLoaded = true;
    }

    /**
     * Actualiser les statistiques
     */
    public function refreshStats()
    {
        $this->statsLoaded = false;
        $this->loadData();
        $this->toastSuccess('Statistiques actualisées');
    }

    /**
     * Redémarrer les workers de queue
     */
    public function restartQueueWorkers(): void
    {
        try {
            Artisan::call('queue:restart');

            $this->refreshStats();
            $this->toastSuccess('Signal de redémarrage envoyé aux workers de queue');
        } catch (\Throwable $exception) {
            $this->toastError('Impossible de redémarrer les workers de queue');
        }
    }

    private function getDashboardStats(): array
    {
        // TODO: Remplacer par de vraies données depuis les services SE4
        return [
            'active_users' => 89,
            'online_machines' => 89,
            'total_machines' => 120,
            'disk_usage' => 78,
            'queue_workers' => $this->getQueueWorkerCount(),
            'pending_jobs' => DB::table('jobs')->count(),
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }

    private function getQueueWorkerCount(): int
    {
        $output = shell_exec("ps aux | grep 'queue:work' | grep -v grep | wc -l");
        return (int) trim($output ?? '0');
    }

    private function getRecentActivity(): array
    {
        // TODO: Implémenter la récupération de l'activité réelle
        return [
            [
                'initials' => 'JD',
                'name' => 'Jean Dupont',
                'action' => 's\'est connecté',
                'time_ago' => 'Il y a 5 minutes',
                'color' => 'primary',
            ],
            [
                'initials' => 'ML',
                'name' => 'Marie Leblanc',
                'action' => 'a imprimé un document',
                'time_ago' => 'Il y a 12 minutes',
                'color' => 'success',
            ],
            [
                'initials' => 'PM',
                'name' => 'Pierre Martin',
                'action' => 'a créé un nouveau parc',
                'time_ago' => 'Il y a 1 heure',
                'color' => 'warning',
            ],
        ];
    }
};
?>

<x-organisms.page title="{{ config('sambaedu.establishment_name') }}" description="Vue d'ensemble de votre infrastructure SambaEdu">
    <x-slot:actions>
        <button wire:click="refreshStats" wire:loading.attr="disabled" class="btn btn-outline btn-primary btn-sm">
            <i class="fas fa-refresh" wire:loading.class="fa-spin" wire:target="refreshStats"></i>
            Actualiser
        </button>
    </x-slot:actions>

    <div>
        <!-- Statut des services -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- MariaDB Status -->
            <div
                class="stat bg-base-100 shadow-lg rounded-2xl border @if ($mariaDbStatus['status']) border-success/30 @else border-error/30 @endif">
                <div class="stat-figure @if ($mariaDbStatus['status']) text-success @else text-error @endif">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4">
                        </path>
                    </svg>
                </div>
                <div class="stat-title font-medium">Base de données MariaDB</div>
                <div class="stat-value text-2xl @if ($mariaDbStatus['status']) text-success @else text-error @endif">
                    @if ($mariaDbStatus['status'])
                        <i class="fa-solid fa-circle-check"></i> Connecté
                    @else
                        <i class="fa-solid fa-circle-xmark"></i> Déconnecté
                    @endif
                </div>
                <div class="stat-desc">
                    {{ $mariaDbStatus['message'] }}
                    @if (isset($mariaDbStatus['details']['table_connexions']) && $mariaDbStatus['status'])
                        <br><span class="opacity-70">Table connexions:
                            {{ $mariaDbStatus['details']['table_connexions'] }}</span>
                    @endif
                    @if (isset($mariaDbStatus['details']['error']) && !$mariaDbStatus['status'])
                        <br><span class="text-error">{{ $mariaDbStatus['details']['error'] }}</span>
                    @endif
                </div>
            </div>

            <!-- Active Directory Status -->
            <div class="stat bg-base-100 shadow-lg rounded-2xl border border-info/30">
                <div class="stat-figure text-info">
                    <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01">
                        </path>
                    </svg>
                </div>
                <div class="stat-title font-medium">Active Directory</div>
                <div class="stat-value text-2xl text-info">
                    <i class="fa-solid fa-circle-check"></i> Connecté
                </div>
                <div class="stat-desc">
                    Serveur AD opérationnel
                    <br><span class="opacity-70">Authentification LDAP active</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <!-- Card 1 -->
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title text-primary">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                            </path>
                        </svg>
                        Utilisateurs Actifs
                    </h2>
                    <p class="text-3xl font-bold text-primary">{{ $stats['active_users'] }}</p>
                    <p class="text-sm text-base-content/60">Connectés actuellement</p>
                </div>
            </div>

            <!-- Card 2 -->
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title text-success">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                            </path>
                        </svg>
                        Machines en Ligne
                    </h2>
                    <p class="text-3xl font-bold text-success">{{ $stats['online_machines'] }}</p>
                    <p class="text-sm text-base-content/60">Sur {{ $stats['total_machines'] }} machines</p>
                </div>
            </div>

            <!-- Card 3 -->
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title text-warning">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16">
                            </path>
                        </svg>
                        Espace Disque
                    </h2>
                    <p class="text-3xl font-bold text-warning">{{ $stats['disk_usage'] }}%</p>
                    <p class="text-sm text-base-content/60">Utilisation serveur</p>
                </div>
            </div>

            <!-- Card 4 - Queue Workers -->
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <div class="flex items-start justify-between gap-2">
                        <h2 class="card-title @if ($stats['queue_workers'] > 0) text-info @else text-error @endif">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                </path>
                            </svg>
                            Queue Workers
                        </h2>

                        <div class="dropdown dropdown-end">
                            <button tabindex="0" type="button" class="btn btn-ghost btn-xs"
                                aria-label="Actions queue workers">
                                <i class="fa-solid fa-ellipsis-vertical"></i>
                            </button>
                            <ul tabindex="0"
                                class="dropdown-content menu menu-sm z-[1] mt-1 w-52 rounded-box bg-base-100 p-2 shadow">
                                <li>
                                    <button type="button" wire:click="restartQueueWorkers" wire:loading.attr="disabled"
                                        wire:target="restartQueueWorkers">
                                        <i class="fa-solid fa-rotate-right"></i>
                                        Redémarrer les workers
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <p class="text-3xl font-bold @if ($stats['queue_workers'] > 0) text-info @else text-error @endif">
                        {{ $stats['queue_workers'] }}
                    </p>
                    <p class="text-sm text-base-content/60">
                        {{ $stats['pending_jobs'] }} job{{ $stats['pending_jobs'] > 1 ? 's' : '' }} en attente
                        @if ($stats['failed_jobs'] > 0)
                            · <span class="text-error">{{ $stats['failed_jobs'] }}
                                échoué{{ $stats['failed_jobs'] > 1 ? 's' : '' }}</span>
                        @endif
                    </p>

                    <div class="pt-2">
                        <a href="{{ route('app.workers.index') }}" class="btn btn-xs btn-outline">
                            Voir les workers et logs
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Activité récente -->
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title mb-4">Activité Récente</h2>
                    <div class="space-y-3">
                        @foreach ($recentActivity as $activity)
                            <x-molecules.user-activity-item :initials="$activity['initials']" :name="$activity['name']" :action="$activity['action']"
                                :time-ago="$activity['time_ago']" :color="$activity['color']" />
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Actions rapides -->
            <div class="card bg-base-100 shadow-sm border border-base-200">
                <div class="card-body">
                    <h2 class="card-title mb-4">Actions Rapides</h2>
                    <div class="grid grid-cols-2 gap-3">
                        <a href="{{ route('app.users') }}" class="btn highlight btn-outline btn-primary">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                                </path>
                            </svg>
                            Utilisateurs
                        </a>
                        <a href="{{ route('app.parc.index') }}" class="btn btn-outline btn-success">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                </path>
                            </svg>
                            Parcs
                        </a>
                        <a href="/printers/list_printers.php" class="btn btn-outline btn-warning">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z">
                                </path>
                            </svg>
                            Imprimantes
                        </a>
                        <a href="/bbb/create.php" class="btn btn-outline btn-info">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z">
                                </path>
                            </svg>
                            Visio
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-organisms.page>
