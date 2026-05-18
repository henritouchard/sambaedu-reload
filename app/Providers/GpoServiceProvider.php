<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider du module GPO natif (Story 16.1, Epic 16).
 *
 * Au boot :
 *
 * 1. **Crée le dossier `storage/logs/gpo/`** si absent. Sans ça, Monolog
 *    plante au premier write sur le channel `gpo` (parité commit `42cebba`
 *    pour `wpkg-deploy`). Effectué AVANT tout autre log sur le channel —
 *    ordre critique (chicken-and-egg).
 * 2. **Vérifie l'accessibilité** du binaire `samba-tool` et du chemin SYSVOL
 *    (cf. `config/sambaedu.php` § gpo.bin_path et gpo.sysvol_path).
 *    Toute violation logue un warning explicite sur le channel `gpo` — le
 *    boot reste non-bloquant (le code applicatif doit pouvoir démarrer même
 *    sans Samba AD opérationnel, ex. en environnement dev/CI).
 *
 * Skip complet en environnement `testing` (cf. WpkgDeploymentServiceProvider
 * — pattern identique : pas de warning bruyant sur CI/runners).
 */
class GpoServiceProvider extends ServiceProvider
{
    /** Mode des dossiers créés (rwxr-xr-x). */
    private const DIR_MODE = 0755;

    public function boot(): void
    {
        if ($this->app->environment('testing')) {
            return;
        }

        // Ordre critique : préparer le dossier de logs avant d'écrire dans
        // le channel `gpo` — sinon RotatingFileHandler plante.
        $this->ensureLogChannelDirectory();

        $this->checkSambaToolBinary();
        $this->checkSysvolPath();
    }

    /**
     * Crée `storage/logs/gpo/` si absent. `storage/logs/` est git-ignored
     * donc le dossier n'est pas propagé par un déploiement neuf.
     *
     * Failover : si la création échoue, on logue sur le channel par défaut
     * (pas sur `gpo`, sinon chicken-and-egg).
     */
    private function ensureLogChannelDirectory(): void
    {
        $logDir = storage_path('logs/gpo');
        if (is_dir($logDir)) {
            return;
        }
        if (! @mkdir($logDir, self::DIR_MODE, true) && ! is_dir($logDir)) {
            Log::warning('[gpo] impossible de créer le dossier de logs', [
                'path' => $logDir,
                'php_user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
                    : null,
            ]);
        }
    }

    /**
     * Vérifie que le binaire `samba-tool` est présent et exécutable.
     */
    private function checkSambaToolBinary(): void
    {
        $bin = (string) config('sambaedu.gpo.bin_path', '/usr/bin/samba-tool');
        if ($bin === '') {
            return;
        }

        if (! is_file($bin)) {
            Log::channel('gpo')->warning('[gpo] binaire samba-tool introuvable', [
                'config_key' => 'sambaedu.gpo.bin_path',
                'path' => $bin,
            ]);

            return;
        }

        if (! is_executable($bin)) {
            Log::channel('gpo')->warning('[gpo] binaire samba-tool non exécutable', [
                'config_key' => 'sambaedu.gpo.bin_path',
                'path' => $bin,
            ]);
        }
    }

    /**
     * Vérifie que le chemin SYSVOL est accessible en lecture.
     *
     * Sur un DC (path local présent), on warn si non lisible. Sur un serveur
     * membre AD (path absent), SYSVOL est hébergé par le DC et accédé via SMB
     * — pas de warning ici, le diagnostic complet est délégué au Doctor
     * (cf. {@see \App\Doctor\Checks\Gpo\SysvolPathCheck}).
     */
    private function checkSysvolPath(): void
    {
        $sysvol = (string) config('sambaedu.gpo.sysvol_path', '/var/lib/samba/sysvol');
        if ($sysvol === '') {
            return;
        }

        if (! is_dir($sysvol)) {
            return;
        }

        if (! is_readable($sysvol)) {
            Log::channel('gpo')->warning('[gpo] chemin SYSVOL non lisible', [
                'config_key' => 'sambaedu.gpo.sysvol_path',
                'path' => $sysvol,
                'php_user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
                    : null,
            ]);
        }
    }
}
