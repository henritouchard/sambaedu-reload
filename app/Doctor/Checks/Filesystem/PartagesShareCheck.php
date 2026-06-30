<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Filesystem;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Support\Facades\Process;

/**
 * Vérifie que l'export SMB `[partages]` existe et pointe sur la racine des
 * lecteurs réseau gérés (Epic 34).
 *
 * Sans ce partage, NetworkShareService crée bien les répertoires + ACL et
 * DrivesStateProvider projette la lettre, mais l'agent échoue au montage
 * (WNetAddConnection2 code=67 « Nom de réseau introuvable »). Le partage
 * n'est livré ni par le code, ni par le paquet Debian `sambaedu` : il est
 * provisionné par `scripts/update.sh:ensure_samba_partages_share()`.
 *
 * Read-only : interroge `testparm` (aucun side effect).
 */
final class PartagesShareCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'filesystem';
    }

    public function name(): string
    {
        return 'Export SMB [partages]';
    }

    public function run(): CheckResult
    {
        $expected = rtrim((string) config('filesystem.shares_root', '/var/sambaedu/Partages'), '/');

        // testparm absent → Samba non installé : non applicable sur cet hôte.
        if (! Process::run(['sh', '-c', 'command -v testparm'])->successful()) {
            return CheckResult::warn(
                'testparm introuvable (Samba non installé sur cet hôte ?).',
                'Si ce serveur héberge les partages SMB, installer Samba puis relancer.',
            );
        }

        // testparm renvoie rc=1 si la section [partages] est absente.
        $result = Process::timeout(10)->run(
            ['testparm', '-s', '--section-name', 'partages', '--parameter-name', 'path'],
        );

        if (! $result->successful()) {
            return CheckResult::error(
                'Partage SMB [partages] absent de la configuration Samba.',
                'Lancer `scripts/update.sh` (étape ensure_samba_partages_share) pour déployer '
                . '/etc/samba/smb.conf.d/partages.conf et l\'include dans smb.conf. Sans ce partage, '
                . 'les lecteurs réseau gérés (Epic 34) ne se montent pas (WNetAddConnection2 code=67).',
            );
        }

        $actual = rtrim(trim($result->output()), '/');
        if ($actual !== $expected) {
            return CheckResult::error(
                sprintf('[partages] pointe sur "%s" au lieu de "%s".', $actual, $expected),
                'Aligner le `path` du partage Samba [partages] (scripts/config/smb-partages.conf) '
                . 'sur la config `filesystem.shares_root`.',
            );
        }

        return CheckResult::ok(sprintf('Partage SMB [partages] → %s', $actual));
    }
}
