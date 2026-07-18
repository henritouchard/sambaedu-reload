<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithSe4Extinction;
use Illuminate\Console\Command;

/**
 * Story 38.6 — Rapport d'extinction du legacy pour l'instance courante.
 *
 * Lecture seule (pas de garde root) : état de la bascule (vhost
 * `sambaedu-legacy`, dossiers `/var/www/sambaedu`{,`.off`}) + agrégation de
 * `legacy_catchall_logs` sur la fenêtre `--days` et verdict GO/NO-GO.
 * L'exit code reflète le verdict (0 = GO) pour le scripting et sert de
 * préflight à `se4:unplug`.
 */
class Se4StatusCommand extends Command
{
    use InteractsWithSe4Extinction;

    protected $signature = 'se4:status
        {--days=7 : Fenêtre d\'observation en jours}';

    protected $description = 'État de l\'extinction SE4 : bascule vhost/dossiers + verdict GO/NO-GO sur legacy_catchall_logs';

    public function handle(): int
    {
        if (! $this->ensureLegacyPathConfigured()) {
            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        return $this->renderStatus($days) ? self::SUCCESS : self::FAILURE;
    }
}
