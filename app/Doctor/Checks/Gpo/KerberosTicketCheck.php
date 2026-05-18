<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Gpo;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use DateTime;
use Illuminate\Support\Facades\Process;

final class KerberosTicketCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'gpo';
    }

    public function name(): string
    {
        return 'Kerberos ccache';
    }

    public function run(): CheckResult
    {
        $kerbMode = $this->detectKerberosMode();

        if ($kerbMode === 'off') {
            return CheckResult::ok('Kerberos désactivé (kerb_option=off) — check non applicable.');
        }

        if (! is_executable('/usr/bin/klist')) {
            return CheckResult::warn(
                'Binaire /usr/bin/klist absent — impossible de vérifier le ccache.',
                'Installer le paquet `krb5-user`.',
            );
        }

        $silent = Process::timeout(5)->run(['klist', '-s']);
        if (! $silent->successful()) {
            $detail = sprintf(
                'Aucun ticket Kerberos pour %s (klist -s exit ≠ 0).',
                get_current_user(),
            );

            // Avec `desired`, samba-tool fallback sur NTLM via passdb.tdb —
            // l'absence de ticket n'empêche pas le fonctionnement.
            if ($kerbMode === 'desired') {
                return CheckResult::warn(
                    $detail . ' Fallback NTLM actif (kerb_option=desired).',
                    $this->buildKinitHint(),
                );
            }

            return CheckResult::error($detail, $this->buildKinitHint());
        }

        $output = Process::timeout(5)->run(['klist'])->output();
        $expires = $this->parseKlistExpiration($output);

        if ($expires === null) {
            return CheckResult::warn(
                'Ticket présent mais date d\'expiration non parsable.',
                'Vérifier manuellement avec `klist`.',
            );
        }

        $now = time();
        if ($expires <= $now) {
            $detail = sprintf('Ticket EXPIRÉ depuis %s.', date('Y-m-d H:i:s', $expires));
            $fix = sprintf('Renouveler : `sudo -u %s kinit -R` ou `kinit Administrator`.', get_current_user());

            if ($kerbMode === 'desired') {
                return CheckResult::warn($detail . ' Fallback NTLM actif (kerb_option=desired).', $fix);
            }

            return CheckResult::error($detail, $fix);
        }

        $hours = (int) floor(($expires - $now) / 3600);

        return CheckResult::ok(sprintf('Ticket valide encore %dh (expire %s).', $hours, date('Y-m-d H:i', $expires)));
    }

    /**
     * Extrait le mode Kerberos de `config('sambaedu.gpo.kerb_option')`.
     * Retourne `required` (défaut conservateur), `desired` ou `off`.
     */
    private function detectKerberosMode(): string
    {
        $raw = (string) config('sambaedu.gpo.kerb_option', '');
        if (preg_match('/--use-kerberos=(required|desired|off)/i', $raw, $m)) {
            return strtolower($m[1]);
        }

        return 'required';
    }

    /**
     * Suggère la bonne commande `kinit` selon que la machine dispose d'un
     * keytab (cas standard sur un membre AD joint) ou non.
     */
    private function buildKinitHint(): string
    {
        $user = get_current_user();
        foreach (['/etc/krb5.keytab', '/var/lib/samba/private/secrets.keytab'] as $keytab) {
            if (is_readable($keytab)) {
                return sprintf(
                    'Keytab détecté (%s) — initialiser un ticket compte machine : '
                    . '`sudo -u %s kinit -k -t %s` (ou principal admin AD via `kinit Administrator`).',
                    $keytab,
                    $user,
                    $keytab,
                );
            }
        }

        return sprintf(
            'Initialiser un ticket : `sudo -u %s kinit Administrator` (ou un autre principal admin AD). '
            . 'Sur un membre AD, déployer un keytab et utiliser `kinit -k`.',
            $user,
        );
    }

    /**
     * Parse `klist` output pour extraire l'expiration du ticket krbtgt.
     * Tolère les locales `en` (m/d/Y) et `fr` (d/m/Y).
     */
    private function parseKlistExpiration(string $output): ?int
    {
        foreach (explode("\n", $output) as $line) {
            if (! str_contains($line, 'krbtgt/')) {
                continue;
            }
            if (preg_match('#(\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2})\s+(\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2})#', $line, $m)) {
                $expires = DateTime::createFromFormat('d/m/Y H:i:s', $m[2])
                    ?: DateTime::createFromFormat('m/d/Y H:i:s', $m[2]);

                return $expires ? $expires->getTimestamp() : null;
            }
        }

        return null;
    }
}
