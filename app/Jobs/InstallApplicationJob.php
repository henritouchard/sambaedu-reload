<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Enums\InstallationStatus;
use App\Models\Application;
use App\Models\DepotApplication;
use App\Models\InstallationLog;
use App\Services\AppStore\AppStoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 8.2.7 — Installation WPKG au catalogue en tâche de fond.
 *
 * Fine enveloppe asynchrone autour de
 * {@see AppStoreService::installApplication()} : ce Job NE réimplémente RIEN
 * du flow d'installation (download XML → vérif hash → multi-download →
 * post-traitement → finalisation → régénération packages.xml). Il se contente
 * de re-`find()` la `DepotApplication` puis de déléguer au service, qui gère
 * déjà tout le cycle de vie (`InstallationLog` + `Application`) dans son
 * `try/catch`.
 *
 * Conception (décisions Story 8.2.7) :
 *  - On passe l'**id** (`int $depotApplicationId`), PAS le modèle entier : une
 *    row stale ou un `firstOrCreate`/sync entre dispatch et pickup rend l'id
 *    plus sûr (cf. {@see \App\Ipxe\Iso\Jobs\DownloadWindowsIsoJob} qui prend
 *    `public readonly int $downloadId`).
 *  - File `default` : déjà servie par `laravel-queue-worker` +
 *    `laravel-queue-general` (parallélisme ≥ 2 sans action OPS).
 *  - `$timeout` cohérent avec un package multi-fichiers (N × download_timeout)
 *    + marge — voir la propriété `$timeout`.
 *  - `WithoutOverlapping` keyé sur l'`app_id` du dépôt : empêche deux jobs
 *    concurrents sur la **même** app (défense en profondeur vs le risque de
 *    double-pickup `retry_after=90s` < `$timeout`). L'idempotence de
 *    `installApplication()` (`Application::firstOrCreate`) reste le garde-fou
 *    de fond.
 *  - `failed()` : garde-fou idempotent qui passe en `Failed` TOUS les logs
 *    non-terminaux de l'app (le service crée
 *    un log par tentative, sans corrélation 1:1 Job↔log) — `inProgress()`
 *    exclut déjà les états terminaux, donc on ne réécrit jamais un `Success`.
 */
class InstallApplicationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Réseau lent/intermittent : 3 tentatives.
     *
     * L'op est idempotente (`Application::firstOrCreate` + skip-by-hash des
     * fichiers déjà téléchargés), donc un retry ne re-télécharge pas tout.
     */
    public int $tries = 3;

    /**
     * Backoff entre tentatives (secondes).
     */
    public int $backoff = 30;

    /**
     * Timeout global du Job (secondes).
     *
     * Un package peut comporter plusieurs fichiers, chacun plafonné par
     * `WPKG_DOWNLOAD_TIMEOUT` (`sambaedu.wpkg.download_timeout`, 300s par
     * défaut). On dimensionne pour ~12 fichiers + marge — bien au-delà du
     * `retry_after=90s` de la connexion `database` (`config/queue.php`).
     *
     * ⚠ `retry_after (90s) < $timeout` : si un job tournait plus de 90s, un
     * autre worker pourrait le re-prendre (double-pickup). C'est précisément
     * ce que neutralise le `WithoutOverlapping` par `app_id` (un seul job en
     * vol par app) — l'idempotence `firstOrCreate` restant le filet de fond.
     */
    public int $timeout;

    public function __construct(
        public readonly int $depotApplicationId,
        public readonly string $initiatedBy,
    ) {
        $this->onQueue('default');

        $downloadTimeout = (int) config('sambaedu.wpkg.download_timeout', 300);
        // ~12 fichiers max raisonnable par recipe + 300s de marge globale
        // (overhead HTTP, parsing XML, post-traitement, finalisation).
        $this->timeout = ($downloadTimeout * 12) + 300;
    }

    /**
     * Défense en profondeur : un seul job par app concurrente.
     *
     * Key par `app_id` du dépôt (et non par row id) : c'est l'identité
     * fonctionnelle de l'application installée côté `applications.app_id`. On
     * `releaseAfter()` plutôt que `dontRelease()` : si un autre job sur la même
     * app est en vol, on préfère remettre celui-ci en file (l'utilisateur a pu
     * relancer) plutôt que de le perdre.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $depotApp = DepotApplication::find($this->depotApplicationId);
        // Si la row a disparu entre dispatch et pickup, on retombe sur l'id
        // pour garder une key déterministe (handle() loggera + return).
        $key = $depotApp?->app_id ?? ('depot-app-' . $this->depotApplicationId);

        return [
            (new WithoutOverlapping('appstore.install.' . $key))
                ->releaseAfter($this->backoff)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(AppStoreService $appStoreService): void
    {
        $depotApp = DepotApplication::find($this->depotApplicationId);

        if ($depotApp === null) {
            Log::warning('[AppStore] InstallApplicationJob: DepotApplication introuvable', [
                'depot_application_id' => $this->depotApplicationId,
                'initiated_by' => $this->initiatedBy,
            ]);

            return;
        }

        $appStoreService->installApplication($depotApp, $this->initiatedBy);
    }

    /**
     * Garde-fou idempotent appelé après épuisement des `$tries` ou sur une
     * exception hors `try` de `installApplication()`.
     *
     * Le `catch` de `installApplication()` couvre déjà le cas nominal (le log
     * de la tentative en échec passe `Failed` + app `Error`). Ici, on rattrape
     * le cas où un log a pu rester NON-terminal (worker tué mid-job, exception
     * hors `try`). On marque en `Failed` **tous** les logs non-terminaux de
     * l'app — et pas seulement le dernier : `installApplication()` crée un log
     * par tentative sans corrélation 1:1 Job↔log, donc ne rattraper que le plus
     * récent laisserait des logs « in progress » fantômes que le panneau de
     * progression (poll 3s) afficherait indéfiniment. `inProgress()` excluant
     * déjà `Success`/`Failed`, l'opération ne réécrit JAMAIS un log terminal
     * (idempotente par construction).
     */
    public function failed(?Throwable $exception): void
    {
        $depotApp = DepotApplication::find($this->depotApplicationId);
        if ($depotApp === null) {
            return;
        }

        $application = Application::where('app_id', $depotApp->app_id)->first();
        if ($application === null) {
            return;
        }

        $nonTerminalLogs = InstallationLog::query()
            ->where('application_id', $application->id)
            ->inProgress()
            ->get();

        if ($nonTerminalLogs->isEmpty()) {
            // Rien à rattraper (déjà Success/Failed par le catch du service).
            return;
        }

        foreach ($nonTerminalLogs as $log) {
            $log->update([
                'status' => InstallationStatus::Failed,
                'message' => 'Echec definitif du job: '
                    . ($exception?->getMessage() ?? 'Job en echec sans exception.'),
                'completed_at' => now(),
            ]);
        }

        $application->update(['status' => ApplicationStatus::Error]);

        Log::error('[AppStore] InstallApplicationJob en echec definitif', [
            'depot_application_id' => $this->depotApplicationId,
            'app_id' => $depotApp->app_id,
            'logs_failed' => $nonTerminalLogs->count(),
            'error' => $exception?->getMessage(),
        ]);
    }
}
