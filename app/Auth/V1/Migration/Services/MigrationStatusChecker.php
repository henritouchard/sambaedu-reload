<?php

declare(strict_types=1);

namespace App\Auth\V1\Migration\Services;

use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Models\WorkstationMigrationStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Module de migration SE4 → SE5.
 *
 * Ce code pourra être supprimé lorsqu'il n'existera plus de nécessité de
 * migrer un déploiement SE4 vers SE5 (typiquement : quand aucun collège
 * actif n'utilise plus SE4 = sambaedu legacy PHP-only).
 *
 * Sprint Change Proposal 2026-05-19. Story 16.13bis.
 *
 * Service utilitaire qui :
 *  - lookup l'état migration d'un poste (`isMigrated($uuid)`),
 *  - log un `WorkstationMigrationAttempt` (status='started') à chaque
 *    requête legacy traitée par `MigrationController::serveFragment`.
 *
 * Réutilise les tables `workstations_migration_status` et
 * `workstation_migration_attempts` livrées par Story 16.11.
 */
final class MigrationStatusChecker
{
    /**
     * Indique si le poste identifié par `$uuid` est déjà migré (présence
     * d'une row `workstation_migration_status`).
     *
     * Tolère un UUID vide / null → false (pas migré, fragment complet).
     * Best-effort en cas d'erreur DB : retourne false pour servir un
     * fragment complet plutôt que de leaker un état serveur inconnu.
     */
    public function isMigrated(?string $uuid): bool
    {
        if ($uuid === null || $uuid === '') {
            return false;
        }

        try {
            return WorkstationMigrationStatus::query()
                ->where('workstation_uuid', strtolower($uuid))
                ->exists();
        } catch (Throwable $e) {
            Log::channel('auth-v1')->warning(
                '[MigrationStatusChecker] migration.status.lookup_failed',
                [
                    'action_type' => 'migration.status.lookup_failed',
                    'error' => $e->getMessage(),
                ],
            );

            return false;
        }
    }

    /**
     * Insère une row `workstation_migration_attempts` status='started' pour
     * traçabilité du fragment servi. Best-effort : un échec DB n'empêche
     * pas le service du fragment.
     */
    public function logAttempt(Request $request, string $os, ?string $uuid): void
    {
        try {
            WorkstationMigrationAttempt::create([
                'workstation_uuid' => $uuid !== null && $uuid !== '' ? strtolower($uuid) : null,
                'started_at' => now(),
                'status' => WorkstationMigrationAttempt::STATUS_STARTED,
                'client_ip' => (string) ($request->server('REMOTE_ADDR', '') ?? $request->ip() ?? '127.0.0.1'),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
                'os' => $os,
            ]);
        } catch (Throwable $e) {
            Log::channel('auth-v1')->warning(
                '[MigrationStatusChecker] migration.attempt.persist_failed',
                [
                    'action_type' => 'migration.attempt.persist_failed',
                    'error' => $e->getMessage(),
                    'os' => $os,
                ],
            );
        }
    }

    /**
     * Extrait un UUID v4 strict (avec ou sans tirets) de la requête (query,
     * body, json). Retourne null si absent ou format invalide.
     */
    public function extractDeclaredUuid(Request $request): ?string
    {
        $candidate = trim((string) $request->input('uuid', ''));
        if ($candidate === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $candidate) !== 1) {
            return null;
        }

        return strtolower($candidate);
    }

    /**
     * Résout l'UUID d'un poste depuis le paramètre legacy `machine` (cn).
     *
     * Correctif 2026-06-05 : les scripts GPO legacy (templates sambaedu-gpo,
     * cf. `applications/logon.cmd` sysvol) n'envoient **jamais** de `uuid` —
     * seulement `machine=%computername%`. Sans résolution serveur, le
     * bootstrap_token est minté non lié et l'enroll échoue systématiquement
     * en 401 `uuid_mismatch` (fail-closed 16.11) : aucun poste passant par
     * la GPO ne peut migrer. On résout donc l'uuid en DB par le nom.
     *
     * Pas d'affaiblissement sécurité : `uuid` était déjà un input client
     * non authentifié ; le couple token↔uuid ne garantit que la cohérence
     * mint/enroll, pas l'authenticité du poste.
     *
     * Best-effort : null si paramètre absent/invalide, poste inconnu ou
     * uuid SQL vide (poste legacy jamais enrôlé iPXE).
     */
    public function resolveUuidFromMachineName(Request $request): ?string
    {
        $machine = trim((string) $request->input('machine', ''));
        // Pattern cn NetBIOS/sAMAccountName : pas de wildcard ni méta LIKE.
        if ($machine === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,62}$/', $machine) !== 1) {
            return null;
        }

        try {
            $uuid = \App\Models\Workstation::query()
                ->whereRaw('LOWER(name) = ?', [strtolower($machine)])
                ->value('uuid');
        } catch (Throwable $e) {
            Log::channel('auth-v1')->warning(
                '[MigrationStatusChecker] migration.uuid.machine_lookup_failed',
                [
                    'action_type' => 'migration.uuid.machine_lookup_failed',
                    'error' => $e->getMessage(),
                ],
            );

            return null;
        }

        $uuid = trim((string) $uuid);

        return $uuid !== '' ? strtolower($uuid) : null;
    }
}
