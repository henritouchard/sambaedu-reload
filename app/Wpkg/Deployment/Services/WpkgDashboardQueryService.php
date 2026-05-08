<?php

declare(strict_types=1);

namespace App\Wpkg\Deployment\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.5 / AC3 — Encapsule les requêtes SQL agrégées du dashboard
 * `/app/wpkg/deployments`. Permet de :
 *
 *   - Tester unitairement les requêtes (sans Livewire).
 *   - Réutiliser les agrégats dans les sous-pages (drill-down, listing).
 *   - Instrumenter le port PostgreSQL ↔ SQLite (`DISTINCT ON` PG-only).
 *
 * Portabilité : `DISTINCT ON (workstation_id)` est PostgreSQL-only. En SQLite
 * (testing) on bascule sur `ROW_NUMBER() OVER (PARTITION BY ...)` qui est
 * supporté depuis SQLite 3.25 et la plupart des SGBD modernes.
 *
 * Performance : conçu pour atteindre NFR1 < 2s sur 500 postes via :
 *   - Une seule requête SQL pour les KPIs globaux (pas N+1).
 *   - Indices DB en place (cf. migration 2026_05_06_100300).
 *   - Filtre `WHERE` discriminant (pas de scan full table).
 */
final class WpkgDashboardQueryService
{
    /**
     * KPIs globaux (cards en haut du dashboard). Une seule requête SQL.
     *
     * @return array{
     *     total: int,
     *     success: int,
     *     partial: int,
     *     failed: int,
     *     silent: int,
     *     last_sync: \Illuminate\Support\Carbon|null,
     *     unknown: int,
     *     never_reported: int,
     * }
     */
    public function kpis(?Carbon $silentThreshold = null, ?Carbon $recentThreshold = null): array
    {
        $silentCutoff = $silentThreshold ?? Carbon::now()->subDays(7);
        $recentCutoff = $recentThreshold ?? Carbon::now()->subDay();

        // Latest status per workstation : `DISTINCT ON` (PG) ou
        // `ROW_NUMBER() OVER (PARTITION BY)` (SQLite).
        $latestSubquery = $this->latestStatusPerWorkstationSubquery();

        $totalWorkstations = (int) DB::table('workstations')
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->count();

        $rows = DB::table(DB::raw("({$latestSubquery['sql']}) AS latest"))
            ->setBindings($latestSubquery['bindings'])
            ->selectRaw('
                COUNT(*) as reported,
                SUM(CASE WHEN client_status = ? THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN client_status = ? THEN 1 ELSE 0 END) as partial,
                SUM(CASE WHEN client_status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN client_status = ? THEN 1 ELSE 0 END) as unknown_count,
                MAX(client_reported_at) as last_sync
            ', ['success', 'partial', 'failed', 'unknown'])
            ->first();

        $reported = $rows !== null ? (int) $rows->reported : 0;
        $success = $rows !== null ? (int) $rows->success : 0;
        $partial = $rows !== null ? (int) $rows->partial : 0;
        $failed = $rows !== null ? (int) $rows->failed : 0;
        $unknown = $rows !== null ? (int) $rows->unknown_count : 0;
        $lastSync = $rows !== null && $rows->last_sync !== null
            ? Carbon::parse((string) $rows->last_sync)
            : null;

        // Postes silencieux : last_report_at < cutoff OU jamais reporté.
        $silent = (int) DB::table('workstations')
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->where(function ($q) use ($silentCutoff): void {
                $q->whereNull('last_report_at')
                  ->orWhere('last_report_at', '<', $silentCutoff);
            })
            ->count();

        $neverReported = (int) DB::table('workstations')
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereNull('last_report_at')
            ->count();

        return [
            'total' => $totalWorkstations,
            'reported' => $reported,
            'success' => $success,
            'partial' => $partial,
            'failed' => $failed,
            'unknown' => $unknown,
            'silent' => $silent,
            'never_reported' => $neverReported,
            'last_sync' => $lastSync,
        ];
    }

    /**
     * Agrégats par parc (`workstation_groups`) : compteurs par statut.
     *
     * @return list<array{
     *     group_id: int,
     *     group_name: string,
     *     total: int,
     *     success: int,
     *     partial: int,
     *     failed: int,
     *     silent: int,
     * }>
     */
    public function groupAggregates(): array
    {
        $latestSubquery = $this->latestStatusPerWorkstationSubquery();

        $rows = DB::table('workstation_groups as wg')
            ->leftJoin('workstation_group_workstation as wgw', 'wgw.workstation_group_id', '=', 'wg.id')
            ->leftJoin('workstations as w', function ($join): void {
                $join->on('w.id', '=', 'wgw.workstation_id')
                     ->where('w.status', '=', 'active')
                     ->whereNull('w.archived_at');
            })
            ->leftJoin(
                DB::raw("({$latestSubquery['sql']}) AS latest"),
                'latest.workstation_id',
                '=',
                'w.id'
            )
            ->whereNull('wg.archived_at')
            ->selectRaw('
                wg.id as group_id,
                wg.name as group_name,
                COUNT(DISTINCT w.id) as total,
                SUM(CASE WHEN latest.client_status = ? THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN latest.client_status = ? THEN 1 ELSE 0 END) as partial,
                SUM(CASE WHEN latest.client_status = ? THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN w.id IS NOT NULL AND (w.last_report_at IS NULL OR w.last_report_at < ?) THEN 1 ELSE 0 END) as silent
            ', array_merge(['success', 'partial', 'failed', Carbon::now()->subDays(7)], $latestSubquery['bindings']))
            ->groupBy('wg.id', 'wg.name')
            ->orderBy('wg.name')
            ->get();

        return $rows->map(static fn ($row): array => [
            'group_id' => (int) $row->group_id,
            'group_name' => (string) $row->group_name,
            'total' => (int) $row->total,
            'success' => (int) $row->success,
            'partial' => (int) $row->partial,
            'failed' => (int) $row->failed,
            'silent' => (int) $row->silent,
        ])->all();
    }

    /**
     * Agrégats par profil (`app_profiles`) : compteurs par statut, tous les
     * postes héritant du profil (direct ou via groupe).
     *
     * @return list<array{
     *     profile_id: int,
     *     profile_name: string,
     *     total: int,
     *     success: int,
     *     partial: int,
     *     failed: int,
     * }>
     */
    public function profileAggregates(): array
    {
        $latestSubquery = $this->latestStatusPerWorkstationSubquery();

        // Story 15.5 / Fix #12 — restructuration en UNION ALL de 2 sous-jointures
        // pour éviter l'ambiguïté sémantique du précédent `orOn` + `where` sur
        // la condition status/archived_at. Chaque sous-jointure ne ramène que
        // des workstations actives non-archivées (filtrées en amont via WHERE),
        // puis on dédoublonne par DISTINCT au niveau du COUNT.
        //
        //   Source 1 : (profile_id, workstation_id) via app_profile_workstation direct
        //   Source 2 : (profile_id, workstation_id) via app_profile_workstation_group → workstation_group_workstation
        //
        // Note : on inline 'active' dans le SQL (sans binding) car SQLite refuse
        // les parenthèses autour des SELECT dans un UNION et la gestion des
        // bindings à travers DB::raw/leftJoin est fragile. Les valeurs sont
        // statiques et non-utilisateur, donc pas de risque d'injection.
        $unionSql = "SELECT apw.app_profile_id AS profile_id, w.id AS workstation_id
                     FROM app_profile_workstation apw
                     INNER JOIN workstations w ON w.id = apw.workstation_id
                     WHERE w.status = 'active' AND w.archived_at IS NULL
                     UNION ALL
                     SELECT apwg.app_profile_id AS profile_id, w.id AS workstation_id
                     FROM app_profile_workstation_group apwg
                     INNER JOIN workstation_group_workstation wgw_p ON wgw_p.workstation_group_id = apwg.workstation_group_id
                     INNER JOIN workstations w ON w.id = wgw_p.workstation_id
                     WHERE w.status = 'active' AND w.archived_at IS NULL";

        $rows = DB::table('app_profiles as ap')
            ->leftJoin(
                DB::raw("({$unionSql}) AS pw"),
                'pw.profile_id',
                '=',
                'ap.id'
            )
            ->leftJoin(
                DB::raw("({$latestSubquery['sql']}) AS latest"),
                'latest.workstation_id',
                '=',
                'pw.workstation_id'
            )
            ->whereNull('ap.archived_at')
            ->selectRaw('
                ap.id as profile_id,
                ap.name as profile_name,
                COUNT(DISTINCT pw.workstation_id) as total,
                COUNT(DISTINCT CASE WHEN latest.client_status = ? THEN pw.workstation_id END) as success,
                COUNT(DISTINCT CASE WHEN latest.client_status = ? THEN pw.workstation_id END) as partial,
                COUNT(DISTINCT CASE WHEN latest.client_status = ? THEN pw.workstation_id END) as failed
            ', array_merge(
                ['success', 'partial', 'failed'],
                $latestSubquery['bindings'],
            ))
            ->groupBy('ap.id', 'ap.name')
            ->orderBy('ap.name')
            ->get();

        return $rows->map(static fn ($row): array => [
            'profile_id' => (int) $row->profile_id,
            'profile_name' => (string) $row->profile_name,
            'total' => (int) $row->total,
            'success' => (int) $row->success,
            'partial' => (int) $row->partial,
            'failed' => (int) $row->failed,
        ])->all();
    }

    /**
     * Story 15.5 / Fix #11 — Liste paginée des incidents 24h, dédupliquée
     * par `workstation_id` (un poste = une seule ligne, le dernier statut).
     *
     * Sans dédup, un poste qui rapporte 3 fois `failed` en 24h produit 3
     * lignes dans la table → bruit visuel + biais sur la pagination.
     *
     * Driver-aware :
     *   - PG : `DISTINCT ON (workstation_id) ... ORDER BY workstation_id, client_reported_at DESC`
     *   - SQLite + autres : `ROW_NUMBER() OVER (PARTITION BY workstation_id)
     *     WHERE rn = 1` (réutilise le pattern `latestStatusPerWorkstationSubquery`).
     *
     * @param  list<string>|null  $statusFilter  Liste de statuts à inclure.
     *                                           Si null → ['partial','failed','unknown'].
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function recentIncidentsPaginated(
        int $perPage,
        ?array $statusFilter = null,
        ?Carbon $cutoff = null,
    ) {
        $perPage = max(1, min(200, $perPage));
        $cutoffDate = $cutoff ?? Carbon::now()->subDay();
        $statuses = $statusFilter !== null && ! empty($statusFilter)
            ? array_values($statusFilter)
            : ['partial', 'failed', 'unknown'];

        $latestSubquery = $this->latestIncidentPerWorkstationSubquery($cutoffDate, $statuses);

        $query = DB::table(DB::raw("({$latestSubquery['sql']}) AS wdws"))
            ->setBindings($latestSubquery['bindings'])
            ->join('workstations as w', 'w.id', '=', 'wdws.workstation_id')
            ->select([
                'wdws.id as id',
                'wdws.client_status as client_status',
                'wdws.client_reported_at as client_reported_at',
                'wdws.details as details',
                'wdws.workstation_id as workstation_id',
                'w.name as workstation_name',
            ])
            ->orderByDesc('wdws.client_reported_at');

        return $query->paginate($perPage);
    }

    /**
     * Sous-requête « dernier incident par workstation sur la fenêtre temporelle ».
     * Driver-aware (PG DISTINCT ON / SQLite ROW_NUMBER).
     *
     * @param  list<string>  $statuses
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function latestIncidentPerWorkstationSubquery(Carbon $cutoff, array $statuses): array
    {
        $driver = DB::connection()->getDriverName();
        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $bindings = array_merge([$cutoff], $statuses);

        if ($driver === 'pgsql') {
            return [
                'sql' => "SELECT DISTINCT ON (workstation_id) id, workstation_id, client_status, client_reported_at, details
                          FROM wpkg_deployment_workstation_status
                          WHERE client_reported_at >= ?
                            AND client_status IN ({$placeholders})
                          ORDER BY workstation_id, client_reported_at DESC",
                'bindings' => $bindings,
            ];
        }

        return [
            'sql' => "SELECT id, workstation_id, client_status, client_reported_at, details FROM (
                        SELECT id, workstation_id, client_status, client_reported_at, details,
                               ROW_NUMBER() OVER (PARTITION BY workstation_id ORDER BY client_reported_at DESC) as rn
                        FROM wpkg_deployment_workstation_status
                        WHERE client_reported_at >= ?
                          AND client_status IN ({$placeholders})
                      ) ranked WHERE rn = 1",
            'bindings' => $bindings,
        ];
    }

    /**
     * Construit la sous-requête « dernier statut par workstation » selon
     * le driver DB :
     *   - PG : `SELECT DISTINCT ON (workstation_id) ... ORDER BY workstation_id, client_reported_at DESC`
     *   - SQLite + autres : `SELECT ... FROM (... ROW_NUMBER() OVER ...)
     *     WHERE rn = 1`
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function latestStatusPerWorkstationSubquery(): array
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return [
                'sql' => 'SELECT DISTINCT ON (workstation_id) workstation_id, client_status, client_reported_at
                          FROM wpkg_deployment_workstation_status
                          ORDER BY workstation_id, client_reported_at DESC',
                'bindings' => [],
            ];
        }

        // SQLite >=3.25 + MySQL 8 + autres : ROW_NUMBER OVER PARTITION.
        return [
            'sql' => 'SELECT workstation_id, client_status, client_reported_at FROM (
                        SELECT workstation_id, client_status, client_reported_at,
                               ROW_NUMBER() OVER (PARTITION BY workstation_id ORDER BY client_reported_at DESC) as rn
                        FROM wpkg_deployment_workstation_status
                      ) ranked WHERE rn = 1',
            'bindings' => [],
        ];
    }

    /**
     * Logs la durée d'une requête lente pour audit perf NFR1.
     */
    public function withSlowQueryAudit(string $name, callable $callback): mixed
    {
        $start = microtime(true);
        $result = $callback();
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        if ($durationMs > 500) {
            Log::channel('wpkg-deploy')->warning('[WpkgDashboardQueryService] requête lente', [
                'event' => 'wpkg_dashboard_slow_query',
                'name' => $name,
                'duration_ms' => $durationMs,
            ]);
        }

        return $result;
    }
}
