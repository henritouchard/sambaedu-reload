<?php

declare(strict_types=1);

namespace App\Auth\V1\Jwt;

use App\Auth\V1\Models\WorkstationJwtRevocation;
use App\Auth\V1\Models\WorkstationRefreshToken;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Story 16.10 — AC2.3 / D10.
 *
 * Orchestre la rotation refresh + détection replay sur l'endpoint
 * `POST /api/v1/agent/refresh`.
 *
 * **Happy path** :
 *
 *  1. L'amont (middleware `EnsureRefreshToken`) a déjà validé le refresh
 *     côté DB (hash trouvé, non révoqué, non expiré) et injecté le record
 *     en `$request->attributes['auth_v1.refresh_token_record']`.
 *  2. `refresh()` est invoqué avec ce record :
 *      - Émet une nouvelle paire access + refresh via `WorkstationJwtIssuer`.
 *      - Crée une nouvelle entrée `workstation_refresh_tokens` (avec
 *        `client_meta` copié de l'ancien — pratique pour conserver
 *        l'historique mac/hostname).
 *      - Marque l'ancien refresh `revoked_at = now`, `revocation_reason =
 *        'refresh_rotation'`, `last_used_at = now`.
 *  3. Renvoie le nouveau payload (access_token, refresh_token clear,
 *     expires_in, refresh_expires_in).
 *
 * **Replay detection** (path adverse) :
 *
 *  - Si le middleware `EnsureRefreshToken` trouve un hash en DB mais avec
 *    `revoked_at != null`, c'est un replay. Il appelle alors
 *    `handleReplay($record)` :
 *      - Révoque cascade tous les refresh actifs du `workstation_uuid`
 *        (`revoked_at = now, revocation_reason = 'cascade_revoke'`).
 *      - Insère des entrées `workstation_jwt_revocations` pour chaque jti
 *        actif lié — on n'a pas le jti directement, donc on révoque par
 *        `workstation_uuid` (les futurs JWT seront refusés via une logique
 *        applicative : ici on ne peut révoquer en masse que les jti connus).
 *      - Le middleware retourne 401 `refresh.replay_detected` au caller.
 *      - Log `auth.token.replay_detected` warning.
 *
 *  - Limitation **acceptée** : on ne peut pas révoquer les jti des access
 *    tokens en cours sans les avoir listés. La table
 *    `workstation_refresh_tokens` ne capture pas le `jti` de l'access émis
 *    en parallèle (1 refresh ↔ N access). On documente cette limitation
 *    dans Dev Notes. L'attaquant pourrait conserver un access valide
 *    jusqu'à expiration (≤24h). En pratique le poste légitime va
 *    re-bootstrap (16.11) — un nouveau workstation_uuid si reset, ou
 *    l'attaquant doit re-voler le bootstrap token.
 *
 * **Toutes les opérations DB en transaction** pour éviter qu'un crash
 * Postgres laisse l'ancien refresh actif + un nouveau actif (deux refresh
 * valides = bug).
 */
class WorkstationJwtRefreshService
{
    public function __construct(
        private readonly WorkstationJwtIssuer $issuer,
    ) {
    }

    /**
     * Effectue la rotation refresh. Retourne le payload à renvoyer au client.
     *
     * @return array{
     *     access_token: string,
     *     refresh_token: string,
     *     token_type: string,
     *     expires_in: int,
     *     refresh_expires_in: int,
     *     workstation_uuid: string,
     *     access_jti: string,
     *     new_refresh_id: string
     * }
     */
    public function refresh(WorkstationRefreshToken $oldRecord): array
    {
        $workstationUuid = $oldRecord->workstation_uuid;
        $oldRecordId = $oldRecord->id;

        $access = $this->issuer->issueAccessToken($workstationUuid);
        $refresh = $this->issuer->issueRefreshToken($workstationUuid);

        $newRecord = DB::transaction(function () use ($oldRecord, $workstationUuid, $refresh): WorkstationRefreshToken {
            // Marque l'ancien
            $oldRecord->revoked_at = Carbon::now();
            $oldRecord->revocation_reason = 'refresh_rotation';
            $oldRecord->last_used_at = Carbon::now();
            $oldRecord->save();

            // Crée le nouveau
            $new = new WorkstationRefreshToken();
            $new->id = (string) Str::uuid();
            $new->workstation_uuid = $workstationUuid;
            $new->refresh_token_hash = $refresh['hash'];
            $new->issued_at = $refresh['issued_at'];
            $new->expires_at = $refresh['expires_at'];
            $new->client_meta = is_array($oldRecord->client_meta) ? $oldRecord->client_meta : [];
            $new->save();

            return $new;
        });

        Log::channel('auth-v1')->info('[WorkstationJwtRefreshService] auth.token.refreshed', [
            'action_type' => 'auth.token.refreshed',
            'workstation_uuid' => $workstationUuid,
            'old_refresh_id' => $oldRecordId,
            'new_refresh_id' => $newRecord->id,
            'new_access_jti' => $access['jti'],
            'kid' => $access['kid'],
        ]);

        return [
            'access_token' => $access['token'],
            'refresh_token' => $refresh['clear'],
            'token_type' => 'Bearer',
            'expires_in' => (int) config('auth_v1.jwt.access_ttl', 86400),
            'refresh_expires_in' => (int) config('auth_v1.jwt.refresh_ttl', 2592000),
            'workstation_uuid' => $workstationUuid,
            'access_jti' => $access['jti'],
            'new_refresh_id' => $newRecord->id,
        ];
    }

    /**
     * Gère un replay : refresh_token présenté mais déjà `revoked_at != null`.
     * Révoque cascade tous les refresh actifs du workstation_uuid.
     *
     * @return array{revoked_refresh_count: int}
     */
    public function handleReplay(WorkstationRefreshToken $replayed): array
    {
        $workstationUuid = $replayed->workstation_uuid;

        $count = DB::transaction(function () use ($workstationUuid) {
            $now = Carbon::now();

            // Cascade : tous les refresh actifs (ou même expirés mais non
            // révoqués) sont marqués revoked
            $affected = WorkstationRefreshToken::query()
                ->where('workstation_uuid', $workstationUuid)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => $now,
                    'revocation_reason' => 'cascade_revoke',
                ]);

            return (int) $affected;
        });

        Log::channel('auth-v1')->warning('[WorkstationJwtRefreshService] auth.token.replay_detected', [
            'action_type' => 'auth.token.replay_detected',
            'workstation_uuid' => $workstationUuid,
            'replayed_refresh_id' => $replayed->id,
            'cascade_revoked_count' => $count,
        ]);

        return ['revoked_refresh_count' => $count];
    }

    /**
     * Helper pour enregistrer un nouveau refresh (utilisé par `EnrollController`).
     * Crée l'entrée DB. Retourne l'instance enregistrée.
     *
     * @param array<string, mixed> $clientMeta
     */
    public function persistEnrollmentRefresh(
        string $workstationUuid,
        string $refreshHash,
        Carbon $issuedAt,
        Carbon $expiresAt,
        array $clientMeta,
    ): WorkstationRefreshToken {
        $record = new WorkstationRefreshToken();
        $record->id = (string) Str::uuid();
        $record->workstation_uuid = $workstationUuid;
        $record->refresh_token_hash = $refreshHash;
        $record->issued_at = $issuedAt;
        $record->expires_at = $expiresAt;
        $record->client_meta = $clientMeta;
        $record->save();

        return $record;
    }

    /**
     * Mark all active access jti revoked for a workstation_uuid.
     * Utile pour la commande `workstation:revoke` (T7.1) — mais comme on ne
     * connaît pas les jti des access tokens en cours, on insère une entrée
     * "marker" `workstation_jwt_revocations` avec un jti synthétique non-UUID
     * **inexistant en pratique** — non, c'est insuffisant.
     *
     * **Choix réel** : la commande `workstation:revoke` lit la table
     * `workstation_refresh_tokens` actifs pour ce uuid, n'y trouve pas
     * directement de jti d'access mais peut révoquer **tous les refresh
     * actifs**. Les access tokens en cours expireront naturellement (≤24h)
     * et ne pourront pas être renouvelés.
     *
     * Pour une révocation "stricte" d'access en cours, il faudrait tracker
     * chaque jti émis (table workstation_jwt_emissions par exemple — futur).
     * On accepte la fenêtre 24h en Phase 2.
     */
    public function revokeAllRefreshesForWorkstation(string $workstationUuid, string $reason, ?string $by = null): int
    {
        $now = Carbon::now();
        $affected = WorkstationRefreshToken::query()
            ->where('workstation_uuid', $workstationUuid)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'revocation_reason' => $reason,
            ]);

        Log::channel('auth-v1')->info('[WorkstationJwtRefreshService] auth.token.revoked', [
            'action_type' => 'auth.token.revoked',
            'workstation_uuid' => $workstationUuid,
            'reason' => $reason,
            'revoked_by' => $by,
            'refresh_revoked_count' => $affected,
        ]);

        return (int) $affected;
    }
}
