<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Wpkg\Deployment\Services\WpkgBundleGenerator;
use Illuminate\Console\Command;

/**
 * Story 27.5 (D6/D7) — Génère le bundle WPKG NATIF SE5 pré-substitué dans le
 * sous-dossier public servi en STATIQUE par Apache (`config('agent.wpkg_bundle_path')`).
 *
 * À lancer à la pose / au changement de conf (`se4fs_name`) — PAS par requête
 * (zéro charge Laravel sur le download : Apache sert le statique). Le profil
 * par-hôte (`profiles.xml`/`hosts.xml`) n'est PAS dans le bundle : l'agent le
 * dépose localement (D9).
 *
 * Rappel /vm : après génération, chown www-admin (uid 599) sur le sous-dossier
 * (convention storage non versionnée) sinon le serving Apache échoue en 404.
 */
final class WpkgBundleGenerateCommand extends Command
{
    protected $signature = 'wpkg:bundle';

    protected $description = 'Génère le bundle WPKG natif SE5 pré-substitué (scripts + packages.xml) servi statiquement par Apache.';

    public function handle(WpkgBundleGenerator $generator): int
    {
        $result = $generator->generate();

        $this->info(sprintf(
            'Bundle WPKG généré dans %s (%d fichiers, SE4FS_NAME=%s).',
            $result['path'],
            count($result['files']),
            $result['se4fs_name'],
        ));
        foreach ($result['files'] as $file) {
            $this->line("  - {$file}");
        }
        $this->comment('Pensez au chown www-admin (uid 599) sur le sous-dossier (/vm) — sinon serving Apache 404.');

        return self::SUCCESS;
    }
}
