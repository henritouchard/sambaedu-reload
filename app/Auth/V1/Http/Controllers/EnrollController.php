<?php

declare(strict_types=1);

namespace App\Auth\V1\Http\Controllers;

use App\Auth\V1\Http\Requests\EnrollAgentRequest;
use App\Auth\V1\Jwt\WorkstationJwtIssuer;
use App\Auth\V1\Jwt\WorkstationJwtRefreshService;
use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Models\WorkstationMigrationStatus;
use App\Auth\V1\Pki\CaInitializer;
use App\Auth\V1\Services\MigrationAttemptRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Story 16.10 — AC5.1.
 *
 * Endpoint `POST /api/v1/agent/enroll`.
 *
 * - Amont protégé par `RequireBootstrapToken` + `throttle:10,1`.
 * - Body validé par `EnrollAgentRequest`.
 * - Émet access + refresh via `WorkstationJwtIssuer`.
 * - Persiste le refresh DB via `WorkstationJwtRefreshService::persistEnrollmentRefresh`.
 * - Retourne le `ca_cert_pem` (CA root local) + `server_base_url` pour
 *   permettre au poste de pinner le CA et savoir où adresser les requêtes
 *   futures.
 *
 * **Idempotence** (AC5.1) : ré-enrôler le même UUID **n'invalide PAS** les
 * sessions existantes. On émet une nouvelle paire fraîche, l'ancienne reste
 * valide jusqu'à sa propre rotation/révocation.
 *
 * **Réponse OK** :
 *
 * ```json
 * {
 *   "success": true,
 *   "message": "Workstation enrolled",
 *   "access_token": "<jwt>",
 *   "refresh_token": "<64 hex>",
 *   "token_type": "Bearer",
 *   "expires_in": 86400,
 *   "refresh_expires_in": 2592000,
 *   "ca_cert_pem": "-----BEGIN CERTIFICATE-----\n...",
 *   "server_base_url": "https://se4fs-<UAI>.<domain>"
 * }
 * ```
 *
 * `success` + `message` ajoutés (cf. convention `architecture.md` —
 * réponse API toujours avec `success: true|false` + `message`).
 *
 * **Story 16.11 — upsert migration status** : en fin de flot enroll réussi,
 * upsert dans `workstations_migration_status` (unique sur `workstation_uuid`)
 * + insertion d'une row `workstation_migration_attempts` (status='enrolled').
 * Le middleware `InjectBootstrapFragment` consulte cette table pour décider
 * de no-op (poste déjà migré) sur les prochaines requêtes legacy.
 */
class EnrollController extends Controller
{
    public function __construct(
        private readonly WorkstationJwtIssuer $issuer,
        private readonly WorkstationJwtRefreshService $refreshService,
        private readonly CaInitializer $caInitializer,
        private readonly MigrationAttemptRecorder $attemptRecorder,
    ) {
    }

