<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

/**
 * Vérifie l'accessibilité des fichiers Samba privés (`passdb.tdb`,
 * `netlogon_creds_cli.tdb`) par l'user PHP-FPM courant.
 *
 * IMPORTANT — sémantique conditionnelle :
 *
 * - Si `sambaedu.gpo.kerb_option = --use-kerberos=required` (parité legacy),
 *   samba-tool n'ouvre JAMAIS ces fichiers. Les permissions par défaut Samba
 *   (`0600 root:root`) sont alors la **configuration correcte et recommandée**.
 *   Le check passe ✓ même si www-admin ne peut pas les lire.
 *
 * - Si `kerb_option` vaut `desired` ou `off`, samba-tool tente d'ouvrir
 *   `secrets.tdb` pour préparer un fallback NTLM via compte machine. Dans ce
 *   mode, les fichiers privés DOIVENT être lisibles par le user PHP-FPM, sous
 *   peine de `NT_STATUS_CANT_ACCESS_DOMAIN_INFO`.
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
        $kerb = (string) config('sambaedu.gpo.kerb_option', '');
        $requiresLocalSecrets = $kerb !== '--use-kerberos=required';

        $details = [];
        $worstLevel = 'ok';
        $fixes = [];

        foreach ([self::PASSDB_TDB, self::NETLOGON_TDB] as $file) {
            if (! file_exists($file)) {
                $details[] = sprintf('%s absent', basename($file));
                if ($requiresLocalSecrets) {
                    $worstLevel = $this->bump($worstLevel, 'warn');
                    $fixes[] = 'Normal sur un DC (secrets.ldb à la place). Sur un serveur membre : vérifier que `samba-common` est installé et que le serveur est joint au domaine.';
                }

                continue;
            }
            if (! is_readable($file)) {
                if ($requiresLocalSecrets) {
                    $details[] = sprintf('%s non lisible', basename($file));
                    $worstLevel = $this->bump($worstLevel, 'error');
                    $fixes[] = sprintf(
                        '`sudo setfacl -m u:%s:r %s` — OU (recommandé) basculer `GPO_KERB_OPTION=--use-kerberos=required` pour éviter d\'accéder à ce fichier.',
                        get_current_user(),
                        $file,
                    );
                } else {
                    $details[] = sprintf('%s non lisible (OK — mode required)', basename($file));
                }

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
