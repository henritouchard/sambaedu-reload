<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithSe4Extinction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Story 38.6 — Rollback de l'extinction à blanc, en une commande.
 *
 * Symétrique inverse de `se4:unplug` : `mv .off → legacy` puis
 * `a2ensite sambaedu-legacy` + reload. Le reload est INCONDITIONNEL (cf.
 * reloadApache()) : relancer après un échec mi-séquence converge toujours
 * vers l'état rebranché.
 */
class Se4ReplugCommand extends Command
{
    use InteractsWithSe4Extinction;

    protected $signature = 'se4:replug';

    protected $description = 'Rollback de l\'extinction à blanc : restaure le FS legacy depuis .off et réactive le vhost sambaedu-legacy';

    protected $help = <<<'HELP'
    Rebranche le serveur SE4 : restaure son arborescence et réactive son hôte virtuel.

      <info>php artisan se4:replug</info>

    C'est le RETOUR ARRIÈRE exact de <info>se4:unplug</info>, et la raison pour laquelle
    l'extinction peut être tentée sereinement.

    Rejouable : le rechargement d'Apache est inconditionnel, de sorte qu'une commande
    relancée après un échec en cours de séquence converge toujours vers l'état
    rebranché.

    Sans effet une fois <info>se4:purge</info> passée — celle-là n'a pas de retour arrière.
    HELP;

    public function handle(): int
    {
        if (! $this->ensureRoot() || ! $this->ensureLegacyPathConfigured()) {
            return self::FAILURE;
        }

        $legacyPath = $this->legacyPath();
        $offPath = $this->offPath();
        $vhostEnabled = $this->vhostEnabled();
        $offPresent = is_dir($offPath);

        if ($vhostEnabled === null) {
            $this->error('Impossible de déterminer l\'état du vhost (a2query introuvable) — abandon.');

            return self::FAILURE;
        }

        if ($vhostEnabled && ! $offPresent) {
            $this->info('Legacy déjà branché (vhost actif, pas de FS .off).');
            $this->line('Reload Apache par sûreté (converge après un éventuel run interrompu).');

            return $this->reloadApache() ? self::SUCCESS : self::FAILURE;
        }

        if ($offPresent && is_dir($legacyPath)) {
            $this->error(sprintf(
                'Collision : %s ET %s existent tous les deux. Résoudre manuellement avant de relancer.',
                $legacyPath,
                $offPath,
            ));

            return self::FAILURE;
        }

        if ($offPresent) {
            $result = Process::run(sprintf('mv %s %s', escapeshellarg($offPath), escapeshellarg($legacyPath)));
            if (! $result->successful()) {
                $this->error('Échec de la restauration du FS legacy : ' . trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }

            $this->info(sprintf('FS legacy restauré : %s → %s', $offPath, $legacyPath));
        } else {
            $this->line('Pas de FS .off à restaurer — étape sautée.');
        }

        if (! $vhostEnabled) {
            $result = Process::run('a2ensite sambaedu-legacy');
            if (! $result->successful()) {
                $this->error('Échec a2ensite sambaedu-legacy : ' . trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }

            $this->info('Vhost sambaedu-legacy réactivé.');
        } else {
            $this->line('Vhost sambaedu-legacy déjà actif — a2ensite sauté.');
        }

        if (! $this->reloadApache()) {
            return self::FAILURE;
        }
        $this->info('Apache rechargé.');

        $this->newLine();
        $this->info('Legacy rebranché.');

        return self::SUCCESS;
    }
}
