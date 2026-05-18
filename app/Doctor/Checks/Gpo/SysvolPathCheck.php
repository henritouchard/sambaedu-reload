<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Support\Facades\Process;

/**
 * Vérifie l'accessibilité de SYSVOL.
 *
 * - **Sur un DC** (path local présent) : le partage SYSVOL vit dans
 *   `config('sambaedu.gpo.sysvol_path')` ; on contrôle existence, lecture et
 *   présence d'au moins un sous-dossier domaine.
 * - **Sur un serveur membre** (path local absent) : SYSVOL est hébergé par le
 *   DC et accédé via SMB (`\\<DC>\sysvol`). On vérifie alors que le partage
 *   `sysvol` est exposé par le DC via `smbclient -L`. Le test fonctionnel réel
 *   d'accès (Kerberos, ACLs) est couvert par {@see SambaToolListAllCheck}.
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

        if (is_dir($path)) {
            return $this->checkLocalSysvol($path);
        }

        return $this->checkRemoteSysvol($path);
    }

    /**
     * Cas DC (ou membre avec mount CIFS local) : le path local existe.
     */
    private function checkLocalSysvol(string $path): CheckResult
    {
        if (! is_readable($path)) {
            return CheckResult::error(
                sprintf('%s non lisible par %s.', $path, get_current_user()),
                sprintf('`sudo setfacl -R -m u:%s:rX %s`', get_current_user(), $path),
            );
        }

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

    /**
     * Cas membre AD : pas de SYSVOL local, on contrôle l'exposition du
     * partage `sysvol` par le DC.
     */
    private function checkRemoteSysvol(string $localPath): CheckResult
    {
        $dcIp = (string) config('sambaedu.se4ad_ip', '');
        if ($dcIp === '') {
            return CheckResult::error(
                sprintf('SYSVOL local absent (%s) et config(\'sambaedu.se4ad_ip\') vide.', $localPath),
                'Définir SE4AD_IP=<ip-du-DC> dans .env, ou installer/configurer le DC localement.',
            );
        }

        $result = Process::timeout(5)->run(['smbclient', '-L', '//' . $dcIp, '-N', '-g']);
        if (! $result->successful()) {
            return CheckResult::error(
                sprintf('SYSVOL local absent et `smbclient -L //%s` a échoué.', $dcIp),
                'Vérifier que `smbclient` est installé, que le DC est joignable, et que les partages SMB sont exposés.',
            );
        }

        $hasSysvol = false;
        foreach (explode("\n", $result->output()) as $line) {
            if (preg_match('/^Disk\|sysvol\|/i', trim($line))) {
                $hasSysvol = true;
                break;
            }
        }

        if (! $hasSysvol) {
            return CheckResult::error(
                sprintf('SYSVOL local absent et partage `sysvol` non exposé par //%s.', $dcIp),
                'Vérifier sur le DC que Samba est démarré et que SYSVOL est répliqué.',
            );
        }

        return CheckResult::ok(sprintf(
            'Serveur membre — partage `sysvol` exposé par //%s (SYSVOL distant).',
            $dcIp,
        ));
    }
}