    public function store(EnrollAgentRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $workstationUuid = (string) $validated['uuid'];
        $mac = strtoupper(str_replace('-', ':', (string) $validated['mac']));
        $hostname = (string) $validated['hostname'];
        $os = (string) $validated['os'];

        // 1. Émission access + refresh
        $access = $this->issuer->issueAccessToken($workstationUuid);
        $refresh = $this->issuer->issueRefreshToken($workstationUuid);

        // 2. Persistance DB du refresh (capture client_meta)
        $clientMeta = [
            'mac' => $mac,
            'hostname' => $hostname,
            'os' => $os,
            'enroll_ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 200),
        ];

        $this->refreshService->persistEnrollmentRefresh(
            workstationUuid: $workstationUuid,
            refreshHash: $refresh['hash'],
            issuedAt: $refresh['issued_at'],
            expiresAt: $refresh['expires_at'],
            clientMeta: $clientMeta,
        );

        // 3. CA root PEM (pour le poste — il pin ce CA pour valider HTTPS)
        try {
            $caCertPem = $this->caInitializer->getCaCertPem();
        } catch (RuntimeException $e) {
            Log::channel('auth-v1')->error('[EnrollController] CA cert not available', [
                'action_type' => 'auth.enroll.ca_missing',
                'error' => $e->getMessage(),
                'workstation_uuid' => $workstationUuid,
            ]);

            // En testing/local on tolère un CA absent (mocks, dev sans PKI initialisée).
            // En prod : 503 strict — un enrollment sans CA root expose le poste à du MitM
            // (il pourrait basculer en mode skip-CA-verify, accepter n'importe quel cert).
            if (! app()->environment(['testing', 'local'])) {
                // Story 16.11 Q2 (Opus-B) — tracer comme failed pour health-check.
                $this->attemptRecorder->recordFailure(
                    $request,
                    'pki.not_initialized',
                    $workstationUuid,
                    'PKI CA cert not available: ' . $e->getMessage(),
                    $os,
                );

                return response()->json([
                    'success' => false,
                    'message' => 'PKI not initialized — run php artisan auth:ca:init on the local server',
                    'code' => 'pki.not_initialized',
                ], 503);
            }

            $caCertPem = '';
        }

        // 4. Server base URL
        $serverBaseUrl = $this->resolveServerBaseUrl();

        // Story 16.11 — upsert workstations_migration_status + log migration.
        $this->recordMigrationSuccess($request, $workstationUuid, $os, (string) $access['jti']);

        Log::channel('auth-v1')->info('[EnrollController] auth.enroll.success', [
            'action_type' => 'auth.enroll.success',
            'workstation_uuid' => $workstationUuid,
            'mac' => $mac,
            'hostname' => $hostname,
            'os' => $os,
            'ip' => $request->ip(),
            'access_jti' => $access['jti'],
            'kid' => $access['kid'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Workstation enrolled',
            'access_token' => $access['token'],
            'refresh_token' => $refresh['clear'],
            'token_type' => 'Bearer',
            'expires_in' => (int) config('auth_v1.jwt.access_ttl', 86400),
            'refresh_expires_in' => (int) config('auth_v1.jwt.refresh_ttl', 2592000),
            'ca_cert_pem' => $caCertPem,
            'server_base_url' => $serverBaseUrl,
        ], 200);
    }

    /**
     * Résout l'URL HTTPS du serveur local.
     *
     *  1. config('auth_v1.server.base_url') si non vide
     *  2. construite à partir de sambaedu.se4fs_name + host_suffix
     *  3. fallback `https://<request_host>` (utile dev local)
     */
    private function resolveServerBaseUrl(): string
    {
        $configured = (string) config('auth_v1.server.base_url', '');
        if ($configured !== '') {
            return $configured;
        }

        $se4fs = (string) config('sambaedu.se4fs_name', '');
        $suffix = (string) config('auth_v1.server.host_suffix', '');
        if ($se4fs !== '') {
            $host = $suffix === '' ? $se4fs : ($se4fs . '.' . ltrim($suffix, '.'));

            return 'https://' . $host;
        }

        $appUrl = (string) config('app.url', '');
        if ($appUrl !== '') {
            return preg_replace('/^http:/i', 'https:', $appUrl) ?? $appUrl;
        }

        return 'https://localhost';
    }

    /**
     * Story 16.11 — upsert `workstations_migration_status` + insert attempt
     * `enrolled` après un enroll réussi. Best-effort : si DB indispo, on log
     * et on ne fait pas crasher l'enroll lui-même.
     *
     * Note : l'exception est intentionnellement absorbée pour ne pas casser
     * le flot enroll — le poste recevra son JWT et repartira normalement.
     * En revanche l'absence de row en DB est détectée via les error logs
     * `auth-v1` (`auth.migration.persist_failed`) pour l'audit forensique.
     * Le poste re-tentera le bootstrap à la prochaine requête legacy (idempotence).
     */
    private function recordMigrationSuccess(
        \Illuminate\Http\Request $request,
        string $workstationUuid,
        string $os,
        string $jti,
    ): void {
        try {
            WorkstationMigrationStatus::updateOrCreate(
                ['workstation_uuid' => $workstationUuid],
                [
                    'migrated_at' => Carbon::now(),
                    'access_token_emitted_jti' => $jti,
                    'bootstrap_token_hash_prefix' => substr(hash('sha256', (string) $request->header('X-Bootstrap-Token', '')), 0, 16),
                    'os' => $os,
                    'se4fs_name' => (string) config('sambaedu.se4fs_name', ''),
                ],
            );

            WorkstationMigrationAttempt::create([
                'workstation_uuid' => $workstationUuid,
                'started_at' => Carbon::now(),
                'finished_at' => Carbon::now(),
                'status' => WorkstationMigrationAttempt::STATUS_ENROLLED,
                'client_ip' => (string) ($request->server('REMOTE_ADDR', '') ?? $request->ip() ?? '127.0.0.1'),
                'user_agent' => substr((string) $request->userAgent(), 0, 1024),
                'os' => $os,
            ]);

            Log::channel('auth-v1')->info('[EnrollController] auth.migration.success', [
                'action_type' => 'auth.migration.success',
                'workstation_uuid' => $workstationUuid,
                'os' => $os,
            ]);
        } catch (\Throwable $e) {
            Log::channel('auth-v1')->error('[EnrollController] auth.migration.persist_failed', [
                'action_type' => 'auth.migration.persist_failed',
                'workstation_uuid' => $workstationUuid,
                'error_class' => $e::class,
                'error' => $e->getMessage(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
            ]);
        }
    }
}
