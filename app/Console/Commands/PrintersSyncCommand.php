<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Printer;
use App\Services\Print\CupsPrinterService;
use App\Services\Print\Exceptions\CupsDaemonDownException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 6.1 — Réconciliation table SER `printers` ↔ état CUPS réel.
 *
 * Exécutée :
 *  - quotidiennement à 03:30 par `app/Console/Kernel.php`
 *  - à la demande : `php artisan printers:sync [--dry-run]`
 *
 * Idempotente : la relancer ne change rien quand l'état est aligné.
 *
 * Actions :
 *  1. CUPS contient des imprimantes absentes de SER → INSERT (orphan=false,
 *     created_by_user_id=null, description_ser=null).
 *  2. SER contient des rows non-orphan absents de CUPS → UPDATE orphan=true
 *     (PAS de delete : préserve les rattachements pour réintroduction).
 *  3. SER contient des rows orphan présents dans CUPS → UPDATE orphan=false
 *     (réintroduction).
 *
 * Fix #12 : si CUPS est injoignable (`isHealthy()` échoue ou `CupsDaemonDownException`
 * levée), la commande interrompt sans marquer les rows SER comme orphelins — évite
 * la perte de visibilité des imprimantes pour les délégués lors d'une interruption CUPS.
 *
 * Logs préfixés `printers:sync`.
 */
class PrintersSyncCommand extends Command
{
    protected $signature = 'printers:sync
        {--dry-run : Affiche les actions sans écrire en DB}';

    protected $description = 'Réconcilie la table `printers` SER avec l\'état réel de CUPS (idempotent).';

    protected $help = <<<'HELP'
    Réconcilie la liste des imprimantes connues de SE5 avec l'état réel du serveur
    d'impression.

      <info>php artisan printers:sync --dry-run</info>   ce qui serait fait
      <info>php artisan printers:sync</info>

    Trois cas traités :

      · imprimante présente sur le serveur mais inconnue de SE5 → ajoutée ;
      · imprimante connue de SE5 mais disparue du serveur → marquée ORPHELINE, jamais
        supprimée : ses rattachements aux groupes survivent, et une réintroduction la
        retrouve telle quelle ;
      · imprimante orpheline qui réapparaît → remise en service.

    Idempotente : la relancer sur un état déjà aligné ne change rien.

    Planifiée quotidiennement.
    HELP;

    public function handle(CupsPrinterService $cups): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Fix #12 : vérifier la santé CUPS avant d'interroger la liste.
        if (!$cups->isHealthy()) {
            Log::error('printers:sync — daemon CUPS injoignable, synchronisation annulée', [
                'dry_run' => $dryRun,
            ]);
            $this->error('CUPS injoignable — synchronisation annulée pour préserver les rattachements SER.');
            return Command::FAILURE;
        }

        try {
            $cupsByName = collect($cups->listPrinters())->keyBy('name');
        } catch (CupsDaemonDownException $e) {
            // Défense en profondeur si isHealthy() a divergé.
            Log::error('printers:sync — CupsDaemonDownException lors de listPrinters, annulé', [
                'message' => $e->getMessage(),
                'dry_run' => $dryRun,
            ]);
            $this->error('CUPS injoignable — synchronisation annulée.');
            return Command::FAILURE;
        }

        $serByName = Printer::all()->keyBy('cups_name');

        $toAdd = $cupsByName->keys()->diff($serByName->keys());
        $toMarkOrphan = $serByName->reject(fn(Printer $p) => $p->orphan)
            ->keys()
            ->diff($cupsByName->keys());
        $toRestore = $serByName->filter(fn(Printer $p) => $p->orphan)
            ->keys()
            ->intersect($cupsByName->keys());

        $stats = [
            'added' => $toAdd->count(),
            'marked_orphan' => $toMarkOrphan->count(),
            'restored' => $toRestore->count(),
            'dry_run' => $dryRun,
        ];

        Log::info('printers:sync — diff calculé', [
            'added' => $toAdd->all(),
            'marked_orphan' => $toMarkOrphan->all(),
            'restored' => $toRestore->all(),
            'dry_run' => $dryRun,
        ]);

        if (!$dryRun) {
            foreach ($toAdd as $name) {
                Printer::create([
                    'cups_name' => $name,
                    'orphan' => false,
                    'created_by_user_id' => null,
                    'description_ser' => null,
                ]);
            }

            if ($toMarkOrphan->isNotEmpty()) {
                Printer::whereIn('cups_name', $toMarkOrphan->all())
                    ->update(['orphan' => true]);
            }

            if ($toRestore->isNotEmpty()) {
                Printer::whereIn('cups_name', $toRestore->all())
                    ->update(['orphan' => false]);
            }
        }

        $this->info(sprintf(
            'printers:sync %s — ajoutées : %d, marquées orphan : %d, restaurées : %d.',
            $dryRun ? '[dry-run]' : 'OK',
            $stats['added'],
            $stats['marked_orphan'],
            $stats['restored'],
        ));

        return Command::SUCCESS;
    }
}
