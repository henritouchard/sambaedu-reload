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
        if (! is_executable('/usr/bin/klist')) {
            return CheckResult::warn(
                'Binaire /usr/bin/klist absent — impossible de vérifier le ccache.',
                'Installer le paquet `krb5-user`.',
            );
        }

        $silent = Process::timeout(5)->run(['klist', '-s']);
        if (! $silent->successful()) {
            return CheckResult::error(
                sprintf(
                    'Aucun ticket Kerberos pour %s (klist -s exit ≠ 0).',
                    get_current_user(),
                ),
                sprintf(
                    'Initialiser un ticket : `sudo -u %s kinit Administrator` (ou un autre principal admin AD).',
                    get_current_user(),
                ),
            );
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
            return CheckResult::error(
                sprintf('Ticket EXPIRÉ depuis %s.', date('Y-m-d H:i:s', $expires)),
                sprintf('Renouveler : `sudo -u %s kinit -R` ou `kinit Administrator`.', get_current_user()),
            );
        }

        $hours = (int) floor(($expires - $now) / 3600);

        return CheckResult::ok(sprintf('Ticket valide encore %dh (expire %s).', $hours, date('Y-m-d H:i', $expires)));
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
