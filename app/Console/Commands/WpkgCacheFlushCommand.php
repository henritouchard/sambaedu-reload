<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Workstation;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.2 / AC6.2 — Flush ciblé des caches packages WPKG.
 */
final class WpkgCacheFlushCommand extends Command
{
    protected $signature = 'wpkg:cache:flush
        {--workstation= : Hostname précis (sinon flush tous les postes)}';

    protected $description = 'Vide les caches `wpkg:packages:*` (un poste ou tous les postes).';

    protected $help = <<<'HELP'
    Vide le cache de la liste des paquets calculée pour les postes.

      <info>php artisan wpkg:cache:flush</info>                       tous les postes
      <info>php artisan wpkg:cache:flush --workstation=SALLE-B12-01</info>   un seul

    À utiliser quand un poste continue de se voir proposer d'anciennes applications
    après un changement d'affectation : c'est le calcul mis en cache qui n'a pas été
    invalidé.

    Sans danger : le cache se reconstruit tout seul à la sollicitation suivante.
    Pour le reconstruire tout de suite, enchaînez avec <info>wpkg:cache:warmup</info>.
    HELP;

    public function handle(): int
    {
        $hostname = $this->option('workstation');

        if ($hostname !== null) {
            Cache::forget(WorkstationPackagesResolver::cacheKey((string) $hostname));
            $this->info(sprintf('Cache flushed pour %s.', $hostname));

            return self::SUCCESS;
        }

        $hostnames = Workstation::query()->pluck('name');
        foreach ($hostnames as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }
            Cache::forget(WorkstationPackagesResolver::cacheKey($name));
        }

        $this->info(sprintf('Cache flushed pour %d postes.', $hostnames->count()));

        Log::channel('wpkg-deploy')->info('[wpkg:cache:flush] terminé', [
            'count' => $hostnames->count(),
        ]);

        return self::SUCCESS;
    }
}
