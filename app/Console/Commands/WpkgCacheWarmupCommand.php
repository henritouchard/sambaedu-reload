<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Workstation;
use App\Wpkg\Deployment\Services\WorkstationPackagesResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.2 / AC6.1 — Pré-calcul du cache `wpkg:packages:{hostname}`.
 */
final class WpkgCacheWarmupCommand extends Command
{
    protected $signature = 'wpkg:cache:warmup
        {--all : Pré-calcule pour tous les postes}
        {--workstation= : Hostname précis (mutex avec --all)}';

    protected $description = 'Pré-remplit le cache packages WPKG pour un poste ou tous les postes.';

    public function handle(WorkstationPackagesResolver $resolver): int
    {
        $hostname = $this->option('workstation');
        $all = (bool) $this->option('all');

        if (! $all && $hostname === null) {
            $this->error('Préciser --all ou --workstation=HOSTNAME.');

            return self::INVALID;
        }

        if ($hostname !== null) {
            $packages = $resolver->resolve((string) $hostname);
            $this->info(sprintf('Cache warm pour %s : %d packages.', $hostname, $packages->count()));

            return self::SUCCESS;
        }

        $hostnames = Workstation::query()->pluck('name');
        $bar = $this->output->createProgressBar($hostnames->count());
        $bar->start();
        foreach ($hostnames as $name) {
            if (! is_string($name) || $name === '') {
                $bar->advance();

                continue;
            }
            $resolver->resolve($name);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        Log::channel('wpkg-deploy')->info('[wpkg:cache:warmup] terminé', [
            'count' => $hostnames->count(),
        ]);

        return self::SUCCESS;
    }
}
