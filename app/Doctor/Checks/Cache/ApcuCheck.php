<?php

declare(strict_types=1);

namespace App\Doctor\Checks\Cache;

use App\Doctor\CheckResult;
use App\Doctor\EnvironmentCheck;

/**
 * Vérifie qu'APCu est installé ET activé pour l'environnement courant
 * (CLI ou web). Piège récurrent (cf. Story 16.8) : APCu installé pour
 * PHP-FPM mais désactivé en CLI → `php artisan` fail silencieusement
 * sur les opérations qui dépendent du cache APCu.
 */
final class ApcuCheck implements EnvironmentCheck
{
    public function tag(): string
    {
        return 'cache';
    }

    public function name(): string
    {
        return 'APCu extension';
    }

    public function run(): CheckResult
    {
        if (! extension_loaded('apcu')) {
            return CheckResult::error(
                'Extension APCu non chargée.',
                'Installer `php-apcu` (`apt install php<version>-apcu`) puis redémarrer PHP-FPM.',
            );
        }

        if (! function_exists('apcu_enabled') || ! apcu_enabled()) {
            $sapi = PHP_SAPI;
            $fix = $sapi === 'cli'
                ? 'APCu désactivé en CLI : ajouter `apc.enable_cli=1` dans `/etc/php/<version>/cli/conf.d/20-apcu.ini` (ou équivalent).'
                : 'APCu désactivé : vérifier `/etc/php/<version>/fpm/conf.d/20-apcu.ini` (`apc.enabled=1`) puis redémarrer PHP-FPM.';

            return CheckResult::error(
                sprintf('APCu non actif sous SAPI %s.', $sapi),
                $fix,
            );
        }

        // Test fonctionnel : write+read+delete sur une clé éphémère.
        $key = '__doctor_apcu_probe_' . bin2hex(random_bytes(4));
        $expected = (string) microtime(true);

        if (! @apcu_store($key, $expected, 5)) {
            return CheckResult::warn(
                'APCu chargé mais `apcu_store()` retourne false.',
                'Vérifier `apc.shm_size` (peut être saturé) ou les permissions du shared memory segment.',
            );
        }

        $read = apcu_fetch($key);
        apcu_delete($key);

        if ($read !== $expected) {
            return CheckResult::warn(
                sprintf('APCu round-trip KO (write=%s, read=%s).', $expected, var_export($read, true)),
                'Investiguer la config APCu (cache invalidation prématurée ?).',
            );
        }

        return CheckResult::ok(sprintf('APCu actif (SAPI %s, round-trip OK).', PHP_SAPI));
    }
}
