<?php

declare(strict_types=1);

namespace App\ScriptsOs\Http\Controllers;

use App\Http\Controllers\Controller;
use App\ScriptsOs\Http\Requests\IngestScriptExecutionLogRequest;
use App\ScriptsOs\Models\ScriptExecutionLog;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Story 16.12 — AC2.3 / D3 / D11 / D12.
 *
 * Endpoint d'ingestion machine-to-machine. **PAS** d'UX wrapping — réponses
 * Laravel natif :
 *
 *  - 201 noContent sur succès (insert ou idempotent_skip)
 *  - 422 `{message, errors}` sur validation FormRequest
 *  - 401 hérité du middleware `auth.v1.workstation` (16.10) `{error, code, message}`
 *
 * Idempotence (D12) — déduplication 2 niveaux :
 *
 *  1. **Pré-check applicatif** : avant insert, on cherche `(workstation_uuid,
 *     correlation_id)` ; si row existante → 201 silent + log
 *     `scriptsos.ingest.idempotent_skip`.
 *  2. **Catch QueryException race (SQLSTATE 23505 unique_violation)** : si
 *     2 POST concurrents passent simultanément le pré-check, l'unique index
 *     partiel pgsql rejette le 2ème → on catch + 201 silent + log
 *     `scriptsos.ingest.idempotent_skip_race`.
 */
class ScriptExecutionLogIngestionController extends Controller
{
    public function store(IngestScriptExecutionLogRequest $request): Response
    {
        $workstationUuid = $this->extractWorkstationUuid($request);
        $validated = $request->validated();

        $correlationId = $validated['correlation_id'] ?? null;

        // 1. Pré-check idempotence applicatif (D12).
        if ($correlationId !== null && $this->correlationExists($workstationUuid, (string) $correlationId)) {
            Log::channel('scriptsos')->info('scriptsos.ingest.idempotent_skip', [
                'event' => 'scriptsos.ingest.idempotent_skip',
                'workstation_uuid' => $workstationUuid,
                'correlation_id' => $correlationId,
                'action' => $validated['action'] ?? null,
            ]);

            return response()->noContent(201);
        }

        // 2. Insert. Mapping payload → row :
        //  - `stdout` / `stderr` (payload) → `stdout_excerpt` / `stderr_excerpt` (DB)
        //    le mutator Model truncate à 8 KB.
        //  - `reported_at` = timestamp serveur (différent du `started_at` côté poste).
        $attributes = [
            'workstation_uuid' => $workstationUuid,
            'script_id' => $validated['script_id'] ?? null,
            'script_source' => $validated['script_source'],
            'action' => $validated['action'],
            'os' => $validated['os'],
            'status' => $validated['status'],
            'exit_code' => $validated['exit_code'] ?? null,
            'stdout_excerpt' => $validated['stdout'] ?? null,
            'stderr_excerpt' => $validated['stderr'] ?? null,
            'started_at' => Carbon::parse((string) $validated['started_at']),
            'duration_ms' => (int) $validated['duration_ms'],
            'reported_at' => Carbon::now(),
            'correlation_id' => $correlationId,
        ];

        try {
            ScriptExecutionLog::create($attributes);
        } catch (QueryException $e) {
            // Race condition unique violation — un autre POST concurrent
            // (même correlation_id) a gagné. On catch et on retourne 201
            // silent + log distinct pour observabilité.
            if ($this->isUniqueViolation($e)) {
                Log::channel('scriptsos')->info('scriptsos.ingest.idempotent_skip_race', [
                    'event' => 'scriptsos.ingest.idempotent_skip_race',
                    'workstation_uuid' => $workstationUuid,
                    'correlation_id' => $correlationId,
                    'action' => $validated['action'] ?? null,
                    'sqlstate' => $e->errorInfo[0] ?? null,
                ]);

                return response()->noContent(201);
            }

            throw $e;
        }

        Log::channel('scriptsos')->info('scriptsos.ingest.success', [
            'event' => 'scriptsos.ingest.success',
            'workstation_uuid' => $workstationUuid,
            'action' => $validated['action'] ?? null,
            'status' => $validated['status'] ?? null,
            'exit_code' => $validated['exit_code'] ?? null,
            'duration_ms' => $validated['duration_ms'] ?? null,
            'correlation_id' => $correlationId,
        ]);

        return response()->noContent(201);
    }

    /**
     * Extrait le `workstation_uuid` injecté par le middleware
     * `auth.v1.workstation` (16.10). Garde-fou défense en profondeur :
     * si l'attribute est absent, c'est une erreur de routing (route sans
     * middleware) — on refuse explicitement.
     */
    private function extractWorkstationUuid(Request $request): string
    {
        $uuid = $request->attributes->get('auth_v1.workstation_uuid');

        if (! is_string($uuid) || $uuid === '') {
            throw new RuntimeException(
                'Missing auth_v1.workstation_uuid attribute — '
                . 'auth.v1.workstation middleware required on this route.'
            );
        }

        return strtolower($uuid);
    }

    /**
     * Pré-check idempotence applicatif (D12).
     */
    private function correlationExists(string $workstationUuid, string $correlationId): bool
    {
        return ScriptExecutionLog::query()
            ->where('workstation_uuid', $workstationUuid)
            ->where('correlation_id', $correlationId)
            ->exists();
    }

    /**
     * Détection unique violation cross-driver.
     *
     *  - Postgres : SQLSTATE 23505 (`unique_violation`)
     *  - SQLite : SQLSTATE 23000 (constraint failed), driver code 19
     *  - MySQL : SQLSTATE 23000, driver code 1062
     *
     * On retient SQLSTATE = 23xxx (Class 23 — Integrity Constraint Violation).
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        if ($sqlState === '23505') {
            return true; // pgsql unique_violation
        }

        // SQLite + MySQL — on inspecte aussi le message pour fiabiliser
        // (SQLSTATE 23000 est plus générique).
        if ($sqlState === '23000') {
            $msg = strtolower($e->getMessage());

            return str_contains($msg, 'unique')
                || str_contains($msg, '1062')
                || str_contains($msg, 'sel_ws_corr_unique');
        }

        return false;
    }
}
