<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithSe4Extinction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Story 38.6 — Suppression définitive du FS legacy, post-GO uniquement.
 *
 * Envoie `/var/www/sambaedu.off` à la corbeille via `trash` ou `gio trash`
 * — JAMAIS `rm -rf` (doctrine projet). Refuse si l'extinction à blanc n'est
 * pas en place (FS legacy encore présent ou vhost actif) ou sans `--confirm`.
 */
class Se4PurgeCommand extends Command
{
    use InteractsWithSe4Extinction;

    protected $signature = 'se4:purge
        {--confirm : Confirme la suppression définitive (obligatoire)}';

    protected $description = 'Suppression définitive du FS legacy .off (trash, jamais rm -rf) — après le GO d\'observation';

    protected $help = <<<'HELP'
    Supprime DÉFINITIVEMENT l'arborescence du serveur SE4 mise de côté lors de son
    extinction.

      <info>php artisan se4:purge --confirm</info>

    <comment>Geste irréversible</comment> — le dernier de la séquence d'extinction. À ne jouer
    qu'après une période d'observation concluante, quand vous êtes certain que plus
    rien n'a besoin du legacy.

    Deux refus protègent l'opération : sans <comment>--confirm</comment>, et tant que l'extinction
    n'est pas effectivement en place (arborescence encore active ou hôte virtuel
    toujours servi).

    L'arborescence part à la corbeille du système, jamais par une suppression brutale
    — c'est une règle du projet, et cela laisse une ultime chance de récupération.
    HELP;

    public function handle(): int
    {
        if (! $this->ensureRoot()) {
            return self::FAILURE;
        }

        if (! $this->option('confirm')) {
            $this->error('Suppression définitive refusée sans --confirm.');
            $this->line('Vérifier le verdict GO (`php artisan se4:status`) puis relancer avec --confirm.');

            return self::FAILURE;
        }

        if (! $this->ensureLegacyPathConfigured()) {
            return self::FAILURE;
        }

        $legacyPath = $this->legacyPath();
        $offPath = $this->offPath();

        if (is_dir($legacyPath)) {
            $this->error(sprintf(
                'Refusé : %s existe encore — l\'extinction à blanc n\'est pas en place (`php artisan se4:unplug`).',
                $legacyPath,
            ));

            return self::FAILURE;
        }

        $vhostEnabled = $this->vhostEnabled();

        if ($vhostEnabled === null) {
            $this->error('Impossible de déterminer l\'état du vhost (a2query introuvable) — abandon.');

            return self::FAILURE;
        }

        if ($vhostEnabled) {
            $this->error('Refusé : le vhost sambaedu-legacy est encore actif — l\'extinction à blanc n\'est pas en place.');

            return self::FAILURE;
        }

        if (! is_dir($offPath)) {
            $this->info(sprintf('Rien à purger : %s absent.', $offPath));

            return self::SUCCESS;
        }

        $trashCommand = $this->resolveTrashCommand();
        if ($trashCommand === null) {
            $this->error('Aucun utilitaire de corbeille disponible (`trash` ou `gio`) — abandon. JAMAIS de rm -rf.');

            return self::FAILURE;
        }

        $result = Process::run(sprintf('%s %s', $trashCommand, escapeshellarg($offPath)));
        if (! $result->successful()) {
            $this->error('Échec de la mise à la corbeille : ' . trim($result->errorOutput() ?: $result->output()));

            return self::FAILURE;
        }

        $this->info(sprintf('%s envoyé à la corbeille (%s).', $offPath, $trashCommand));

        return self::SUCCESS;
    }

    /**
     * Détecte l'utilitaire de corbeille disponible : `trash` (trash-cli)
     * sinon `gio trash`. Null si aucun.
     */
    private function resolveTrashCommand(): ?string
    {
        if (Process::run('command -v trash')->successful()) {
            return 'trash';
        }

        if (Process::run('command -v gio')->successful()) {
            return 'gio trash';
        }

        return null;
    }
}
