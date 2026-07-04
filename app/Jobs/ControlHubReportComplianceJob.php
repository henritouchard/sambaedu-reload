<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ControlHub\ControlHubComplianceReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Story 39.2 (canal ③) — Job FIN d'émission du rapport de conformité amont.
 *
 * Se contente d'appeler {@see ControlHubComplianceReportService::emit()} : toute la
 * logique (construction d'enveloppe, gardes NFR-A1, POST HTTPS) vit dans le service.
 * Le job existe UNIQUEMENT pour offrir le retry automatique Laravel sur échec HTTP
 * transitoire (`$tries = 3`) sans bloquer le tick du scheduler. Traité par le worker
 * `laravel-queue-general` déjà en place (queue par défaut — patron
 * {@see DispatchMachinePowerActionJob}).
 *
 * ⚠️ NE reproduit PAS le patron d'auto-redispatch de `ControlHubHeartbeatJob`
 * (Cache::put + static::dispatch()->delay) — code MORT jamais branché. La cadence
 * est portée par la commande `controlhub:report-compliance` (intervalle fixe).
 */
class ControlHubReportComplianceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Retry automatique sur échec HTTP transitoire (voir handle() : `http_error` relève). */
    public int $tries = 3;

    /** Espacement des retries (secondes) — laisse le temps à un endpoint amont transitoirement KO de revenir. */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ControlHubComplianceReportService $service): void
    {
        $result = $service->emit();

        Log::info('ControlHub Compliance Job — émission traitée', [
            'sent' => $result['sent'],
            'reason' => $result['reason'],
            'items' => $result['items'] ?? null,
        ]);

        // Review 39.2 #1 — `ControlHubApiClient` avale les exceptions HTTP et renvoie
        // un `ApiResponse::failed()` : sans relever ici, `handle()` se termine
        // normalement → Laravel considère le job réussi et `$tries` ne s'arme JAMAIS
        // (retry AC7 mort). On relève DONC sur échec HTTP transitoire pour engager le
        // retry natif (backoff ci-dessus). Ré-émission SÛRE : le rapport est
        // état-intégral idempotent + garde de fraîcheur `reported_at` côté amont
        // (NFR-A2). Les gardes NFR-A1 (`no_active_contract`/`no_active_connection`/
        // `no_token`) NE relèvent PAS : ce sont des no-op légitimes, pas des échecs.
        if (($result['reason'] ?? null) === 'http_error') {
            throw new \RuntimeException(sprintf(
                'Émission de conformité amont échouée (HTTP %s) — retry Laravel engagé.',
                $result['http_status'] ?? '?',
            ));
        }
    }
}
