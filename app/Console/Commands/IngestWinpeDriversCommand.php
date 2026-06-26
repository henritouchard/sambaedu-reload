<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ipxe\Iso\Exceptions\WinpeDriverIngestionException;
use App\Ipxe\Iso\Services\WinpeDriverIngestor;
use Illuminate\Console\Command;

/**
 * Story 3.10 — AC4.1-4.3 — Ingestion CLI d'une archive de pilotes NIC
 * (`.exe` InnoSetup Lenovo via `innoextract`, `.zip` Intel via `unzip`) vers
 * le pack persistant `winpe_drivers_path/<famille>/`.
 *
 *   php artisan ipxe:winpe-drivers:ingest <famille> <chemin-archive>
 *
 * Toute la logique vit dans {@see WinpeDriverIngestor} (service PARTAGÉ avec le
 * composant Livewire `iso-windows` — D3, zéro duplication). La commande se
 * limite à déléguer, afficher le récap des `.inf` ingérés, et mapper les
 * échecs métier vers un exit non-zéro (AC4.3).
 */
final class IngestWinpeDriversCommand extends Command
{
    protected $signature = 'ipxe:winpe-drivers:ingest
        {famille : Nom de la famille de pilotes (ex. intel-i219)}
        {archive : Chemin de l\'archive (.exe Lenovo / .zip Intel)}';

    protected $description = 'Ingère une archive de pilotes NIC WinPE (.exe/.zip) dans le pack persistant winpe-drivers.';

    public function handle(WinpeDriverIngestor $ingestor): int
    {
        $famille = (string) $this->argument('famille');
        $archive = (string) $this->argument('archive');

        try {
            $infFiles = $ingestor->ingest($famille, $archive);
        } catch (WinpeDriverIngestionException $e) {
            $this->error('Ingestion échouée : ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Pilotes ingérés dans la famille « %s » : %d fichier(s) .inf.',
            $famille,
            count($infFiles),
        ));
        foreach ($infFiles as $inf) {
            $this->line('  - ' . $inf);
        }
        $this->line('');
        $this->info('Le pack sera injecté dans le boot.wim à la prochaine extraction d\'ISO Windows.');

        return self::SUCCESS;
    }
}
