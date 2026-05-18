<?php

declare(strict_types=1);

namespace App\ScriptsOs\Services;

use App\ScriptsOs\Models\ScriptExecutionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Story 16.12 — AC6.1 / D7.
 *
 * Calcule les indicateurs affichés dans le bandeau d'en-tête de
 * `/admin/settings/scripts-logs/` :
 *
 *  - **`dashboard24h()`** — taux d'échec global sur 24h glissantes
 *  - **`topFailingWorkstations()`** — top N postes les plus en échec
 *  - **`topFailingScripts()`** — top N scripts les plus en échec
 *
 * Chaque méthode est cachée TTL 60s via `Cache::remember()` driver default
 * (Redis ou file selon config). Compromis fraîcheur (monitoring quasi-RT)
 * / coût query (3 GROUP BY indexed = ~100ms à 100k rows).
 */
class ScriptExecutionLogStatsService
{
    private const CACHE_KEY_DASHBOARD = 'scriptsos.stats.dashboard24h';
    private const CACHE_KEY_TOP_WS = 'scriptsos.stats.top_failing_ws_';
    private const CACHE_KEY_TOP_SCRIPTS = 'scriptsos.stats.top_failing_scripts_';

    /**
     * Stats globales 24h glissantes.
     *
     * @return array{total:int, failures:int, rate:float}
     */
    public function dashboard24h(): array
    {
        $ttl = (int) config('scriptsos.stats_cache_ttl', 60);

        return Cache::remember(self::CACHE_KEY_DASHBOARD, $ttl, static function (): array {
            $window = Carbon::now()->subHours(24);

            $total = ScriptExecutionLog::query()
                ->where('started_at', '>=', $window)
                ->count();

            $failures = ScriptExecutionLog::query()
                ->where('started_at', '>=', $window)
                ->failed()
                ->count();

            $rate = $total > 0 ? $failures / $total : 0.0;

            return [
                'total' => $total,
                'failures' => $failures,
                'rate' => $rate,
            ];
        });
    }

    /**
     * Top postes les plus en échec sur 24h.
     *
     * @return Collection<int,object{workstation_uuid:string, failures_count:int}>
     */
    public function topFailingWorkstations(int $limit = 5): Collection
    {
        $ttl = (int) config('scriptsos.stats_cache_ttl', 60);
        $key = self::CACHE_KEY_TOP_WS . $limit;

        return Cache::remember($key, $ttl, static function () use ($limit): Collection {
            return ScriptExecutionLog::query()
                ->failed()
                ->where('started_at', '>=', Carbon::now()->subHours(24))
                ->selectRaw('workstation_uuid, COUNT(*) as failures_count')
                ->groupBy('workstation_uuid')
                ->orderByDesc('failures_count')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Top scripts les plus en échec sur 24h. Exclut les logs sans
     * `script_id` (cas gpo_applications, manual, etc.).
     *
     * @return Collection<int,object{script_id:int, failures_count:int}>
     */
    public function topFailingScripts(int $limit = 5): Collection
    {
        $ttl = (int) config('scriptsos.stats_cache_ttl', 60);
        $key = self::CACHE_KEY_TOP_SCRIPTS . $limit;

        return Cache::remember($key, $ttl, static function () use ($limit): Collection {
            return ScriptExecutionLog::query()
                ->failed()
                ->whereNotNull('script_id')
                ->where('started_at', '>=', Carbon::now()->subHours(24))
                ->selectRaw('script_id, COUNT(*) as failures_count')
                ->groupBy('script_id')
                ->orderByDesc('failures_count')
                ->limit($limit)
                ->get();
        });
    }

    /**
     * Force la purge du cache des stats — utile post-archivage (les rows
     * supprimées ne doivent plus être comptées) ou en tests.
     */
    public function flushCache(int $limit = 5): void
    {
        Cache::forget(self::CACHE_KEY_DASHBOARD);
        Cache::forget(self::CACHE_KEY_TOP_WS . $limit);
        Cache::forget(self::CACHE_KEY_TOP_SCRIPTS . $limit);
    }
}
