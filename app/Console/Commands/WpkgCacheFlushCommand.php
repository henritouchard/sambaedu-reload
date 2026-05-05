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
