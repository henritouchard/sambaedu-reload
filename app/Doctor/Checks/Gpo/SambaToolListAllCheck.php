<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Support\Facades\Process;

/**
 * Test fonctionnel : exécute réellement `samba-tool gpo listall` avec
 * l'option Kerberos configurée. C'est LA preuve que toute la chaîne
 * (binaire + DC + ccache OU passdb.tdb + permissions) fonctionne pour
 * l'usage métier réel de l'UI admin GPO (Story 16.9).
 *
 * Read-only : `gpo listall` ne modifie rien côté AD.
 */
final class SambaToolListAllCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'gpo';
    }

    public function name(): string
    {
        return 'samba-tool gpo listall';
    }

    public function run(): CheckResult
    {
        $binPath = (string) config('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');
        $kerb = (string) config('sambaedu.gpo.kerb_option', '');

        $command = [$binPath, 'gpo', 'listall'];
        if ($kerb !== '') {
            $command[] = $kerb;
        }

        $result = Process::timeout(15)->run($command);

        if ($result->successful()) {
            $gpoCount = substr_count($result->output(), 'GPO          :');

            return CheckResult::ok(sprintf('Exit 0 — %d GPO(s) listée(s).', $gpoCount));
        }

        $stderr = $result->errorOutput();
        $firstError = '';
        foreach (explode("\n", $stderr) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'ldb:')) {
                continue;
            }
            $firstError = $line;
            break;
        }
        $firstError = $firstError !== '' ? $firstError : 'erreur inconnue (stderr vide)';

        $fix = null;
        if (str_contains($stderr, 'smb_gss_krb5_import_cred failed')) {
            $fix = sprintf(
                'Pas de ccache Kerberos exploitable. Initialiser : `sudo -u %s kinit Administrator`. '
                . 'Ou passer kerb_option à `--use-kerberos=desired` dans config/sambaedu.php (fallback NTLM).',
                get_current_user(),
            );
        } elseif (str_contains($stderr, 'NT_STATUS_CANT_ACCESS_DOMAIN_INFO')) {
            $fix = 'Compte machine inaccessible. Vérifier les ACLs Samba (check Samba private files) et l\'appartenance au domaine.';
        }

        return CheckResult::error(
            sprintf('Exit %d — %s', $result->exitCode() ?? -1, $firstError),
            $fix,
        );
    }
}
