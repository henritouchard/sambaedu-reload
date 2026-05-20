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

    // 5b. Stubs pour dépendances manquantes des includes GPO (story 1bis.18a)
    //     guid(), roaming_profiles_stats(), search_parcs() — définis dans des
    //     modules non encore chargés (printers, partages, parcs).
    require_once __DIR__ . '/stubs/gpo_deps.inc.php';

    // 6. Include path legacy — les modules font des include("xxx.inc.php")
    //    qui doivent résoudre vers le dossier includes/ du legacy original.
    //    IMPORTANT : les stubs doivent être AVANT le legacy includes/ pour que
    //    require 'ldap.inc.php' / 'config.inc.php' / 'admin_ui.inc.php'
    //    résolvent vers nos stubs (qui bridgent vers les shims) et non vers
    //    les originaux legacy (qui redéclareraient des fonctions = fatal error).
    $stubsPath = base_path('legacy/stubs');
    $legacyIncludesPath = config('sambaedu.legacy_path', '/var/www/sambaedu') . '/includes';
    $newIncludePath = get_include_path();
    if (is_dir($stubsPath)) {
        $newIncludePath = $stubsPath . PATH_SEPARATOR . $newIncludePath;
    }
    if (is_dir($legacyIncludesPath)) {
        $newIncludePath .= PATH_SEPARATOR . $legacyIncludesPath;
    }
    set_include_path($newIncludePath);

    // ─── LEGACY INCLUDES ─────────────────────────────────────────────────
    // Fichiers legacy chargés directement car utilisés par les modules.
    // SUPPRIMER chaque ligne au fur et à mesure que la fonction est shimmée
    // ou que le module qui l'utilise est réécrit en Livewire.
    // En tests, la constante LEGACY_SKIP_LEGACY_INCLUDES (définie par
    // tests/bootstrap.php) saute ce bloc : les shims if-guardés ci-dessous
    // prennent le relais et évitent les exec(samba-tool) qui timeout sur
    // Kerberos dans l'environnement de test.
    // ─────────────────────────────────────────────────────────────────────
    if (is_dir($legacyIncludesPath) && ! defined('LEGACY_SKIP_LEGACY_INCLUDES')) {
        // Fonctions utilitaires globales (utilisées partout)
        require_once $legacyIncludesPath . '/functions.inc.php';

        // ─── GPO core includes (story 1bis.18a) ─────────────────────────
        // Ordre critique — respecter les dépendances croisées :
        // samba-tool (pas de dep sur gpo/delegations)
        // → gpo (dep sur samba-tool: gpocreate, gpodel, etc.)
        // → delegations (dep sur samba-tool + gpo)
        // → gpo_ui (dep sur partages: roaming_profiles_stats — stubbée)
        require_once $legacyIncludesPath . '/samba-tool.inc.php';
        require_once $legacyIncludesPath . '/gpo.inc.php';
        require_once $legacyIncludesPath . '/delegations.inc.php';
        require_once $legacyIncludesPath . '/gpo_ui.inc.php';

        // DHCP
        // require_once $legacyIncludesPath . '/dhcpd.inc.php';

        // Gestion des parcs
        // require_once $legacyIncludesPath . '/fonc_parc.inc.php';

        // Annuaire
        // require_once $legacyIncludesPath . '/annu.inc.php';

        // Délégations — chargé ci-dessus avec les GPO includes
        // require_once $legacyIncludesPath . '/delegations.inc.php';

        // ENT (import GPEI, etc.)
        // require_once $legacyIncludesPath . '/ent.inc.php';
    }
    // ─── FIN LEGACY INCLUDES ─────────────────────────────────────────────

    // ─── GPO shim (story 1bis.18g) — RETIRÉ par Story 16.13bis ─────────────
    // Le `gpo_shim.inc.php` a été archivé dans
    // `legacy/archived/gpo-shim-2026-05-20/` car le modèle de migration
    // SE4 → SE5 bascule en fragment+reboot stateless via
    // `App\Auth\V1\Migration` : les fallbacks SYSVOL + Kerberos bridge ne
    // sont plus utilisés. Les helpers `_shim_gpo_*` définis dans
    // `legacy/ldap.inc.php` restent en place (guardés par function_exists,
    // pas d'impact à l'init).
    //
    // ─── DHCP shim (story 1bis-16) ──────────────────────────────────────────
    // Fournit les fonctions DHCP utilisées par legacy/modules/dhcp/ (export/import
    // reservations, leases, MAC helpers). Sans lui, make_reservations.php produit
    // un Fatal error Call to undefined function export_dhcp_reservations() en prod.
    require_once __DIR__ . '/dhcp_shim.inc.php';

} catch (\Throwable $e) {
    // Le bootstrap ne doit jamais faire tomber le site — remonter au controller
    if (function_exists('app') && app()->bound(\App\Services\ErrorLoggerService::class)) {
        app(\App\Services\ErrorLoggerService::class)->log('legacy', 'Bootstrap failure: ' . $e->getMessage());
    }
    throw new \RuntimeException('Legacy bootstrap failure: ' . $e->getMessage(), 500, $e);
}
