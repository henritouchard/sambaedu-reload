<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Workstation;
use App\Wpkg\Deployment\Generators\WorkstationIniGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 15.2 / AC6.3 — Régénération `.ini` per-poste.
 */
final class WpkgIniRegenerateCommand extends Command
{
    protected $signature = 'wpkg:ini:regenerate
        {--all : Régénère pour tous les postes}
        {--workstation= : Hostname précis (mutex avec --all)}';

    protected $description = 'Régénère le fichier `.ini` WPKG d\'un ou de tous les postes.';

    protected $help = <<<'HELP'
    Régénère le fichier de configuration WPKG propre à chaque poste.

      <info>php artisan wpkg:ini:regenerate --all</info>
      <info>php artisan wpkg:ini:regenerate --workstation=SALLE-B12-01</info>

    Les deux options s'excluent : soit tout le parc, soit un poste.

    À lancer après un changement qui modifie ce que ces fichiers contiennent, quand
    on ne veut pas attendre leur régénération naturelle.
    HELP;

    public function handle(WorkstationIniGenerator $generator): int
    {
        $hostname = $this->option('workstation');
        $all = (bool) $this->option('all');

        if (! $all && $hostname === null) {
            $this->error('Préciser --all ou --workstation=HOSTNAME.');

            return self::INVALID;
        }

        if ($hostname !== null) {
            $workstation = Workstation::query()
                ->where('name', $hostname)
                ->with('wpkgOptions')
                ->first();

            if ($workstation === null) {
                $this->error(sprintf('Poste introuvable : %s', $hostname));

                return self::FAILURE;
            }

            $ok = $generator->generate($workstation);
            $this->info(sprintf('Regen %s : %s.', $workstation->name, $ok ? 'ok' : 'KO'));

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $workstations = Workstation::query()->with('wpkgOptions')->get();
        $bar = $this->output->createProgressBar($workstations->count());
        $bar->start();
        $errors = 0;
        foreach ($workstations as $workstation) {
            if (! $generator->generate($workstation)) {
                $errors++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        Log::channel('wpkg-deploy')->info('[wpkg:ini:regenerate] terminé', [
            'count' => $workstations->count(),
            'errors' => $errors,
        ]);

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
