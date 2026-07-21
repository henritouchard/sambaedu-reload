<?php

use Livewire\Component;
use Livewire\Attributes\Title;
use App\Components\Traits\WithToasts;
use App\Models\AppProfile;
use App\Models\Application;
use App\Models\Depot;
use App\Models\MachineBootLog;
use App\Models\Printer;
use App\Models\Shortcut;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

new #[Title('Tableau de bord - Instance SE4FS')] class extends Component {
    use WithToasts;

    public array $stats = [];
    public array $recentActivity = [];
    public array $mariaDbStatus = [];
    public array $postgresStatus = [];
    public array $user = [];
    public bool $statsLoaded = false;

    public function mount()
    {
        $this->loadData();
    }

    public function loadData()
    {
        $this->user = session('sambaedu_user', []);
        $this->stats = $this->getDashboardStats();
        $this->recentActivity = $this->getRecentActivity();
        $this->mariaDbStatus = $this->getMariaDbStatus();
        $this->postgresStatus = $this->getPostgresStatus();
        $this->statsLoaded = true;
    }

    public function refreshStats()
    {
        $this->statsLoaded = false;
        $this->loadData();
        $this->toastSuccess('Statistiques actualisées');
    }

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
        $totalBytes = disk_total_space('/');
        $freeBytes  = disk_free_space('/');
        $diskPercent = $totalBytes ? round(($totalBytes - $freeBytes) / $totalBytes * 100, 1) : 0;

        return [
            'active_users'    => User::where('is_active', true)->count(),
            'total_users'     => User::count(),
            'user_groups_count' => UserGroup::count(),
            'online_machines' => Workstation::where('status', 'online')->count(),
            'total_machines'  => Workstation::count(),
            'disk_usage'      => $diskPercent,
            'disk_free_gb'    => round($freeBytes / (1024 ** 3), 1),
            'queue_workers'   => $this->getQueueWorkerCount(),
            'pending_jobs'    => DB::table('jobs')->count(),
            'failed_jobs'     => DB::table('failed_jobs')->count(),
            'groups_count'      => WorkstationGroup::count(),
            'printers_count'    => Printer::count(),
            'depots_count'      => Depot::count(),
            'applications_count' => Application::count(),
            'app_profiles_count' => AppProfile::notArchived()->count(),
            'shortcuts_desktop_count' => Shortcut::byPlace(Shortcut::PLACE_DESKTOP)->count(),
            'shortcuts_startup_count' => Shortcut::byPlace(Shortcut::PLACE_STARTUP)->count(),
            'shortcuts_taskbar_count' => Shortcut::byPlace(Shortcut::PLACE_TASKBAR)->count(),
        ];
    }

    private function getMariaDbStatus(): array
    {
        if (blank(config('database.connections.legacy_mysql.database'))) {
            return ['status' => null, 'message' => 'Base legacy SE4 non configurée', 'details' => []];
        }

        return $this->probeConnection('legacy_mysql');
    }

    private function getPostgresStatus(): array
    {
        return $this->probeConnection('pgsql');
    }

    private function probeConnection(string $connection): array
    {
        try {
            DB::connection($connection)->getPdo();
            return ['status' => true, 'message' => 'Connecté', 'details' => []];
        } catch (\Throwable $e) {
            return ['status' => false, 'message' => $e->getMessage(), 'details' => ['error' => $e->getMessage()]];
        }
    }

    private function getQueueWorkerCount(): int
    {
        $output = shell_exec("ps aux | grep 'queue:work' | grep -v grep | wc -l");
        return (int) trim($output ?? '0');
    }

    private function getRecentActivity(): array
    {
        $activities = [];

        foreach (MachineBootLog::with('workstation')->latest()->limit(5)->get() as $log) {
            $actionLabel = match ($log->action) {
                'wake'     => 'a été démarré',
                'shutdown' => 'a été éteint',
                'reboot'   => 'a redémarré',
                default    => $log->action,
            };
            $activities[] = [
                'initials'  => strtoupper(substr($log->machine_name, 0, 2)),
                'name'      => $log->machine_name,
                'action'    => $actionLabel,
                'time_ago'  => $log->created_at->diffForHumans(),
                'color'     => $log->action === 'wake' ? 'success' : 'warning',
                'timestamp' => $log->created_at->getTimestamp(),
            ];
        }

        if (count($activities) < 5) {
            $users = User::where('is_active', true)
                ->whereNotNull('firstname')
                ->orderBy('updated_at', 'desc')
                ->limit(5 - count($activities))
                ->get();

            foreach ($users as $user) {
                $initials = strtoupper(
                    substr($user->firstname ?? '', 0, 1) . substr($user->lastname ?? '', 0, 1)
                );
                $activities[] = [
                    'initials'  => $initials ?: '??',
                    'name'      => trim($user->firstname . ' ' . $user->lastname),
                    'action'    => 'compte synchronisé',
                    'time_ago'  => $user->updated_at->diffForHumans(),
                    'color'     => 'primary',
                    'timestamp' => $user->updated_at->getTimestamp(),
                ];
            }
        }

        usort($activities, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        return array_slice($activities, 0, 5);
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
        <!-- Statut des services (groupe technique, compact) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 mb-8">
            <!-- PostgreSQL Status -->
            <div
                class="stat p-3 bg-base-100 shadow-lg rounded-2xl border @if ($postgresStatus['status']) border-success/30 @else border-error/30 @endif">
                <div class="stat-figure @if ($postgresStatus['status']) text-success @else text-error @endif">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4">
                        </path>
                    </svg>
                </div>
                <div class="stat-title text-xs font-medium truncate">PostgreSQL</div>
                <div class="stat-value text-lg truncate @if ($postgresStatus['status']) text-success @else text-error @endif">
                    @if ($postgresStatus['status'])
                        <i class="fa-solid fa-circle-check"></i> Connecté
                    @else
                        <i class="fa-solid fa-circle-xmark"></i> Déconnecté
                    @endif
                </div>
                <div class="stat-desc text-xs line-clamp-2" title="{{ $postgresStatus['message'] }}{{ isset($postgresStatus['details']['error']) && !$postgresStatus['status'] ? ' — ' . $postgresStatus['details']['error'] : '' }}">
                    {{ $postgresStatus['message'] }}
                    @if (isset($postgresStatus['details']['error']) && !$postgresStatus['status'])
                        <br><span class="text-error">{{ $postgresStatus['details']['error'] }}</span>
                    @endif
                </div>
            </div>

            <!-- MariaDB Status (legacy SE4) -->
            <div
                class="stat p-3 bg-base-100 shadow-lg rounded-2xl border @if ($mariaDbStatus['status'] === true) border-success/30 @elseif ($mariaDbStatus['status'] === false) border-error/30 @else border-base-300 @endif">
                <div class="stat-figure @if ($mariaDbStatus['status'] === true) text-success @elseif ($mariaDbStatus['status'] === false) text-error @else text-base-content/40 @endif">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4">
                        </path>
                    </svg>
                </div>
                <div class="stat-title text-xs font-medium truncate">MariaDB (legacy)</div>
                <div class="stat-value text-lg truncate @if ($mariaDbStatus['status'] === true) text-success @elseif ($mariaDbStatus['status'] === false) text-error @else text-base-content/50 @endif">
                    @if ($mariaDbStatus['status'] === true)
                        <i class="fa-solid fa-circle-check"></i> Connecté
                    @elseif ($mariaDbStatus['status'] === false)
                        <i class="fa-solid fa-circle-xmark"></i> Déconnecté
                    @else
                        <i class="fa-solid fa-circle-minus"></i> Non configuré
                    @endif
                </div>
                <div class="stat-desc text-xs line-clamp-2" title="{{ $mariaDbStatus['message'] }}{{ isset($mariaDbStatus['details']['error']) && !$mariaDbStatus['status'] ? ' — ' . $mariaDbStatus['details']['error'] : '' }}">
                    {{ $mariaDbStatus['message'] }}
                    @if (isset($mariaDbStatus['details']['error']) && !$mariaDbStatus['status'])
                        <br><span class="text-error">{{ $mariaDbStatus['details']['error'] }}</span>
                    @endif
                </div>
            </div>

            <!-- Active Directory Status -->
            <div class="stat p-3 bg-base-100 shadow-lg rounded-2xl border border-info/30">
                <div class="stat-figure text-info">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01">
                        </path>
                    </svg>
                </div>
                <div class="stat-title text-xs font-medium truncate">Active Directory</div>
                <div class="stat-value text-lg text-info truncate">
                    <i class="fa-solid fa-circle-check"></i> Connecté
                </div>
                <div class="stat-desc text-xs line-clamp-2">
                    Serveur AD opérationnel
                    <br><span class="opacity-70">Authentification LDAP active</span>
                </div>
            </div>

            <!-- Espace disque -->
            <div
                class="stat p-3 bg-base-100 shadow-lg rounded-2xl border @if ($stats['disk_usage'] >= 90) border-error/30 @elseif ($stats['disk_usage'] >= 75) border-warning/30 @else border-success/30 @endif">
                <div class="stat-figure @if ($stats['disk_usage'] >= 90) text-error @elseif ($stats['disk_usage'] >= 75) text-warning @else text-success @endif">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4">
                        </path>
                    </svg>
                </div>
                <div class="stat-title text-xs font-medium truncate">Espace Disque</div>
                <div class="stat-value text-lg truncate @if ($stats['disk_usage'] >= 90) text-error @elseif ($stats['disk_usage'] >= 75) text-warning @else text-success @endif">
                    {{ $stats['disk_usage'] }}%
                </div>
                <div class="stat-desc text-xs truncate">{{ $stats['disk_free_gb'] }} Go disponibles</div>
            </div>

            <!-- Queue Workers -->
            <div class="stat p-3 bg-base-100 shadow-lg rounded-2xl border @if ($stats['queue_workers'] > 0) border-info/30 @else border-error/30 @endif">
                <div class="flex items-start justify-between">
                    <div class="stat-figure @if ($stats['queue_workers'] > 0) text-info @else text-error @endif">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                            </path>
                        </svg>
                    </div>
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
                <div class="stat-title text-xs font-medium truncate">Queue Workers</div>
                <div class="stat-value text-lg truncate @if ($stats['queue_workers'] > 0) text-info @else text-error @endif">
                    {{ $stats['queue_workers'] }}
                </div>
                <div class="stat-desc text-xs truncate">
                    {{ $stats['pending_jobs'] }} job{{ $stats['pending_jobs'] > 1 ? 's' : '' }} en attente
                    @if ($stats['failed_jobs'] > 0)
                        · <span class="text-error">{{ $stats['failed_jobs'] }}
                            échoué{{ $stats['failed_jobs'] > 1 ? 's' : '' }}</span>
                    @endif
                </div>
                <div class="stat-actions text-xs truncate">
                    <a href="{{ route('app.workers.index') }}" class="link link-hover">Voir les workers</a>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
            <!-- Utilisateurs -->
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body">
                    <a href="{{ route('app.users') }}" class="card-title text-primary hover:underline w-fit">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z">
                            </path>
                        </svg>
                        Utilisateurs
                    </a>
                    <div class="mt-2 space-y-1.5 text-sm">
                        <a href="{{ route('app.users') }}?tab=users"
                            class="flex items-center justify-between hover:text-primary">
                            <span class="text-base-content/70">Comptes actifs</span>
                            <span class="font-semibold">{{ $stats['active_users'] }}</span>
                        </a>
                        <a href="{{ route('app.users') }}?tab=users"
                            class="flex items-center justify-between hover:text-primary">
                            <span class="text-base-content/70">Comptes au total</span>
                            <span class="font-semibold">{{ $stats['total_users'] }}</span>
                        </a>
                        <a href="{{ route('app.users') }}?tab=groups"
                            class="flex items-center justify-between hover:text-primary">
                            <span class="text-base-content/70">Groupes</span>
                            <span class="font-semibold">{{ $stats['user_groups_count'] }}</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Machines -->
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body">
                    <a href="{{ route('app.parc.index') }}?tab=machines"
                        class="card-title text-success hover:underline w-fit">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                            </path>
                        </svg>
                        Machines
                    </a>
                    <div class="mt-2 space-y-1.5 text-sm">
                        <a href="{{ route('app.parc.index') }}?tab=machines&presenceFilter=online"
                            class="flex items-center justify-between hover:text-success">
                            <span class="text-base-content/70">En ligne</span>
                            <span class="font-semibold">{{ $stats['online_machines'] }}</span>
                        </a>
                        <a href="{{ route('app.parc.index') }}?tab=machines&presenceFilter=off"
                            class="flex items-center justify-between hover:text-success">
                            <span class="text-base-content/70">Hors ligne</span>
                            <span class="font-semibold">{{ $stats['total_machines'] - $stats['online_machines'] }}</span>
                        </a>
                        <a href="{{ route('app.parc.index') }}?tab=machines"
                            class="flex items-center justify-between hover:text-success">
                            <span class="text-base-content/70">Inventoriées</span>
                            <span class="font-semibold">{{ $stats['total_machines'] }}</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Parcs -->
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body">
                    <a href="{{ route('app.parc.index') }}" class="card-title text-success hover:underline w-fit">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                            </path>
                        </svg>
                        Parcs
                    </a>
                    <div class="mt-2 space-y-1.5 text-sm">
                        <a href="{{ route('app.parc.index') }}?tab=groups"
                            class="flex items-center justify-between hover:text-success">
                            <span class="text-base-content/70">Groupes de postes</span>
                            <span class="font-semibold">{{ $stats['groups_count'] }}</span>
                        </a>
                        <a href="{{ route('app.parc.index') }}?tab=machines"
                            class="flex items-center justify-between hover:text-success">
                            <span class="text-base-content/70">Machines</span>
                            <span class="font-semibold">{{ $stats['total_machines'] }}</span>
                        </a>
                        <a href="{{ route('app.parc.index') }}?tab=printers"
                            class="flex items-center justify-between hover:text-success">
                            <span class="text-base-content/70">Imprimantes</span>
                            <span class="font-semibold">{{ $stats['printers_count'] }}</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Applications -->
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body">
                    <a href="{{ route('app.parc-settings.index') }}" class="card-title text-warning hover:underline w-fit">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4">
                            </path>
                        </svg>
                        Applications
                    </a>
                    <div class="mt-2 space-y-1.5 text-sm">
                        <a href="{{ route('app.parc-settings.index') }}?tab=depot"
                            class="flex items-center justify-between hover:text-warning">
                            <span class="text-base-content/70">Dépôt{{ $stats['depots_count'] > 1 ? 's' : '' }}</span>
                            <span class="font-semibold">{{ $stats['depots_count'] }}</span>
                        </a>
                        <a href="{{ route('app.parc-settings.index') }}?tab=applications"
                            class="flex items-center justify-between hover:text-warning">
                            <span class="text-base-content/70">Applications</span>
                            <span class="font-semibold">{{ $stats['applications_count'] }}</span>
                        </a>
                        <a href="{{ route('app.parc-settings.index') }}?tab=profiles"
                            class="flex items-center justify-between hover:text-warning">
                            <span class="text-base-content/70">Profils applicatifs</span>
                            <span class="font-semibold">{{ $stats['app_profiles_count'] }}</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Raccourcis -->
            <div class="card bg-base-100 shadow-sm border border-base-300">
                <div class="card-body">
                    <a href="{{ route('app.parc-settings.index', ['tab' => 'shortcuts']) }}" class="card-title text-info hover:underline w-fit">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14">
                            </path>
                        </svg>
                        Raccourcis
                    </a>
                    <div class="mt-2 space-y-1.5 text-sm">
                        <a href="{{ route('app.parc-settings.index', ['tab' => 'shortcuts', 'place' => 'desktop']) }}"
                            class="flex items-center justify-between hover:text-info">
                            <span class="text-base-content/70">Bureau</span>
                            <span class="font-semibold">{{ $stats['shortcuts_desktop_count'] }}</span>
                        </a>
                        <a href="{{ route('app.parc-settings.index', ['tab' => 'shortcuts', 'place' => 'startup']) }}"
                            class="flex items-center justify-between hover:text-info">
                            <span class="text-base-content/70">Démarrage</span>
                            <span class="font-semibold">{{ $stats['shortcuts_startup_count'] }}</span>
                        </a>
                        <a href="{{ route('app.parc-settings.index', ['tab' => 'shortcuts', 'place' => 'taskbar']) }}"
                            class="flex items-center justify-between hover:text-info">
                            <span class="text-base-content/70">Barre des tâches</span>
                            <span class="font-semibold">{{ $stats['shortcuts_taskbar_count'] }}</span>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-6">
            <!-- Activité récente -->
            <a href="{{ route('app.dashboard.activity') }}"
                class="card bg-base-100 shadow-sm border border-base-300 hover:border-primary transition-colors">
                <div class="card-body">
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="card-title">Activité Récente</h2>
                        <span class="text-xs text-base-content/60 flex items-center gap-1">
                            Voir tout <i class="fa-solid fa-chevron-right text-[10px]"></i>
                        </span>
                    </div>
                    <div class="space-y-3">
                        @forelse ($recentActivity as $activity)
                            <x-molecules.user-activity-item :initials="$activity['initials']" :name="$activity['name']" :action="$activity['action']"
                                :time-ago="$activity['time_ago']" :color="$activity['color']" />
                        @empty
                            <p class="text-sm text-base-content/50 italic">Aucune activité récente</p>
                        @endforelse
                    </div>
                </div>
            </a>
        </div>
    </div>
</x-organisms.page>
