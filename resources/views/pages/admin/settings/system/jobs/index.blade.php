<?php

use App\Components\Traits\WithToasts;
use App\Gpo\Support\GpoLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Page Livewire SFC — Dashboard Jobs récents GPO/WPKG — Story 16.14 E.
 *
 * Affiche les jobs pending/running (table `jobs`) et failed (table `failed_jobs`)
 * filtrés sur les classes GPO et WPKG (D14 — large).
 *
 * Polling 5s automatique (AC5.3).
 * Actions : retry (failed) + cancel (pending non réservé) (AC5.4/5.5).
 *
 * Route : GET /admin/settings/system/jobs (admin.system.jobs.index).
 * Permission : can:server.admin.
 *
 * Note D15 : si le driver queue n'est pas `database`, les tables `jobs`/`failed_jobs`
 * peuvent être absentes → afficher un encart explicatif.
 */
new #[Title('Jobs système - SE4FS')] class extends Component {
    use WithToasts;

    /** @var list<array> Jobs pending/running (slice de la page courante). */
    public array $pendingJobs = [];

    /** @var list<array> Jobs échoués (slice de la page courante). */
    public array $failedJobs = [];

    /** Nombre total de jobs récents (avant pagination). */
    public int $totalJobs = 0;

    /** Story 16.14 Q4 — totaux avant slice (AC5.2 — pagination 20/page). */
    public int $totalPending = 0;
    public int $totalFailed = 0;

    /** Si le driver queue ne supporte pas les tables DB. */
    public bool $driverUnsupported = false;
    public string $queueDriver = '';

    /**
     * Story 16.14 Q4 — pagination AC5.2 (20 par page).
     * Pages séparées pour pending et failed (deux tableaux indépendants).
     */
    public int $pendingPage = 1;
    public int $failedPage = 1;
    public int $perPage = 20;

    /**
     * Préfixes de classes de jobs surveillées (D14 — large).
     * On surveille uniquement les jobs GPO (App\Gpo\Jobs\*).
     * Note : App\Wpkg n'a pas encore de Jobs natifs (pas de sous-espace App\Wpkg\Jobs\).
     * À compléter ici quand les jobs WPKG natifs seront créés (ex: App\Wpkg\Jobs\WpkgRepublishJob).
     */
    private const WATCHED_CLASS_PREFIXES = [
        'App\\Gpo\\Jobs\\',
        // 'App\\Wpkg\\Jobs\\',  // À décommenter quand les jobs WPKG natifs existeront
    ];

    public function mount(): void
    {
        abort_unless(
            auth()->check() && auth()->user()->can('server.admin'),
            403,
            'Permission server.admin requise.',
        );

        $this->queueDriver = config('queue.default', 'sync');

        if (!$this->isDatabaseDriver()) {
            $this->driverUnsupported = true;
            return;
        }

        $this->refreshJobs();
    }

    private function isDatabaseDriver(): bool
    {
        return $this->queueDriver === 'database';
    }

    public function refreshJobs(): void
    {
        if ($this->driverUnsupported) {
            return;
        }

        try {
            // Jobs pending/running — récupérer puis filtrer en mémoire (le payload
            // est un JSON encodé, filtrer en SQL = LIKE peu fiable).
            $allPending = DB::table('jobs')
                ->orderBy('created_at', 'desc')
                ->get()
                ->filter(fn($job) => $this->isWatchedJob($job->payload))
                ->map(fn($job) => [
                    'id'                    => $job->id,
                    'class'                 => $this->extractClassName($job->payload),
                    'queue'                 => $job->queue,
                    'status'                => $job->reserved_at ? 'running' : 'pending',
                    'attempts'              => $job->attempts,
                    'created_at'            => $job->created_at,
                    'reserved_at'           => $job->reserved_at,
                    'formatted_created_at'  => $job->created_at
                        ? Carbon::createFromTimestamp((int) $job->created_at)->format('d/m/Y H:i:s')
                        : '—',
                ])
                ->values()
                ->all();

            // Jobs échoués
            $allFailed = DB::table('failed_jobs')
                ->orderBy('failed_at', 'desc')
                ->take(500) // cap remonté à 500 — pagination affiche par tranches de 20
                ->get()
                ->filter(fn($job) => $this->isWatchedJob($job->payload))
                ->map(fn($job) => [
                    'id'                => $job->id,
                    'uuid'              => $job->uuid,
                    'class'             => $this->extractClassName($job->payload),
                    'queue'             => $job->queue,
                    'status'            => 'failed',
                    'attempts'          => null,
                    'failed_at'         => $job->failed_at,
                    'formatted_failed_at' => $job->failed_at
                        ? Carbon::parse($job->failed_at)->format('d/m/Y H:i:s')
                        : '—',
                    'exception'         => $this->summarizeException($job->exception ?? ''),
                ])
                ->values()
                ->all();

            // Story 16.14 Q4 — pagination 20/page (AC5.2).
            $this->totalPending = count($allPending);
            $this->totalFailed = count($allFailed);
            $this->totalJobs = $this->totalPending + $this->totalFailed;

            // Clamp pages dans les bornes valides (utile après suppressions).
            $lastPendingPage = max(1, (int) ceil($this->totalPending / $this->perPage));
            $lastFailedPage  = max(1, (int) ceil($this->totalFailed  / $this->perPage));
            $this->pendingPage = max(1, min($this->pendingPage, $lastPendingPage));
            $this->failedPage  = max(1, min($this->failedPage,  $lastFailedPage));

            $this->pendingJobs = array_slice(
                $allPending,
                ($this->pendingPage - 1) * $this->perPage,
                $this->perPage,
            );
            $this->failedJobs = array_slice(
                $allFailed,
                ($this->failedPage - 1) * $this->perPage,
                $this->perPage,
            );

        } catch (\Throwable $e) {
            // Tables jobs/failed_jobs absentes (driver non database)
            $this->driverUnsupported = true;
            $this->pendingJobs = [];
            $this->failedJobs = [];
            $this->totalJobs = 0;
            $this->totalPending = 0;
            $this->totalFailed = 0;
        }
    }

    /**
     * Story 16.14 Q4 — navigation pagination pending.
     */
    public function goToPendingPage(int $page): void
    {
        $lastPage = max(1, (int) ceil($this->totalPending / $this->perPage));
        $this->pendingPage = max(1, min($page, $lastPage));
        $this->refreshJobs();
    }

    /**
     * Story 16.14 Q4 — navigation pagination failed.
     */
    public function goToFailedPage(int $page): void
    {
        $lastPage = max(1, (int) ceil($this->totalFailed / $this->perPage));
        $this->failedPage = max(1, min($page, $lastPage));
        $this->refreshJobs();
    }

    /**
     * Story 16.14 Q4 — helpers vue : nombre total de pages.
     */
    public function getPendingLastPageProperty(): int
    {
        return max(1, (int) ceil($this->totalPending / $this->perPage));
    }

    public function getFailedLastPageProperty(): int
    {
        return max(1, (int) ceil($this->totalFailed / $this->perPage));
    }

    /**
     * Vérifie si le payload JSON d'un job correspond aux classes surveillées (D14).
     */
    private function isWatchedJob(string $payload): bool
    {
        // Le `displayName` Laravel est sérialisé en JSON : chaque `\` du FQCN
        // PHP devient `\\` dans la chaîne stockée. Décoder avant comparaison
        // pour matcher de façon fiable, indépendamment de l'encodage JSON.
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return false;
        }
        $candidates = [
            $decoded['displayName'] ?? null,
            $decoded['job'] ?? null,
            $decoded['data']['commandName'] ?? null,
        ];
        foreach ($candidates as $name) {
            if (!is_string($name)) {
                continue;
            }
            foreach (self::WATCHED_CLASS_PREFIXES as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Extrait le nom de classe humanisé depuis le payload JSON.
     */
    private function extractClassName(string $payload): string
    {
        try {
            $data = json_decode($payload, true);
            $displayName = $data['displayName'] ?? null;
            if ($displayName) {
                // Extraire la partie après le dernier backslash
                $parts = explode('\\', $displayName);
                return end($parts);
            }
            // Fallback : chercher dans le champ job
            $jobField = $data['job'] ?? $data['data']['commandName'] ?? $data['data']['command'] ?? $payload;
            if (is_string($jobField)) {
                $parts = explode('\\', $jobField);
                return end($parts);
            }
        } catch (\Throwable) {
        }
        return 'Job inconnu';
    }

    private function summarizeException(string $exception): string
    {
        $lines = explode("\n", $exception);
        return trim($lines[0] ?? 'Erreur inconnue');
    }

    /**
     * Retry un job échoué (AC5.4).
     */
    public function retryJob(string $uuid): void
    {
        $log = GpoLogger::action('gpo.job.retry', context: [
            'job_uuid' => $uuid,
            'actor_user_id' => auth()->id(),
        ]);

        try {
            Artisan::call('queue:retry', ['id' => [$uuid]]);
            $log->success(['outcome' => 'success']);
            $this->toastSuccess('Job remis en queue');
            $this->refreshJobs();
        } catch (\Throwable $e) {
            $log->failure($e);
            $this->toast('error', 'Erreur', 'Impossible de relancer le job : ' . $e->getMessage());
        }
    }

    /**
     * Annule un job pending (AC5.5).
     */
    public function cancelJob(int $jobId): void
    {
        $log = GpoLogger::action('gpo.job.cancel', context: [
            'job_id' => $jobId,
            'actor_user_id' => auth()->id(),
        ]);

        try {
            $deleted = DB::table('jobs')
                ->where('id', $jobId)
                ->whereNull('reserved_at')
                ->delete();

            if ($deleted > 0) {
                $log->success(['outcome' => 'success', 'deleted' => $deleted]);
                $this->toastSuccess('Job annulé');
            } else {
                // Job déjà réservé par un worker : échec sémantique pour l'audit log
                $log->failure(new \RuntimeException('Job already reserved by worker, cannot cancel'));
                $this->toast('warning', 'Avertissement', 'Le job était déjà en cours, annulation impossible');
            }
            $this->refreshJobs();
        } catch (\Throwable $e) {
            $log->failure($e);
            $this->toast('error', 'Erreur', 'Impossible d\'annuler le job : ' . $e->getMessage());
        }
    }

    /** Formate un timestamp Unix en chaîne lisible. */
    public function formatTimestamp(?int $ts): string
    {
        if ($ts === null) {
            return '—';
        }
        return date('d/m/Y H:i:s', $ts);
    }
};
?>

<x-organisms.page title="Jobs système" :scrollable="true"
    description="Dashboard des jobs asynchrones GPO et WPKG (wine image, republication WPKG).">

    {{-- Polling 5s (AC5.3) --}}
    <div wire:poll.5s="refreshJobs">

        <div class="space-y-4">

            {{-- Breadcrumb --}}
            <div class="text-sm breadcrumbs">
                <ul>
                    <li><span class="text-base-content/60">Réglages</span></li>
                    <li><span class="text-base-content/60">Système</span></li>
                    <li class="text-base-content/80">Jobs récents</li>
                </ul>
            </div>

            {{-- Indicateur polling actif (AC5.3) --}}
            <div class="flex items-center gap-2 text-xs text-base-content/50" data-testid="polling-indicator">
                <span class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-success opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-success"></span>
                </span>
                Rafraîchissement automatique toutes les 5 secondes
            </div>

            {{-- Driver non supporté (D15) --}}
            @if ($driverUnsupported)
                <div class="alert alert-warning shadow-sm" data-testid="driver-unsupported-alert">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <p class="font-medium">Driver queue actuel = <code class="font-mono">{{ $queueDriver }}</code></p>
                        <p class="text-sm opacity-80">
                            Le dashboard nécessite le driver <code class="font-mono">database</code> pour fonctionner pleinement.
                            Les tables <code class="font-mono">jobs</code> et <code class="font-mono">failed_jobs</code> ne sont pas disponibles.
                        </p>
                    </div>
                </div>
            @else
                {{-- Compteur global --}}
                <div class="flex items-center gap-3">
                    <span class="text-sm font-medium text-base-content/70">
                        <span class="font-bold text-base-content" data-testid="total-jobs-count">{{ $totalJobs }}</span>
                        job{{ $totalJobs !== 1 ? 's' : '' }} récent{{ $totalJobs !== 1 ? 's' : '' }}
                    </span>
                    @if (count($failedJobs) > 0)
                        <span class="badge badge-error badge-sm">
                            {{ count($failedJobs) }} échoué{{ count($failedJobs) > 1 ? 's' : '' }}
                        </span>
                    @endif
                    @if (count($pendingJobs) > 0)
                        <span class="badge badge-info badge-sm">
                            {{ count($pendingJobs) }} en file
                        </span>
                    @endif
                </div>

                {{-- Jobs pending / running --}}
                <div>
                    <h3 class="text-sm font-semibold mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-hourglass-half text-info text-xs"></i>
                        En file / En cours
                    </h3>

                    @if (empty($pendingJobs))
                        <div class="card bg-base-100 border border-base-300 shadow-sm">
                            <div class="card-body py-4 text-center text-base-content/50 text-sm" data-testid="no-pending-jobs">
                                Aucun job en attente ou en cours.
                            </div>
                        </div>
                    @else
                        <div class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="table table-sm" data-testid="pending-jobs-table">
                                    <thead>
                                        <tr>
                                            <th>Job</th>
                                            <th>Queue</th>
                                            <th>Statut</th>
                                            <th>Tentatives</th>
                                            <th>Créé le</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($pendingJobs as $job)
                                            <tr>
                                                <td class="font-mono text-sm font-medium">{{ $job['class'] }}</td>
                                                <td class="text-xs text-base-content/60">{{ $job['queue'] }}</td>
                                                <td>
                                                    @if ($job['status'] === 'running')
                                                        <span class="badge badge-warning badge-sm" data-testid="job-status-running">
                                                            <span class="loading loading-ring loading-xs mr-1"></span>
                                                            En cours
                                                        </span>
                                                    @else
                                                        <span class="badge badge-info badge-sm" data-testid="job-status-pending">
                                                            En attente
                                                        </span>
                                                    @endif
                                                </td>
                                                <td class="text-sm">{{ $job['attempts'] ?? 0 }}</td>
                                                <td class="text-xs text-base-content/60">{{ $job['formatted_created_at'] ?? '—' }}</td>
                                                <td>
                                                    @if ($job['status'] === 'pending')
                                                        <button type="button"
                                                            class="btn btn-ghost btn-xs text-error hover:bg-error/10"
                                                            wire:click="cancelJob({{ $job['id'] }})"
                                                            wire:loading.attr="disabled"
                                                            data-testid="cancel-job-btn-{{ $job['id'] }}">
                                                            <i class="fa-solid fa-xmark text-xs"></i>
                                                            Annuler
                                                        </button>
                                                    @else
                                                        <span class="text-base-content/30 text-xs">—</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            {{-- Story 16.14 Q4 — Pagination pending 20/page (AC5.2) --}}
                            @php
                                $pendingLastPage = $this->getPendingLastPageProperty();
                                $pendingFrom = $totalPending > 0 ? ($pendingPage - 1) * $perPage + 1 : 0;
                                $pendingTo = min($pendingPage * $perPage, $totalPending);
                            @endphp
                            @if ($pendingLastPage > 1)
                                <div data-testid="pending-pagination">
                                    <x-molecules.pagination
                                        :currentPage="$pendingPage"
                                        :lastPage="$pendingLastPage"
                                        :total="$totalPending"
                                        :from="$pendingFrom"
                                        :to="$pendingTo"
                                        :perPage="$perPage"
                                        :showPerPage="false"
                                        onPageChange="goToPendingPage"
                                        itemLabel="job"
                                        itemLabelPlural="jobs" />
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                {{-- Jobs échoués --}}
                <div>
                    <h3 class="text-sm font-semibold mb-2 flex items-center gap-2">
                        <i class="fa-solid fa-circle-exclamation text-error text-xs"></i>
                        Échoués
                    </h3>

                    @if (empty($failedJobs))
                        <div class="card bg-base-100 border border-base-300 shadow-sm">
                            <div class="card-body py-4 text-center text-base-content/50 text-sm" data-testid="no-failed-jobs">
                                Aucun job échoué.
                            </div>
                        </div>
                    @else
                        <div class="card bg-base-100 border border-base-300 shadow-sm overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="table table-sm" data-testid="failed-jobs-table">
                                    <thead>
                                        <tr>
                                            <th>Job</th>
                                            <th>Queue</th>
                                            <th>Statut</th>
                                            <th>Erreur</th>
                                            <th>Échoué le</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($failedJobs as $job)
                                            <tr>
                                                <td class="font-mono text-sm font-medium">{{ $job['class'] }}</td>
                                                <td class="text-xs text-base-content/60">{{ $job['queue'] }}</td>
                                                <td>
                                                    <span class="badge badge-error badge-sm" data-testid="job-status-failed">
                                                        Échoué
                                                    </span>
                                                </td>
                                                <td>
                                                    <x-atoms.tooltip position="top">
                                                        <x-slot name="trigger">
                                                            <span class="text-xs text-error/80 truncate max-w-xs block">
                                                                {{ $job['exception'] }}
                                                            </span>
                                                        </x-slot>
                                                        <span class="text-xs font-mono">{{ $job['exception'] }}</span>
                                                    </x-atoms.tooltip>
                                                </td>
                                                <td class="text-xs text-base-content/60">{{ $job['formatted_failed_at'] ?? '—' }}</td>
                                                <td>
                                                    <button type="button"
                                                        class="btn btn-ghost btn-xs text-primary hover:bg-primary/10"
                                                        wire:click="retryJob('{{ $job['uuid'] }}')"
                                                        wire:loading.attr="disabled"
                                                        data-testid="retry-job-btn-{{ $job['id'] }}">
                                                        <i class="fa-solid fa-rotate-right text-xs"></i>
                                                        Retry
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>

                            {{-- Story 16.14 Q4 — Pagination failed 20/page (AC5.2) --}}
                            @php
                                $failedLastPage = $this->getFailedLastPageProperty();
                                $failedFrom = $totalFailed > 0 ? ($failedPage - 1) * $perPage + 1 : 0;
                                $failedTo = min($failedPage * $perPage, $totalFailed);
                            @endphp
                            @if ($failedLastPage > 1)
                                <div data-testid="failed-pagination">
                                    <x-molecules.pagination
                                        :currentPage="$failedPage"
                                        :lastPage="$failedLastPage"
                                        :total="$totalFailed"
                                        :from="$failedFrom"
                                        :to="$failedTo"
                                        :perPage="$perPage"
                                        :showPerPage="false"
                                        onPageChange="goToFailedPage"
                                        itemLabel="job"
                                        itemLabelPlural="jobs" />
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </div>
    </div>
</x-organisms.page>
