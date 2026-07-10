<?php

declare(strict_types=1);

namespace App\Services\Gpo;

use App\Gpo\Dto\GpoSummary;
use App\Gpo\Support\GpoLogger;
use App\Gpo\Support\PregCodec;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Lecture / écriture des `Registry.pol` d'une GPO dans SYSVOL, en natif.
 *
 * Story 38.4 (AC2) — port des fonctions legacy `read_gpo_sysvol` /
 * `update_gpo_sysvol` / `increment_gpo_sysvol` (`gpo.inc.php`) consommées par
 * {@see \App\Services\RoamingProfileService}. Remplace la chaîne
 * `smbclient get/put` + `read_pol/write_pol` legacy par
 * {@see PregCodec} (codec pur) et {@see AdministratorKerberosContext}
 * (ticket Kerberos www-admin/Administrator — anti faux-succès SYSVOL).
 *
 * Descripteur "User side" natif (parité constante legacy `USER_GPO`) :
 * chemin `/User/`, fichier `Registry.pol`.
 *
 * Garde-fou archi : vit sous `App\Services\Gpo` (invoque `Process`/smbclient) ;
 * `GpoNamespaceTest` ne scanne que `app/Gpo/`.
 */
class SysvolPolicyService
{
    /** Sous-chemin + fichier de la politique User (parité `USER_GPO`). */
    private const USER_POLICY_SUBPATH = 'User';
    private const POLICY_FILE = 'Registry.pol';

    public function __construct(
        private readonly PregCodec $codec,
        private readonly AdministratorKerberosContext $kerberos,
        private readonly GpcDirectory $directory,
    ) {}

    /**
     * Lit et décode le `Registry.pol` User d'une GPO depuis SYSVOL.
     *
     * @return list<array<string,mixed>>  Entrées décodées (voir {@see PregCodec::decode}).
     * @throws RuntimeException Si la lecture SYSVOL échoue.
     */
    public function readUserPolicy(GpoSummary $gpo): array
    {
        $log = GpoLogger::action('gpo.sysvol.read', context: ['gpo_name' => $gpo->name, 'phase' => 'user']);

        return $this->kerberos->withTicket($log, function (string $ccache) use ($gpo, $log): array {
            $host = $this->kerberos->sysvolHost();
            $domain = (string) config('sambaedu.domain', '');
            if ($host === '' || $domain === '') {
                throw new RuntimeException('domain/host SYSVOL indéterminés — lecture de politique impossible.');
            }

            $tmpDir = sys_get_temp_dir() . '/se_sysvol_' . bin2hex(random_bytes(6));
            @mkdir($tmpDir, 0700, true);
            $localFile = $tmpDir . '/' . self::POLICY_FILE;

            try {
                $remoteDir = sprintf('%s/Policies/%s/%s', $domain, $gpo->name, self::USER_POLICY_SUBPATH);
                $command = sprintf(
                    'cd "%s";lcd "%s";get %s',
                    $remoteDir,
                    $tmpDir,
                    self::POLICY_FILE,
                );

                $result = Process::env(['KRB5CCNAME' => $ccache])->run([
                    'smbclient', '//' . $host . '/sysvol',
                    '--use-kerberos=required',
                    '-c', $command,
                ]);

                if (! is_file($localFile)) {
                    throw new RuntimeException(sprintf(
                        'Lecture SYSVOL du Registry.pol User échouée pour %s (exit=%d): %s',
                        $gpo->displayName,
                        $result->exitCode() ?? -1,
                        $this->kerberos->scrub(substr($result->output() . $result->errorOutput(), 0, 400)),
                    ));
                }

                $raw = (string) file_get_contents($localFile);
                $entries = $this->codec->decode($raw);
                $log->success(['entries' => count($entries)]);

                return $entries;
            } finally {
                if (is_file($localFile)) {
                    @unlink($localFile);
                }
                @rmdir($tmpDir);
            }
        });
    }

    /**
     * Encode et écrit le `Registry.pol` User d'une GPO dans SYSVOL, avec
     * vérification d'écriture réelle (relecture, anti faux-succès).
     *
     * @param  list<array<string,mixed>>  $entries
     * @throws RuntimeException Si l'écriture ou sa vérification échoue.
     */
    public function writeUserPolicy(GpoSummary $gpo, array $entries): void
    {
        $log = GpoLogger::action('gpo.sysvol.write', context: ['gpo_name' => $gpo->name, 'phase' => 'user']);

        $this->kerberos->withTicket($log, function (string $ccache) use ($gpo, $entries, $log): void {
            $host = $this->kerberos->sysvolHost();
            $domain = (string) config('sambaedu.domain', '');
            if ($host === '' || $domain === '') {
                throw new RuntimeException('domain/host SYSVOL indéterminés — écriture de politique impossible.');
            }

            $binary = $this->codec->encode($entries);
            $expectedSize = strlen($binary);

            $tmpDir = sys_get_temp_dir() . '/se_sysvol_' . bin2hex(random_bytes(6));
            @mkdir($tmpDir, 0700, true);
            $localFile = $tmpDir . '/' . self::POLICY_FILE;

            try {
                if (file_put_contents($localFile, $binary) === false) {
                    throw new RuntimeException('Écriture du Registry.pol temporaire impossible.');
                }

                $remoteDir = sprintf('%s/Policies/%s/%s', $domain, $gpo->name, self::USER_POLICY_SUBPATH);
                $command = sprintf(
                    'cd "%s";lcd "%s";prompt OFF;put %s',
                    $remoteDir,
                    $tmpDir,
                    self::POLICY_FILE,
                );

                $result = Process::env(['KRB5CCNAME' => $ccache])->run([
                    'smbclient', '//' . $host . '/sysvol',
                    '--use-kerberos=required',
                    '-c', $command,
                ]);

                if (! $result->successful()) {
                    throw new RuntimeException(sprintf(
                        'Écriture SYSVOL du Registry.pol User échouée pour %s (exit=%d): %s',
                        $gpo->displayName,
                        $result->exitCode() ?? -1,
                        $this->kerberos->scrub(substr($result->output() . $result->errorOutput(), 0, 400)),
                    ));
                }

                // Anti faux-succès : relecture de la taille distante (égalité stricte).
                $this->assertRemoteSize($ccache, $host, $remoteDir, self::POLICY_FILE, $expectedSize, $gpo);

                $log->success(['written' => true, 'size' => $expectedSize]);
            } finally {
                if (is_file($localFile)) {
                    @unlink($localFile);
                }
                @rmdir($tmpDir);
            }
        });
    }

