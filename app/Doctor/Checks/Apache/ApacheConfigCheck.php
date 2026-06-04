<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Apache;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;
use Illuminate\Support\Facades\Process;

/**
 * Vérifie la configuration Apache du serveur SE5 :
 *  1. le vhost SER (`sambaedu.conf` pointant sur sambaedu-reload/public)
 *     est en place — même critère que `scripts/update.sh::update_apache` ;
 *  2. `apache2ctl configtest` retourne « Syntax OK ».
 */
final class ApacheConfigCheck implements EnvironmentCheck
{
    /**
     * Répertoire des vhosts ACTIFS. On scanne sites-enabled (pas un fichier
     * de sites-available hardcodé) : le nom du vhost SER varie selon les
     * installs (sambaedu.conf, sambaedu-reload.conf…) et sites-available
     * peut contenir des versions obsolètes non activées.
     */
    private const SITES_ENABLED_DIR = '/etc/apache2/sites-enabled';

    public function tag(): string
    {
        return 'apache';
    }

    public function name(): string
    {
        return 'Config Apache';
    }

    public function run(): CheckResult
    {
        $vhost = $this->findSerVhost();
        if ($vhost === null) {
            return CheckResult::error(
                sprintf('aucun vhost actif ne pointe sur sambaedu-reload/public (%s/*.conf).', self::SITES_ENABLED_DIR),
                'Exécuter scripts/setupApache.sh (ou scripts/update.sh) en root.',
            );
        }

        $result = Process::timeout(5)->run(['apache2ctl', 'configtest']);

        // apache2ctl écrit « Syntax OK » sur stderr.
        $output = $result->output() . $result->errorOutput();
        if (! str_contains($output, 'Syntax OK')) {
            // Fix review F11 : binaire absent = exit 127 (shell) ou process
            // jamais démarré (null) — pas d'heuristique substring « not
            // found » qui matcherait un configtest légitime en erreur.
            if ($result->exitCode() === null || $result->exitCode() === 127) {
                return CheckResult::warn(
                    'apache2ctl indisponible — configtest impossible.',
                    'Vérifier qu\'Apache est installé (apt install apache2).',
                );
            }

            return CheckResult::error(
                sprintf('configtest KO : %s', substr(trim($output), 0, 160)),
                'Corriger la configuration Apache (apache2ctl configtest pour le détail) puis reload.',
            );
        }

        return CheckResult::ok(sprintf('vhost SER actif (%s), configtest « Syntax OK »', basename($vhost)));
    }

    /**
     * Premier vhost actif dont le contenu référence `sambaedu-reload/public`.
     */
    private function findSerVhost(): ?string
    {
        foreach (glob(self::SITES_ENABLED_DIR . '/*.conf') ?: [] as $conf) {
            if (str_contains((string) @file_get_contents($conf), 'sambaedu-reload/public')) {
                return $conf;
            }
        }

        return null;
    }
}
