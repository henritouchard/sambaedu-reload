<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Service Provider du pipeline déploiement WPKG (Story 15.1).
 *
 * Au boot : vérifie que les 4 chemins partage WPKG (deploy / ini / inbox /
 * archive) sont accessibles en lecture/écriture par le user PHP-FPM, sinon
 * émet un warning explicite sur le channel `wpkg-deploy`.
 *
 * Le check est non-bloquant : le boot Laravel doit toujours réussir, même
 * sans les partages Samba. Le but est de fournir un signal clair en logs.
 */
class WpkgDeploymentServiceProvider extends ServiceProvider
{
    /** @var list<string> Clés config dont on vérifie l'accessibilité au boot. */
    private const PATH_KEYS = [
        'sambaedu.wpkg.deploy_path',
        'sambaedu.wpkg.ini_path',
        'sambaedu.wpkg.reports_inbox',
        'sambaedu.wpkg.reports_archive',
    ];

    public function boot(): void
    {
        // Skip en environnement de test : les chemins legacy n'existent pas
        // sur les CI / runners locaux et le warning est bruyant sans valeur.
        if ($this->app->environment('testing')) {
            return;
        }

        foreach ($this->checkPaths(self::PATH_KEYS) as $violation) {
            Log::channel('wpkg-deploy')->warning(
                '[wpkg-deploy] chemin partage inaccessible',
                $violation,
            );
        }
    }

    /**
     * Inspecte la liste des clés config et retourne un tableau de violations
     * (chemins non R/W). Méthode pure — pas d'effet de bord, pas d'I/O log :
     * permet le test unitaire de la logique de détection (cf. AC4.1).
     *
     * @param  list<string>  $keys
     * @return list<array<string,mixed>>
     */
    public function checkPaths(array $keys): array
    {
        $violations = [];

        foreach ($keys as $key) {
            $path = (string) config($key, '');
            if ($path === '') {
                continue;
            }

            $exists = is_dir($path);
            $isReadable = $exists && is_readable($path);
            $isWritable = $exists && is_writable($path);

            if ($isReadable && $isWritable) {
                continue;
            }

            $violations[] = [
                'config_key' => $key,
                'path' => $path,
                'exists' => $exists,
                'readable' => $isReadable,
                'writable' => $isWritable,
                'php_user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                    ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
                    : null,
            ];
        }

        return $violations;
    }
}
