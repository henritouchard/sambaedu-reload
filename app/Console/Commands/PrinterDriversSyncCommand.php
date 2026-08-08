<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Printer;
use App\Models\PrinterDriver;
use App\Services\Print\Exceptions\KerberosTicketException;
use App\Services\Print\Exceptions\SambaUnavailableException;
use App\Services\Print\PrintDriverService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Story 6.2 — Réconciliation table SER `printer_drivers` ↔ Samba.
 *
 * Exécutée :
 *  - quotidiennement à 03:35 par `app/Console/Kernel.php` (5 min après
 *    `printers:sync` 03:30, monitoring séparé — D7).
 *  - à la demande : `php artisan printer-drivers:sync [--dry-run]`.
 *
 * Idempotente : la relancer ne change rien quand l'état est aligné.
 *
 * Actions :
 *  1. Samba contient des drivers absents de SER → INSERT (orphan=false,
 *     source=`synced`, `created_by_user_id`=null, sans rattachement
 *     printer — l'association se gère via la modale upload).
 *  2. SER contient des rows non-orphan absents de Samba → UPDATE
 *     orphan=true (PAS de delete : préserve audit + rattachement
 *     historique).
 *  3. SER contient des rows orphan présents dans Samba → UPDATE
 *     orphan=false (réintroduction).
 *
 * Fix #12 décalqué 6.1 : si `isSambaHealthy()` retourne false (Kerberos
 * KO, daemon down) → log error + Command::FAILURE + AUCUN row SER
 * marqué orphan. Évite la perte de visibilité massive en cas
 * d'interruption transitoire.
 *
 * Auto-attachement AC4 (Q1A — décision Henri 2026-05-20) :
 * la sync interroge `listPrintersOnSe4fs()` (= `rpcclient enumprinters
 * <se4fs>`) pour obtenir les associations (cups_name → driver_name)
 * effectives côté Samba. Pour chaque association détectée, si la ligne
 * SER `(printer_cups_name, x64)` n'existe pas ET qu'un row `Printer`
 * SER existe pour ce cups_name, on INSERT (source=`synced`,
 * created_by_user_id=null). Si la résolution ne trouve aucun row
 * `Printer` SER (cups_name absent de la table), on log warning + skip
 * (rattachement manuel UI requis).
 *
 * Logs préfixés `[printer-drivers:sync]`.
 */
class PrinterDriversSyncCommand extends Command
{
    protected $signature = 'printer-drivers:sync
        {--dry-run : Affiche les actions sans écrire en DB}';

    protected $description = 'Réconcilie la table `printer_drivers` SER avec l\'état réel de Samba (idempotent).';

    protected $help = <<<'HELP'
    Réconcilie la liste des pilotes d'impression connus de SE5 avec ceux réellement
    publiés par Samba.

      <info>php artisan printer-drivers:sync --dry-run</info>   ce qui serait fait
      <info>php artisan printer-drivers:sync</info>

    Trois cas traités :

      · pilote présent dans Samba mais inconnu de SE5 → ajouté ;
      · pilote connu de SE5 mais disparu de Samba → marqué ORPHELIN, jamais supprimé,
        de sorte que ses rattachements survivent à une réintroduction ;
      · pilote orphelin qui réapparaît dans Samba → remis en service.

    Idempotente : la relancer sur un état déjà aligné ne change rien.

    Planifiée quotidiennement, peu après la synchronisation des imprimantes.
    HELP;

