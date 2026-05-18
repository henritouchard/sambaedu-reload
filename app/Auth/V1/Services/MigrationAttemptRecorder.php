<?php

declare(strict_types=1);

namespace App\Auth\V1\Services;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Story 16.11 — Q2 (correction post-review Opus-B + Opus-D, 2026-05-18).
 *
 * Helper injectable qui insère une row `workstation_migration_attempts` avec
 * `status='failed'` chaque fois qu'un middleware ou controller rejette une
 * tentative de migration auto-bootstrap.
 *
 * **Pourquoi un service plutôt qu'un trait** :
 *
 *  - Testabilité : on peut binder un mock en container et asserter les
 *    invocations.
 *  - Injection DI automatique côté middlewares et controller.
 *  - Pas de couplage statique — le caller `EnrollController` reste fin.
 *
 * **Best-effort strict** : toute exception lors de l'insertion est capturée
 * et loggée (`error`). Le caller continue son flow d'erreur sans dépendre
 * du succès de la traçabilité (pas de double erreur 500 sur incident DB).
 *
 * **Champs renseignés** :
 *
 *  - `workstation_uuid` (nullable — si le poste n'a pas déclaré d'uuid valide).
 *  - `started_at` = `finished_at` = now (tentative atomique côté serveur).
 *  - `status` = `WorkstationMigrationAttempt::STATUS_FAILED`.
 *  - `error_code` = code symbolique (typage `JwtErrorCodes` ou domaine).
 *  - `error_message` = message court (mutator truncate à 1024 chars).
 *  - `client_ip` = `$request->server('REMOTE_ADDR')` (non-spoofable via XFF).
 *  - `user_agent` = `$request->userAgent()` tronqué 1KB.
 *  - `os` = nullable (uniquement si fourni par le caller ou détectable
 *    via UA — par défaut on laisse null).
 *
 * **Sites d'invocation actuels (2026-05-18)** :
 *
 *  - `App\Auth\V1\Http\Middleware\RequireBootstrapToken` — 3 cas d'échec
 *    (missing / invalid / uuid_mismatch).
 *  - `App\Auth\V1\Http\Middleware\EnsureLanIp` — 1 cas (not_lan).
 *  - `App\Auth\V1\Http\Controllers\EnrollController` — paths d'erreur PKI.
 *
 * @see \App\Console\Commands\MigrationHealthCheck Commande consommant les rows.
 */
class MigrationAttemptRecorder
{
    /**
     * Enregistre une tentative échouée.
     *
     * @param Request     $request       Requête HTTP courante.
     * @param string      $errorCode     Code d'erreur (typiquement constante `JwtErrorCodes::*`).
     * @param string|null $declaredUuid  UUID déclaré par le poste (nullable si absent/invalide).
     * @param string|null $errorMessage  Message libre court (sera tronqué à 1024 chars par le mutator).
     * @param string|null $os            OS du poste (windows|linux) si connu, sinon null.
     */
    public function recordFailure(
        Request $request,
        string $errorCode,
        ?string $declaredUuid = null,
        ?string $errorMessage = null,
        ?string $os = null,
    ): void {
        try {
            $now = Carbon::now();

            WorkstationMigrationAttempt::create([
                'workstation_uuid' => $declaredUuid,
                'started_at' => $now,
                'finished_at' => $now,
                'status' => WorkstationMigrationAttempt::STATUS_FAILED,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'client_ip' => (string) ($request->server('REMOTE_ADDR', '') ?? $request->ip() ?? '127.0.0.1'),
                'user_agent' => substr((string) $request->userAgent(), 0, 1024),
                'os' => $os,
            ]);
        } catch (\Throwable $e) {
            Log::channel('auth-v1')->error(
                '[MigrationAttemptRecorder] auth.migration.attempt.persist_failed',
                [
                    'action_type' => 'auth.migration.attempt.persist_failed',
                    'error_code' => $errorCode,
                    'error_class' => $e::class,
                    'error' => $e->getMessage(),
                ],
            );
        }
    }
}
