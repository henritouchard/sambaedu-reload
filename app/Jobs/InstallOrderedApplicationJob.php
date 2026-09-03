<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ApplicationStatus;
use App\Models\Application;
use App\Services\AppStore\AppStoreService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Pose côté SERVEUR une application ORDONNÉE par le contrat amont.
 *
 * Jumeau de {@see InstallApplicationJob} pour les applications qui n'ont pas de
 * `DepotApplication` derrière elles : la recette vient du catalogue amont, portée
 * par l'`Application` elle-même (`xml_url`/`xml_sha`).
 *
 * Asynchrone parce que l'ingestion d'un contrat est synchrone : tirer les
 * installeurs d'une dizaine d'apps dans le fil de la requête ferait expirer la
 * réception, et un dépôt amont lent bloquerait tout le contrat pour une seule app.
 *
 * Le retry est celui du dépôt distant, pas celui d'une erreur de contrat : trois
 * tentatives espacées, puis on laisse l'application en `Error` — la réception
 * suivante, ou `controlhub:provision-ordered-apps`, redonnera sa chance.
 */
class InstallOrderedApplicationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout;

    public function __construct(
        public readonly int $applicationId,
        public readonly string $initiatedBy = 'controlhub',
    ) {
        $this->onQueue('default');

        $downloadTimeout = (int) config('sambaedu.wpkg.download_timeout', 300);
        $this->timeout = ($downloadTimeout * 12) + 300;
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        $appId = Application::find($this->applicationId)?->app_id
            ?? ('application-' . $this->applicationId);

        // Même clé que InstallApplicationJob : une app ordonnée ET installable
        // depuis un dépôt ne doit pas être tirée deux fois en parallèle.
        return [
            (new WithoutOverlapping('appstore.install.' . $appId))
                ->releaseAfter($this->backoff)
                ->expireAfter($this->timeout),
        ];
    }

    public function handle(AppStoreService $appStoreService): void
    {
        $application = Application::find($this->applicationId);

        if ($application === null) {
            Log::warning('[AppStore] InstallOrderedApplicationJob: Application introuvable', [
                'application_id' => $this->applicationId,
            ]);

            return;
        }

        // Course : un autre chemin (install manuelle, job jumeau) a pu aboutir entre
        // le dispatch et le pickup. Réinstaller ne casserait rien mais re-téléchargerait.
        if ($application->status === ApplicationStatus::Installed) {
            return;
        }

        $appStoreService->installOrderedApplication($application, $this->initiatedBy);
    }
}
