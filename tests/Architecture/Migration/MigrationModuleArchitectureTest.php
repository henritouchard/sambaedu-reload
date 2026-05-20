<?php

declare(strict_types=1);

namespace Tests\Architecture\Migration;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 16.13bis — AC8.
 *
 * Tests architecture invariance pour la transformation des 8 routes
 * legacy en `MigrationController::serveFragment` + suppression des
 * artefacts 16.11 (middleware `InjectBootstrapFragment`, controller
 * `BootstrapScriptController`) + non-régression `/api/v1/workstation-config/*`
 * 16.13 + ControlHub.
 *
 * Décisions D9.
 */
final class MigrationModuleArchitectureTest extends TestCase
{
    /** Noms de routes attendus après transformation (cf. D2). */
    private const EXPECTED_MIGRATION_ROUTES = [
        'migration.legacy.shortcuts',
        'migration.legacy.wallpaper',
        'migration.legacy.firefox',
        'migration.legacy.thunderbird',
        'migration.legacy.network',
        'migration.legacy.veyon',
        'migration.legacy.associations',
        'migration.legacy.applications',
    ];

    /** Anciens noms supprimés (Story 16.13bis D2). */
    private const REMOVED_LEGACY_ROUTE_NAMES = [
        'shortcuts.legacy',
        'wallpaper.legacy',
        'app-policy.firefox.legacy',
        'app-policy.thunderbird.legacy',
        'gpo.network-out.legacy',
        'gpo.veyon-out.legacy',
        'gpo.associations-out.legacy',
        'gpo.applications.legacy',
    ];

    /** Noms de routes 16.13 qui doivent rester intacts (Q4 16.13). */
    private const API_V1_WORKSTATION_CONFIG_ROUTES = [
        'agent.v1.config.wallpaper',
        'agent.v1.config.firefox',
        'agent.v1.config.thunderbird',
        'agent.v1.config.shortcuts',
        'agent.v1.config.network',
        'agent.v1.config.veyon',
        'agent.v1.config.associations',
        'agent.v1.config.applications-scripts',
    ];

    #[Test]
    public function migration_controller_serve_fragment_routes_are_registered_for_8_endpoints(): void
    {
        foreach (self::EXPECTED_MIGRATION_ROUTES as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull(
                $route,
                "Route `{$name}` introuvable — la transformation D2 n'est pas effective.",
            );
        }
    }

    #[Test]
    public function legacy_out_routes_no_longer_use_business_controllers(): void
    {
        $webRoutes = (string) file_get_contents(__DIR__ . '/../../../routes/web.php');

        // Les 8 chemins URI doivent toujours apparaître (parité).
        $paths = [
            'gpo/shortcuts_out.php',
            'gpo/wallpaper_out.php',
            'gpo/firefox_out.php',
            'gpo/thunderbird_out.php',
            'gpo/network_out.php',
            'gpo/veyon_out.php',
            'gpo/associations_out.php',
            'gpo/applications.php',
        ];
        foreach ($paths as $path) {
            self::assertStringContainsString(
                $path,
                $webRoutes,
                "Chemin legacy `{$path}` manquant dans routes/web.php — risque cassure dual-mode.",
            );
        }

        // Les controllers métier ne doivent plus être référencés comme
        // **target** des routes `gpo/*_out.php` (recherche heuristique :
        // les anciens patterns `[WallpaperController::class, 'legacyOut']`,
        // `[AppPolicyController::class, 'legacyFirefoxOut']`, etc. ne
        // doivent plus apparaître).
        $forbiddenTargets = [
            "[WallpaperController::class, 'legacyOut']",
            "[AppPolicyController::class, 'legacyFirefoxOut']",
            "[AppPolicyController::class, 'legacyThunderbirdOut']",
            "ShortcutExportController::class, 'legacyDispatch'",
            "NetworkOutController::class, 'legacyOut'",
            "VeyonOutController::class, 'legacyOut'",
            "AssociationsOutController::class, 'legacyOut'",
            "ApplicationsScriptsController::class, 'generate'",
        ];
        foreach ($forbiddenTargets as $needle) {
            self::assertStringNotContainsString(
                $needle,
                $webRoutes,
                "Pattern legacy `{$needle}` doit avoir disparu de routes/web.php (D2 transformation).",
            );
        }
    }

    #[Test]
    public function inject_bootstrap_fragment_middleware_no_longer_referenced(): void
    {
        $webRoutes = (string) file_get_contents(__DIR__ . '/../../../routes/web.php');
        $providerSrc = (string) file_get_contents(__DIR__ . '/../../../app/Providers/AuthV1ServiceProvider.php');

        // Aucune occurrence "actionnable" dans routes/web.php : la string
        // `inject.bootstrap-fragment` peut éventuellement persister dans
        // un commentaire historique mais surtout pas dans un
        // `Route::middleware(...)` actif. On grep strict.
        self::assertDoesNotMatchRegularExpression(
            "/Route::middleware\s*\(\s*['\"]inject\.bootstrap-fragment['\"]/i",
            $webRoutes,
            'Aucun `Route::middleware(\'inject.bootstrap-fragment\')` ne doit subsister.',
        );

        // L'alias dans le ServiceProvider doit aussi être retiré.
        self::assertDoesNotMatchRegularExpression(
            "/aliasMiddleware\s*\(\s*['\"]inject\.bootstrap-fragment['\"]/i",
            $providerSrc,
            "AuthV1ServiceProvider::boot() ne doit plus enregistrer l'alias `inject.bootstrap-fragment`.",
        );
    }

