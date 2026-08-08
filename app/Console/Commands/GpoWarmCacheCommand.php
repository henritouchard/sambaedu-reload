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
    protected $description = 'Préchauffe le cache de santé GPO (liens et numéro de version par GPO).';

    /** @var string */
    protected $help = <<<'HELP'
    Pré-remplit le cache de santé des stratégies de groupe — liens et numéro de
    version de chaque stratégie — pour que la page d'administration n'ait pas à
    interroger le contrôleur de domaine stratégie par stratégie à l'affichage.

    Planifiée chaque soir, de sorte que le cache soit chaud à l'ouverture du matin.

      <info>php artisan gpo:warm-cache</info>
      <info>php artisan gpo:warm-cache --force</info>   vide entièrement le cache d'abord

    <comment>--force</comment> est le geste à faire après un déploiement, ou dès qu'on soupçonne des
    entrées périmées.

    Codes de retour : <info>0</info> même si quelques stratégies ont échoué — le remplissage est
    au mieux · <info>1</info> seulement si le contrôleur de domaine est globalement inaccessible.
    HELP;

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
