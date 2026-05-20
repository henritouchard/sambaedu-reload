<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Gpo\Support\CachedGpoLookups;
use App\Gpo\Support\GpoLogger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Story 16.14 — Q2 arbitré Henri 2026-05-20.
 *
 * Commande `gpo:warm-cache` — pré-remplit le cache santé GPO (links +
 * versionNumber par GPO) pour éviter les N appels samba-tool en cascade
 * sur le listing admin.
 *
 * Schedule (`app/Console/Kernel.php`) : daily 22:00 — repeuple le cache
 * juste avant la fenêtre d'admin matinale habituelle (≈ 7-8 h).
 *
 * Options :
 *  - `--force` : flush complet du cache (`forgetAll`) avant le warm-up.
 *    Utile en cas de doute sur des entrées obsolètes (ex. post-déploiement).
 *
 * Exit code :
 *  - 0 succès, même si quelques erreurs partielles (best-effort).
 *  - 1 uniquement si le warm-up global échoue (samba-tool inaccessible).
 *
 * Logs émis (channel `gpo`) : `gpo.cache.warm` avec `count`, `duration_ms`,
 * `errors[]` (tronqué à 10 entrées dans le log).
 */
class GpoWarmCacheCommand extends Command
{
    /** @var string */
    protected $signature = 'gpo:warm-cache
        {--force : Flush le cache complet avant le warm-up.}';

    /** @var string */
    protected $description = 'Warm le cache santé GPO (links + versionNumber par GPO). Appelé en schedule 22:00 + manuel.';

    public function handle(CachedGpoLookups $cache): int
    {
        $force = (bool) $this->option('force');

        if ($force) {
            $this->info('--force : flush du cache complet avant warm-up.');
            $cache->forgetAll();
        }

        $log = GpoLogger::action('gpo.cache.warm', context: [
            'force' => $force,
            'invoked_by' => 'artisan',
        ]);

        try {
            $result = $cache->warmAll();
        } catch (Throwable $e) {
            $log->failure($e);
            $this->error('Échec du warm-up : ' . $e->getMessage());
            return self::FAILURE;
        }

        $log->success([
            'count' => $result['count'],
            'duration_ms' => $result['duration_ms'],
            'errors' => array_slice($result['errors'], 0, 10),
            'errors_total' => count($result['errors']),
        ]);

        $this->info(sprintf(
            '[OK] Warm-up cache santé GPO : %d GPOs en %d ms (%d erreurs partielles).',
            $result['count'],
            $result['duration_ms'],
            count($result['errors']),
        ));

        if (!empty($result['errors'])) {
            $this->warn('Erreurs partielles (jusqu\'à 5 affichées) :');
            foreach (array_slice($result['errors'], 0, 5) as $err) {
                $this->line('  - ' . $err);
            }
        }

        return self::SUCCESS;
    }
}