    #[Test]
    public function inject_bootstrap_fragment_class_no_longer_exists(): void
    {
        // Suppression hard du middleware (D3). On vérifie côté fichier
        // disque (autoload PSR-4 ne pourrait pas charger un fichier
        // physiquement absent).
        $path = __DIR__ . '/../../../app/Auth/V1/Http/Middleware/InjectBootstrapFragment.php';
        self::assertFileDoesNotExist(
            $path,
            'Le fichier InjectBootstrapFragment.php doit être supprimé (Story 16.13bis D3).',
        );

        self::assertFalse(
            class_exists('App\\Auth\\V1\\Http\\Middleware\\InjectBootstrapFragment'),
            'La classe InjectBootstrapFragment ne doit plus être résolvable.',
        );
    }

    #[Test]
    public function bootstrap_script_controller_no_longer_exists(): void
    {
        $path = __DIR__ . '/../../../app/Auth/V1/Http/Controllers/BootstrapScriptController.php';
        self::assertFileDoesNotExist(
            $path,
            'Le fichier BootstrapScriptController.php doit être supprimé (Story 16.13bis D3).',
        );

        self::assertFalse(
            class_exists('App\\Auth\\V1\\Http\\Controllers\\BootstrapScriptController'),
            'La classe BootstrapScriptController ne doit plus être résolvable.',
        );
    }

    #[Test]
    public function bootstrap_routes_agent_v1_no_longer_registered(): void
    {
        self::assertNull(Route::getRoutes()->getByName('agent.v1.bootstrap.cmd'));
        self::assertNull(Route::getRoutes()->getByName('agent.v1.bootstrap.sh'));
    }

    #[Test]
    public function api_v1_workstation_config_routes_remain_intact(): void
    {
        foreach (self::API_V1_WORKSTATION_CONFIG_ROUTES as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull(
                $route,
                "Route 16.13 `{$name}` doit toujours être enregistrée (non-régression).",
            );
        }
    }

    #[Test]
    public function controlhub_routes_remain_intact(): void
    {
        // Smoke check des routes ControlHub : `/api/v1/snapshot` et
        // `/api/v1/workstation-groups/*` doivent toujours exister.
        $apiRoutes = (string) file_get_contents(__DIR__ . '/../../../routes/api.php');

        self::assertStringContainsString(
            '/snapshot',
            $apiRoutes,
            'Route ControlHub /api/v1/snapshot doit rester intacte.',
        );
        self::assertStringContainsString(
            'workstation-groups',
            $apiRoutes,
            'Routes ControlHub /api/v1/workstation-groups/* doivent rester intactes.',
        );
    }

    #[Test]
    public function legacy_gpo_shim_no_longer_loaded_by_bootstrap(): void
    {
        $bootstrap = (string) file_get_contents(__DIR__ . '/../../../legacy/bootstrap.php');

        self::assertDoesNotMatchRegularExpression(
            "/require_once[^;]+gpo_shim\.inc\.php/i",
            $bootstrap,
            'legacy/bootstrap.php ne doit plus require_once gpo_shim.inc.php.',
        );
    }

    #[Test]
    public function legacy_archived_directory_exists_with_dated_subfolder(): void
    {
        $archivedRoot = __DIR__ . '/../../../legacy/archived';
        self::assertDirectoryExists($archivedRoot, 'legacy/archived/ doit exister.');

        $subfolders = glob($archivedRoot . '/gpo-shim-*', GLOB_ONLYDIR) ?: [];
        self::assertNotEmpty(
            $subfolders,
            'Au moins un sous-dossier legacy/archived/gpo-shim-YYYY-MM-DD/ doit exister.',
        );

        // Le sous-dossier doit contenir gpo_shim.inc.php archivé.
        $hasShim = false;
        foreach ($subfolders as $sub) {
            if (is_file($sub . '/gpo_shim.inc.php')) {
                $hasShim = true;
                break;
            }
        }
        self::assertTrue($hasShim, 'Le shim gpo_shim.inc.php doit être présent dans le sous-dossier daté.');
    }

    #[Test]
    public function old_legacy_route_names_are_removed(): void
    {
        foreach (self::REMOVED_LEGACY_ROUTE_NAMES as $oldName) {
            self::assertNull(
                Route::getRoutes()->getByName($oldName),
                "Ancien nom de route `{$oldName}` doit avoir disparu (D2 rename → migration.legacy.*).",
            );
        }
    }
}
