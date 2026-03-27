<?php

/**
 * Bootstrap Legacy — Initialise le contexte Laravel pour les modules legacy.
 *
 * Ce fichier est chargé par le LegacyCatchallController avant l'exécution
 * d'un module legacy situé dans legacy/modules/.
 *
 * Il fournit : autoload Composer, app Laravel bootstrappée, session,
 * error handlers, config bridge et shim LDAP.
 *
 * IDEMPOTENT : un double appel ne crashe pas.
 */

// Guard : ne bootstrapper qu'une seule fois
if (defined('LEGACY_BOOTSTRAP_LOADED')) {
    return;
}
define('LEGACY_BOOTSTRAP_LOADED', true);

try {
    // 1. Autoloader Composer
    require_once __DIR__ . '/../vendor/autoload.php';

    // 2. Bootstrapper l'application Laravel (si pas déjà fait)
    if (!function_exists('app') || !app()->hasBeenBootstrapped()) {
        $app = require_once __DIR__ . '/../bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $kernel->bootstrap();
    }

    // 3. Démarrer/reprendre la session Laravel
    if (!app('session')->isStarted()) {
        app('session')->start();
    }

    // 4. Brancher les error handlers legacy (story 1bis.1)
    set_error_handler([\App\Services\LegacyErrorHandler::class, 'handleError']);
    set_exception_handler([\App\Services\LegacyErrorHandler::class, 'handleException']);

    // 5. Charger le config bridge et les shims
    require_once __DIR__ . '/config.inc.php';
    require_once __DIR__ . '/ldap.inc.php';
    require_once __DIR__ . '/wpkg_libsql.php';

    // 6. Include path legacy — les modules font des include("xxx.inc.php")
    //    qui doivent résoudre vers le dossier includes/ du legacy original.
    $legacyIncludesPath = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes';
    if (is_dir($legacyIncludesPath)) {
        set_include_path(get_include_path() . PATH_SEPARATOR . $legacyIncludesPath);
    }

    // ─── LEGACY INCLUDES ─────────────────────────────────────────────────
    // Fichiers legacy chargés directement car utilisés par les modules.
    // SUPPRIMER chaque ligne au fur et à mesure que la fonction est shimmée
    // ou que le module qui l'utilise est réécrit en Livewire.
    // ─────────────────────────────────────────────────────────────────────
    if (is_dir($legacyIncludesPath)) {
        // Fonctions utilitaires globales (utilisées partout)
        // require_once $legacyIncludesPath . '/functions.inc.php';

        // Samba / AD tools
        // require_once $legacyIncludesPath . '/samba-tool.inc.php';

        // DHCP
        // require_once $legacyIncludesPath . '/dhcpd.inc.php';

        // Gestion des parcs
        // require_once $legacyIncludesPath . '/fonc_parc.inc.php';

        // Annuaire
        // require_once $legacyIncludesPath . '/annu.inc.php';

        // Délégations
        // require_once $legacyIncludesPath . '/delegations.inc.php';

        // ENT (import GPEI, etc.)
        // require_once $legacyIncludesPath . '/ent.inc.php';
    }
    // ─── FIN LEGACY INCLUDES ─────────────────────────────────────────────

} catch (\Throwable $e) {
    // Le bootstrap ne doit jamais faire tomber le site — remonter au controller
    if (function_exists('app') && app()->bound(\App\Services\ErrorLoggerService::class)) {
        app(\App\Services\ErrorLoggerService::class)->log('legacy', 'Bootstrap failure: ' . $e->getMessage());
    }
    throw new \RuntimeException('Legacy bootstrap failure: ' . $e->getMessage(), 500, $e);
}