    /**
     * Incrémente la version d'une GPO côté **User** (parité `increment_gpo_sysvol`
     * pour `USER_GPO`) : `versionNumber += 0x10000`, réécriture du `GPT.INI`
     * SYSVOL (CRLF) + `versionNumber` AD. Sans quoi les clients ne réappliquent
     * jamais la politique (`project_gpo_template_edit_needs_version_bump`).
     *
     * @throws RuntimeException Si le bump échoue.
     */
    public function bumpUserVersion(GpoSummary $gpo): void
    {
        $log = GpoLogger::action('gpo.sysvol.bump', context: ['gpo_name' => $gpo->name, 'phase' => 'user']);

        $currentVersion = $gpo->versionNumber ?? 0;
        $newVersion = $currentVersion + 0x10000; // incrément côté user (parité legacy)

        $this->kerberos->withTicket($log, function (string $ccache) use ($gpo, $newVersion, $log): void {
            $host = $this->kerberos->sysvolHost();
            $domain = (string) config('sambaedu.domain', '');
            if ($host === '' || $domain === '') {
                throw new RuntimeException('domain/host SYSVOL indéterminés — bump de version impossible.');
            }

            // GPT.INI CRLF (parité byte du legacy).
            $content = "[General]\r\nVersion=" . $newVersion . "\r\ndisplayName=" . $gpo->displayName . "\r\n";
            $expectedSize = strlen($content);

            $tmpDir = sys_get_temp_dir() . '/se_sysvol_' . bin2hex(random_bytes(6));
            @mkdir($tmpDir, 0700, true);
            $localFile = $tmpDir . '/GPT.INI';

            try {
                file_put_contents($localFile, $content);

                $remoteDir = sprintf('%s/Policies/%s', $domain, $gpo->name);
                $command = sprintf('cd "%s";lcd "%s";prompt OFF;put GPT.INI', $remoteDir, $tmpDir);

                $result = Process::env(['KRB5CCNAME' => $ccache])->run([
                    'smbclient', '//' . $host . '/sysvol',
                    '--use-kerberos=required',
                    '-c', $command,
                ]);

                if (! $result->successful()) {
                    throw new RuntimeException(sprintf(
                        'Écriture SYSVOL du GPT.INI échouée pour %s (exit=%d): %s',
                        $gpo->displayName,
                        $result->exitCode() ?? -1,
                        $this->kerberos->scrub(substr($result->output() . $result->errorOutput(), 0, 400)),
                    ));
                }

                $this->assertRemoteSize($ccache, $host, $remoteDir, 'GPT.INI', $expectedSize, $gpo);

                // versionNumber AD (parité modify_ad).
                $this->directory->setAttributes($gpo->name, ['versionnumber' => $newVersion]);

                $log->success(['bumped_to' => $newVersion]);
            } finally {
                if (is_file($localFile)) {
                    @unlink($localFile);
                }
                @rmdir($tmpDir);
            }
        });
    }

    /**
     * Relit la taille d'un fichier SYSVOL via `smbclient ls` et exige l'égalité
     * stricte avec la taille attendue (anti faux-succès `ACCESS_DENIED` masqué
     * en exit 0).
     */
    private function assertRemoteSize(string $ccache, string $host, string $remoteDir, string $file, int $expectedSize, GpoSummary $gpo): void
    {
        $result = Process::env(['KRB5CCNAME' => $ccache])->run([
            'smbclient', '//' . $host . '/sysvol',
            '--use-kerberos=required',
            '-c', sprintf('cd "%s";ls %s', $remoteDir, $file),
        ]);

        $out = $result->output();
        if (! $result->successful() || preg_match('/' . preg_quote($file, '/') . '\s+\S+\s+(\d+)/i', $out, $m) !== 1) {
            throw new RuntimeException(sprintf(
                'Vérification d\'écriture SYSVOL ÉCHOUÉE pour %s (%s absent). Probable ACCESS_DENIED masqué.',
                $gpo->displayName,
                $file,
            ));
        }

        $remoteSize = (int) $m[1];
        if ($remoteSize !== $expectedSize) {
            throw new RuntimeException(sprintf(
                'Vérification d\'écriture SYSVOL ÉCHOUÉE pour %s : %s fait %d octets, %d attendus.',
                $gpo->displayName,
                $file,
                $remoteSize,
                $expectedSize,
            ));
        }
    }
}
