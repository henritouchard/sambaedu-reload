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

    protected $help = <<<'HELP'
    Dresse l'état d'extinction du serveur SE4 pour cette instance : hôte virtuel actif
    ou non, arborescence en place ou mise de côté, et surtout ce qui a ENCORE frappé
    le legacy sur la période d'observation.

      <info>php artisan se4:status</info>
      <info>php artisan se4:status --days=30</info>

    Strictement en lecture, exécutable à tout moment.

    Le verdict conclut sur la possibilité d'éteindre, et le CODE DE RETOUR le reflète
    (<info>0</info> = on peut y aller) — de quoi enchaîner dans un script. C'est le contrôle
    préalable qu'exécute <info>se4:unplug</info> avant d'agir.
    HELP;

    public function handle(): int
    {
        if (! $this->ensureLegacyPathConfigured()) {
            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        return $this->renderStatus($days) ? self::SUCCESS : self::FAILURE;
    }
}
