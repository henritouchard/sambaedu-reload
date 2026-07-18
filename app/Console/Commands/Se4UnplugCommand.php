<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithSe4Extinction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Story 38.6 — Extinction à blanc du legacy, réversible et rejouable par
 * instance.
 *
 * Séquence : `a2dissite sambaedu-legacy` → `systemctl reload apache2` →
 * `mv /var/www/sambaedu /var/www/sambaedu.off`. Le reload est INCONDITIONNEL
 * (cf. reloadApache()) : relancer la commande après un échec mi-séquence
 * converge toujours vers l'état éteint. Le préflight rejoue le verdict de
 * `se4:status` : NO-GO (hits legacy récents) → abort sauf `--force` — c'est
 * le garde-fou lab1/exception Linux Q4, dans le code et pas dans un runbook.
 * Rollback : `php artisan se4:replug`.
 */
class Se4UnplugCommand extends Command
{
    use InteractsWithSe4Extinction;

    protected $signature = 'se4:unplug
        {--days=7 : Fenêtre du préflight en jours}
        {--force : Éteindre malgré un préflight NO-GO (hits legacy récents)}';

    protected $description = 'Extinction à blanc du legacy : désactive le vhost sambaedu-legacy et déplace le FS legacy vers .off (réversible via se4:replug)';

    public function handle(): int
    {
        if (! $this->ensureRoot() || ! $this->ensureLegacyPathConfigured()) {
            return self::FAILURE;
        }

        $legacyPath = $this->legacyPath();
        $offPath = $this->offPath();
        $vhostEnabled = $this->vhostEnabled();
        $legacyPresent = is_dir($legacyPath);

        if ($vhostEnabled === null) {
            $this->error('Impossible de déterminer l\'état du vhost (a2query introuvable) — abandon.');

            return self::FAILURE;
        }

        if (! $vhostEnabled && ! $legacyPresent) {
            $this->info('Extinction déjà en place (vhost inactif, FS legacy absent).');
            $this->line('Reload Apache par sûreté (converge après un éventuel run interrompu).');

            return $this->reloadApache() ? self::SUCCESS : self::FAILURE;
        }

        // Collision : impossible de déplacer le legacy si .off existe déjà.
        // On n'écrase JAMAIS — résolution manuelle requise.
        if ($legacyPresent && is_dir($offPath)) {
            $this->error(sprintf(
                'Collision : %s ET %s existent tous les deux. Résoudre manuellement avant de relancer.',
                $legacyPath,
                $offPath,
            ));

            return self::FAILURE;
        }

        $days = max(1, (int) $this->option('days'));

        if (! $this->renderStatus($days, $vhostEnabled) && ! $this->option('force')) {
            $this->newLine();
            $this->error('Préflight NO-GO : des routes legacy sont encore appelées — extinction refusée.');
            $this->line('Traiter les hits ci-dessus (fix ou story), ou relancer avec --force en connaissance de cause.');
            $this->line('Ne JAMAIS forcer sur une instance dont le canal Linux est encore vivant (exception Q4 — lab1).');

            return self::FAILURE;
        }

        if ($vhostEnabled) {
            $result = Process::run('a2dissite sambaedu-legacy');
            if (! $result->successful()) {
                $this->error('Échec a2dissite sambaedu-legacy : ' . trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }

            $this->info('Vhost sambaedu-legacy désactivé.');
        } else {
            $this->line('Vhost sambaedu-legacy déjà inactif — a2dissite sauté.');
        }

        if (! $this->reloadApache()) {
            return self::FAILURE;
        }
        $this->info('Apache rechargé.');

        if ($legacyPresent) {
            $result = Process::run(sprintf('mv %s %s', escapeshellarg($legacyPath), escapeshellarg($offPath)));
            if (! $result->successful()) {
                $this->error('Échec du déplacement du FS legacy : ' . trim($result->errorOutput() ?: $result->output()));

                return self::FAILURE;
            }

            $this->info(sprintf('FS legacy déplacé : %s → %s', $legacyPath, $offPath));
        } else {
            $this->line('FS legacy déjà absent — étape sautée.');
        }

        $this->newLine();
        $this->info('Extinction à blanc en place. Le catchall répond 404 (loggé), les tombstones restent inchangés.');
        $this->line('Rollback en une commande : php artisan se4:replug');

        return self::SUCCESS;
    }
}
