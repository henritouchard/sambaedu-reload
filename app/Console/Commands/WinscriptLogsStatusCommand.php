<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ManagesScriptLoggingFlag;
use Illuminate\Console\Command;

/**
 * Story 17.5 / AC1.3 — Affiche l'état effectif du logging centralisé des
 * scripts d'applications (lecture seule, AUCUNE écriture fichier).
 *
 * Lit la valeur via `config('sambaedu.scripts.logging.enabled', false)`
 * (source de vérité partagée avec l'Assembler 17.2) et affiche l'URL
 * d'ingestion résolue pour aider l'opérateur à vérifier la cible.
 */
final class WinscriptLogsStatusCommand extends Command
{
    use ManagesScriptLoggingFlag;

    protected $signature = 'winscript-logs:status';

    protected $description = 'Affiche l\'état courant du logging centralisé des scripts d\'applications (lecture seule).';

    protected $help = <<<'HELP'
    Affiche si la journalisation centralisée des scripts d'applications est active, et
    vers quelle adresse les exécutions sont remontées.

      <info>php artisan winscript-logs:status</info>

    Strictement en lecture, aucune écriture.

    L'état affiché est l'état EFFECTIF — celui que voit réellement le générateur de
    scripts, cache de configuration compris — et pas ce que vous croyez avoir écrit
    dans le fichier d'environnement. C'est précisément ce qui rend cette commande
    utile après une bascule qui « ne prend pas ».
    HELP;

    public function handle(): int
    {
        $enabled = $this->loggingFlagEnabled();

        $this->info(sprintf(
            'Logging des scripts d\'applications : %s',
            $enabled ? 'ACTIVÉ' : 'DÉSACTIVÉ',
        ));

        $this->newLine();
        $this->line(sprintf('URL d\'ingestion : %s', $this->resolveIngestUrl()));

        if ($enabled) {
            $this->line('Les scripts assemblés (cmd / bash) sont wrappés et POSTent leur résultat d\'exécution.');
        } else {
            $this->line('Comportement iso-legacy : les scripts assemblés ne sont pas wrappés.');
        }

        return self::SUCCESS;
    }
}
