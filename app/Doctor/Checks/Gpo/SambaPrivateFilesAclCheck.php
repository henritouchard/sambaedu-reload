<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

/**
 * Vérifie l'accessibilité des fichiers Samba critiques
 * (`passdb.tdb`, `netlogon_creds_cli.tdb`) par l'user PHP-FPM courant.
 *
 * Sur un serveur SE4FS membre de domaine, ces fichiers contiennent les
 * credentials machine permettant à `samba-tool` de s'authentifier
 * silencieusement auprès du DC AD.
 */
final class SambaPrivateFilesAclCheck implements EnvironmentCheck
{
    private const PASSDB_TDB = '/var/lib/samba/private/passdb.tdb';

    private const NETLOGON_TDB = '/var/lib/samba/private/netlogon_creds_cli.tdb';

    public function tag(): string
    {
        return 'gpo';
    }

    public function name(): string
    {
        return 'Samba private files';
    }

    public function run(): CheckResult
    {
        $details = [];
        $worstLevel = 'ok';
        $fixes = [];

        foreach ([self::PASSDB_TDB, self::NETLOGON_TDB] as $file) {
            if (! file_exists($file)) {
                $details[] = sprintf('%s absent', basename($file));
                $worstLevel = $this->bump($worstLevel, 'warn');
                $fixes[] = sprintf('Normal sur un DC (secrets.ldb à la place). Sur un serveur membre : vérifier que `samba-common` est installé et que le serveur est joint au domaine.');

                continue;
            }
            if (! is_readable($file)) {
                $details[] = sprintf('%s non lisible', basename($file));
                $worstLevel = $this->bump($worstLevel, 'error');
                $fixes[] = sprintf('`sudo setfacl -m u:%s:r %s`', get_current_user(), $file);

                continue;
            }
            $details[] = sprintf('%s lisible', basename($file));
        }

        $detail = implode(' · ', $details);
        $fix = $fixes !== [] ? implode(' / ', array_unique($fixes)) : null;

        return match ($worstLevel) {
            'ok' => CheckResult::ok($detail),
            'warn' => CheckResult::warn($detail, $fix),
            'error' => CheckResult::error($detail, $fix),
        };
    }

    /**
     * Promote `ok < warn < error`.
     */
    private function bump(string $current, string $candidate): string
    {
        $rank = ['ok' => 0, 'warn' => 1, 'error' => 2];

        return $rank[$candidate] > $rank[$current] ? $candidate : $current;
    }
}
