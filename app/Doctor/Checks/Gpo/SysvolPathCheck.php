<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

/**
 * Vérifie que `config('sambaedu.gpo.sysvol_path')` existe et est
 * lisible/traversable par l'user PHP-FPM courant. Requis par Stories
 * 16.3, 16.4 (lecture/écriture `.pol`, `.xml`, `.ini` de policies).
 */
final class SysvolPathCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'gpo';
    }

    public function name(): string
    {
        return 'SYSVOL path';
    }

    public function run(): CheckResult
    {
        $path = (string) config('sambaedu.gpo.sysvol_path', '/var/lib/samba/sysvol');

        if (! is_dir($path)) {
            return CheckResult::error(
                sprintf('SYSVOL absent : %s', $path),
                'Vérifier que Samba est installé et configuré, ou ajuster config(\'sambaedu.gpo.sysvol_path\').',
            );
        }

        if (! is_readable($path)) {
            return CheckResult::error(
                sprintf('%s non lisible par %s.', $path, get_current_user()),
                sprintf('`sudo setfacl -R -m u:%s:rX %s`', get_current_user(), $path),
            );
        }

        // Heuristique : un SYSVOL valide contient au moins un sous-dossier
        // au niveau du domaine (par convention `<domain>/Policies/`).
        $subDirs = array_filter(
            scandir($path) ?: [],
            fn ($e) => $e !== '.' && $e !== '..' && is_dir($path . '/' . $e),
        );

        if ($subDirs === []) {
            return CheckResult::warn(
                sprintf('%s lisible mais vide (aucun sous-dossier domaine).', $path),
                'Vérifier que le serveur est bien joint au domaine et que SYSVOL est synchronisé.',
            );
        }

        return CheckResult::ok(sprintf(
            '%s lisible (%d sous-dossier(s) domaine).',
            $path,
            count($subDirs),
        ));
    }
}
