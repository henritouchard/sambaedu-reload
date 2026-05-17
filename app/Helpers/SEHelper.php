<?php

use App\Services\UtilityService;
use App\Services\CacheService;

if (!function_exists('SE_utility')) {
    /**
     * Helper global pour accéder au UtilityService
     */
    function SE_utility(): UtilityService
    {
        return app(UtilityService::class);
    }
}

if (!function_exists('SE_cache')) {
    /**
     * Helper global pour accéder au CacheService
     */
    function SE_cache(): CacheService
    {
        return app(CacheService::class);
    }
}

if (!function_exists('legacy_url')) {
    /**
     * Construit une URL absolue vers une page legacy SambaEdu en utilisant le
     * port défini dans `config('sambaedu.legacy_port')` (8082 par défaut).
     *
     * Le scheme et le host sont repris du contexte de la requête courante.
     * Hors contexte HTTP (CLI, queue worker), retourne le path tel quel —
     * legacy_url() n'a de sens qu'en rendu de vue.
     *
     * Exemple :
     *   legacy_url('/gpo/gestion_gpo.php')
     *   → "http://se4fs:8082/gpo/gestion_gpo.php"
     *
     *   legacy_url('/gpo/gpo-maj.php?selectionne=foo')
     *   → "http://se4fs:8082/gpo/gpo-maj.php?selectionne=foo"
     */
    function legacy_url(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $request = request();

        if (! $request instanceof \Illuminate\Http\Request) {
            return $path;
        }

        return sprintf(
            '%s://%s:%d%s',
            $request->getScheme(),
            $request->getHost(),
            (int) config('sambaedu.legacy_port', 8082),
            $path,
        );
    }
}