    public function handle(PrintDriverService $driverService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Fix #12 6.1 décalqué : pré-flight santé Samba.
        if (!$driverService->isSambaHealthy()) {
            Log::error('[printer-drivers:sync] — Samba injoignable, synchronisation annulée', [
                'dry_run' => $dryRun,
            ]);
            $this->error('Samba injoignable — synchronisation annulée pour préserver l\'audit SER.');
            return Command::FAILURE;
        }

        try {
            $sambaList = collect($driverService->listAllDrivers())
                ->keyBy(fn(array $d) => $d['driver_name'] . '|' . $d['architecture']);
        } catch (SambaUnavailableException $e) {
            Log::error('[printer-drivers:sync] — SambaUnavailableException sur listAllDrivers, annulé', [
                'message' => $e->getMessage(),
                'dry_run' => $dryRun,
            ]);
            $this->error('Samba injoignable — synchronisation annulée.');
            return Command::FAILURE;
        } catch (KerberosTicketException $e) {
            Log::error('[printer-drivers:sync] — KerberosTicketException sur listAllDrivers, annulé', [
                'message' => $e->getMessage(),
                'dry_run' => $dryRun,
            ]);
            $this->error('Authentification Samba expirée — synchronisation annulée.');
            return Command::FAILURE;
        }

        $serRows = PrinterDriver::all()
            ->keyBy(fn(PrinterDriver $d) => $d->driver_name . '|' . $d->architecture);

        $sambaKeys = $sambaList->keys();
        $serKeys = $serRows->keys();

        // Drivers à marquer orphan (présents SER non-orphan, absents Samba).
        $toMarkOrphan = $serRows
            ->reject(fn(PrinterDriver $d) => $d->orphan)
            ->keys()
            ->diff($sambaKeys);

        // Drivers à restaurer (présents SER orphan, présents Samba).
        $toRestore = $serRows
            ->filter(fn(PrinterDriver $d) => $d->orphan)
            ->keys()
            ->intersect($sambaKeys);

        // Q1A — résolution auto-attach : lecture associations effectives
        // Samba (cups_name → driver_name) via enumprinters sur SE4FS. Pour
        // chaque association non encore matérialisée en SER, INSERT si
        // un row `Printer` existe pour ce cups_name (sinon log warning).
        $se4fsAssocs = [];
        try {
            $se4fsAssocs = $driverService->listPrintersOnSe4fs();
        } catch (SambaUnavailableException $e) {
            Log::error('[printer-drivers:sync] — SambaUnavailableException sur listPrintersOnSe4fs (auto-attach skip)', [
                'message' => $e->getMessage(),
            ]);
            // On continue : le marquage orphan reste valide (Samba a
            // répondu sur enumdrivers, c'est juste enumprinters qui
            // échoue — défensif).
        } catch (\Throwable $e) {
            Log::warning('[printer-drivers:sync] — listPrintersOnSe4fs échoué (auto-attach skip)', [
                'message' => $e->getMessage(),
            ]);
        }

        $printerCupsNamesInSer = Printer::query()->pluck('cups_name')->all();
        $toAutoAttach = [];   // [['cups_name' => …, 'driver_name' => …], …]
        $toLogMissing = [];   // cups_name absents de la table Printer SER
        foreach ($se4fsAssocs as $assoc) {
            $cupsName = $assoc['smb_name'];
            $driverName = $assoc['smb_driver'];
            if ($driverName === '' || $driverName === 'NO DRIVER') {
                continue;
            }
            $key = $driverName . '|x64';
            // Déjà matérialisé en SER pour ce printer+arch ?
            $exists = PrinterDriver::query()
                ->where('printer_cups_name', $cupsName)
                ->where('architecture', 'x64')
                ->where('driver_name', $driverName)
                ->exists();
            if ($exists) {
                continue;
            }
            if (!in_array($cupsName, $printerCupsNamesInSer, true)) {
                $toLogMissing[] = ['cups_name' => $cupsName, 'driver_name' => $driverName];
                continue;
            }
            $toAutoAttach[] = ['cups_name' => $cupsName, 'driver_name' => $driverName];
        }

        $stats = [
            'auto_attached' => 0,
            'pending_orphan_se4fs' => count($toLogMissing),
            'marked_orphan' => 0,
            'restored' => 0,
            'dry_run' => $dryRun,
        ];

        Log::info('[printer-drivers:sync] — diff calculé', [
            'to_auto_attach' => $toAutoAttach,
            'to_log_missing' => $toLogMissing,
            'marked_orphan' => $toMarkOrphan->all(),
            'restored' => $toRestore->all(),
            'dry_run' => $dryRun,
        ]);

        if (!$dryRun) {
            // Fix #5 — re-count via la valeur retournée par UPDATE (multi-rows).
            if ($toMarkOrphan->isNotEmpty()) {
                foreach ($toMarkOrphan as $key) {
                    [$driverName, $architecture] = explode('|', $key, 2);
                    $stats['marked_orphan'] += PrinterDriver::query()
                        ->where('driver_name', $driverName)
                        ->where('architecture', $architecture)
                        ->update(['orphan' => true]);
                }
            }
            if ($toRestore->isNotEmpty()) {
                foreach ($toRestore as $key) {
                    [$driverName, $architecture] = explode('|', $key, 2);
                    $stats['restored'] += PrinterDriver::query()
                        ->where('driver_name', $driverName)
                        ->where('architecture', $architecture)
                        ->update(['orphan' => false]);
                }
            }
            foreach ($toAutoAttach as $assoc) {
                PrinterDriver::create([
                    'printer_cups_name' => $assoc['cups_name'],
                    'architecture' => 'x64',
                    'driver_name' => $assoc['driver_name'],
                    'source' => 'synced',
                    'orphan' => false,
                    'notes' => null,
                    'created_by_user_id' => null,
                ]);
                $stats['auto_attached']++;
            }
        } else {
            // En dry-run, on rapporte les comptages prédictifs.
            $stats['marked_orphan'] = $toMarkOrphan->count();
            $stats['restored'] = $toRestore->count();
            $stats['auto_attached'] = count($toAutoAttach);
        }

        foreach ($toLogMissing as $miss) {
            Log::warning('[printer-drivers:sync] — driver Samba référence cups_name absent SER (rattachement manuel requis)', [
                'cups_name' => $miss['cups_name'],
                'driver_name' => $miss['driver_name'],
            ]);
        }

        $this->info(sprintf(
            'printer-drivers:sync %s — auto-attachés : %d, cups_name absent SER : %d, marqués orphan : %d, restaurés : %d.',
            $dryRun ? '[dry-run]' : 'OK',
            $stats['auto_attached'],
            $stats['pending_orphan_se4fs'],
            $stats['marked_orphan'],
            $stats['restored'],
        ));

        return Command::SUCCESS;
    }
}
