<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Story 16.13 — AC6 (D7).
 *
 * Garde-fou architectural sur les 8 nouvelles routes `/api/v1/*` :
 *
 *  1. Les 8 routes nommées `agent.v1.config.{wallpaper,firefox,
 *     thunderbird,shortcuts,network,veyon,associations,
 *     applications-scripts}` existent sous le préfixe `/api/v1/`.
 *  2. Toutes attachent le middleware `auth.v1.workstation` (JWT
 *     RS256 + tier=workstation).
 *  3. Toutes attachent le middleware `auth.v1.secure-headers`
 *     (Cache-Control no-store + HSTS + nosniff).
 *  4. Les 7 routes simples sont en `GET` ; `/api/v1/workstation-config/associations`
 *     est en `GET|POST` (parité legacy `associations_out.php` POST).
 *  5. Les 8 routes legacy `*_out.php` restent inchangées dans
 *     `routes/web.php` (non-régression 16.10 D8).
 *  6. Le middleware `inject.bootstrap-fragment` reste attaché aux
 *     routes legacy (non-régression 16.11 D2).
 *  7. La route ControlHub `/api/v1/snapshot` reste intacte (D8
 *     non-collision ControlHub).
 *  8. Aucun controller des 7 cibles ne lit `workstation_uuid`
 *     depuis query/input (AC6.4 option a — grep statique).
 */
class ApiV1ConfigRoutesTest extends TestCase
{
    private const EXPECTED_ROUTE_NAMES = [
        'agent.v1.config.wallpaper',
        'agent.v1.config.firefox',
        'agent.v1.config.thunderbird',
        'agent.v1.config.shortcuts',
        'agent.v1.config.network',
        'agent.v1.config.veyon',
        'agent.v1.config.associations',
        'agent.v1.config.applications-scripts',
    ];

    /**
     * Controllers ciblés par la story 16.13 — où la sécurité 16.12
     * impose de NE PAS lire `workstation_uuid` depuis input/query.
     *
     * Le namespace `App\Auth\V1` est exclu (légitime — c'est lui qui
     * définit le contrat). Les services `App\Gpo\Services` sont
     * exclus (le `WorkstationConfigContextResolver` accepte le
     * paramètre côté API, c'est la responsabilité du controller).
     */
    private const CONTROLLER_FILES_TO_CHECK = [
        'app/Http/Controllers/WallpaperController.php',
        'app/Http/Controllers/AppPolicyController.php',
        'app/Http/Controllers/Api/v1/ShortcutExportController.php',
        'app/Http/Controllers/Gpo/NetworkOutController.php',
        'app/Http/Controllers/Gpo/VeyonOutController.php',
        'app/Http/Controllers/Gpo/AssociationsOutController.php',
        'app/Http/Controllers/Gpo/ApplicationsScriptsController.php',
    ];

    #[Test]
    public function the_8_routes_are_registered_under_api_v1_workstation_config_prefix(): void
    {
        foreach (self::EXPECTED_ROUTE_NAMES as $name) {
            self::assertTrue(
                Route::has($name),
                "Route nommée `{$name}` introuvable (attendue sous /api/v1/workstation-config/).",
            );

            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, "Route `{$name}` null.");
            self::assertStringStartsWith(
                'api/v1/workstation-config/',
                $route->uri(),
                "Route `{$name}` doit être sous /api/v1/workstation-config/ "
                . "(post-review Henri Q4 2026-05-19, URI actuel : {$route->uri()}).",
            );
        }
    }

    #[Test]
    public function no_legacy_unprefixed_routes_remain_for_workstation_config(): void
    {
        // Q4 + Q4ter post-review : garde-fou explicite — aucune des 8
        // anciennes routes plates `/api/v1/{wallpaper,firefox,...}` ne
        // doit subsister sous le middleware `auth.v1.workstation`. Cela
        // empêche toute regression vers l'ancien layout qui collisionnait
        // avec ControlHub.
        $legacyFlatUris = [
            'api/v1/wallpaper',
            'api/v1/firefox',
            'api/v1/thunderbird',
            'api/v1/shortcuts',
            'api/v1/network',
            'api/v1/veyon',
            'api/v1/associations',
            'api/v1/applications-scripts',
        ];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array($uri, $legacyFlatUris, true)) {
                continue;
            }
            $middlewares = $route->gatherMiddleware();
            $authV1Attached = collect($middlewares)->contains(
                fn ($mw): bool => str_contains((string) $mw, 'auth.v1.workstation')
                    || str_contains((string) $mw, 'EnsureWorkstationJwt'),
            );
            self::assertFalse(
                $authV1Attached,
                "URI `{$uri}` ne doit PAS être attaché à `auth.v1.workstation` "
                . '— il a été déplacé sous /api/v1/workstation-config/ (Henri Q4).',
            );
        }
    }

    #[Test]
    public function all_8_routes_have_auth_v1_workstation_middleware(): void
    {
        foreach (self::EXPECTED_ROUTE_NAMES as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, "Route `{$name}` introuvable.");

            $middlewares = $route->gatherMiddleware();
            $hasAuthV1 = collect($middlewares)->contains(function ($mw): bool {
                $mwStr = (string) $mw;
                return str_contains($mwStr, 'auth.v1.workstation')
                    || str_contains($mwStr, 'EnsureWorkstationJwt');
            });

            self::assertTrue(
                $hasAuthV1,
                "Route `{$name}` doit attacher `auth.v1.workstation` "
                . '(actuels middlewares : ' . implode(', ', array_map('strval', $middlewares)) . ').',
            );
        }
    }

    #[Test]
    public function all_8_routes_have_auth_v1_secure_headers_middleware(): void
    {
        foreach (self::EXPECTED_ROUTE_NAMES as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route, "Route `{$name}` introuvable.");

            $middlewares = $route->gatherMiddleware();
            $hasSecureHeaders = collect($middlewares)->contains(function ($mw): bool {
                $mwStr = (string) $mw;
                return str_contains($mwStr, 'auth.v1.secure-headers')
                    || str_contains($mwStr, 'EnsureSecureApiHeaders');
            });

            self::assertTrue(
                $hasSecureHeaders,
                "Route `{$name}` doit attacher `auth.v1.secure-headers`.",
            );
        }
    }

    #[Test]
    public function routes_use_correct_http_methods(): void
    {
        // 7 routes en GET only.
        $getOnly = [
            'agent.v1.config.wallpaper',
            'agent.v1.config.firefox',
            'agent.v1.config.thunderbird',
            'agent.v1.config.shortcuts',
            'agent.v1.config.network',
            'agent.v1.config.veyon',
            'agent.v1.config.applications-scripts',
        ];
        foreach ($getOnly as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);
            self::assertContains('GET', $route->methods(), "Route `{$name}` doit accepter GET.");
            self::assertNotContains('POST', $route->methods(), "Route `{$name}` ne doit PAS accepter POST.");
        }

        // /associations accepte GET|POST (parité legacy associations_out.php POST).
        $assocRoute = Route::getRoutes()->getByName('agent.v1.config.associations');
        self::assertNotNull($assocRoute);
        self::assertContains('GET', $assocRoute->methods());
        self::assertContains('POST', $assocRoute->methods());
    }

    #[Test]
    public function legacy_out_routes_are_still_preserved(): void
    {
        // Non-régression 16.10 D8 — la suite 16.13 n'a pas
        // accidentellement supprimé les 8 routes legacy.
        $webRoutes = (string) file_get_contents(__DIR__ . '/../../routes/web.php');
        $apiRoutes = (string) file_get_contents(__DIR__ . '/../../routes/api.php');
        $allRoutes = $webRoutes . "\n" . $apiRoutes;

        $expected = [
            'wallpaper_out',
            'firefox_out',
            'thunderbird_out',
            'shortcuts_out',
            'network_out',
            'veyon_out',
            'associations_out',
            'applications.php',
        ];

        foreach ($expected as $endpoint) {
            self::assertStringContainsString(
                $endpoint,
                $allRoutes,
                "Route legacy `{$endpoint}` introuvable — non-régression 16.10 D8 cassée.",
            );
        }
    }

    #[Test]
    public function legacy_routes_now_point_to_migration_controller(): void
    {
        // Story 16.13bis — bascule D2 : le middleware
        // `inject.bootstrap-fragment` 16.11 a été supprimé ; les 8 routes
        // legacy pointent désormais vers `MigrationController::serveFragment`.
        // Les noms de routes sont `migration.legacy.*`.
        foreach ([
            'migration.legacy.shortcuts',
            'migration.legacy.wallpaper',
            'migration.legacy.firefox',
            'migration.legacy.thunderbird',
            'migration.legacy.network',
            'migration.legacy.veyon',
            'migration.legacy.associations',
            'migration.legacy.applications',
        ] as $name) {
            self::assertNotNull(
                Route::getRoutes()->getByName($name),
                "Route `{$name}` doit être enregistrée après transformation Story 16.13bis (D2).",
            );
        }
    }

    #[Test]
    public function controlhub_routes_remain_intact(): void
    {
        // Non-régression D8 — les routes ControlHub sous `/api/v1/*`
        // ne doivent pas avoir collisionné avec les nouvelles 16.13.
        self::assertTrue(
            Route::has('snapshot'),
            'Route ControlHub `/api/v1/snapshot` manquante (D8 collision).',
        );

        $snapshot = Route::getRoutes()->getByName('snapshot');
        self::assertNotNull($snapshot);
        self::assertSame('api/v1/snapshot', $snapshot->uri());

        // ControlHub `/api/v1/shortcuts/sync` reste intact.
        // Post-review Q4 : nos endpoints sont sous `/api/v1/workstation-config/*`
        // donc aucune cohabitation possible avec le namespace ControlHub `/api/v1/shortcuts/*`.
        self::assertTrue(
            Route::has('shortcut.sync'),
            'Route ControlHub `/api/v1/shortcuts/sync` manquante.',
        );
    }

    #[Test]
    public function no_controller_reads_workstation_uuid_from_input(): void
    {
        // AC6.4 option a — grep statique défense en profondeur.
        $violations = [];
        $base = realpath(__DIR__ . '/../..');
        self::assertNotFalse($base);

        foreach (self::CONTROLLER_FILES_TO_CHECK as $relPath) {
            $path = $base . '/' . $relPath;
            $content = (string) file_get_contents($path);

            // Recherche des patterns interdits.
            $forbidden = [
                "input('workstation_uuid'",
                'input("workstation_uuid"',
                "query('workstation_uuid'",
                'query("workstation_uuid"',
                "get('workstation_uuid'",
                'get("workstation_uuid"',
            ];

            foreach ($forbidden as $needle) {
                // Tolérance pour `$request->attributes->get(...)` —
                // c'est la lecture autorisée et exclusive.
                $contentSansAttributes = preg_replace(
                    '/\$request->attributes->get\([^)]*\)/i',
                    '/*ATTRIBUTES_GET_OK*/',
                    $content,
                ) ?? $content;

                if (str_contains($contentSansAttributes, $needle)) {
                    $violations[] = "{$relPath} contient `{$needle}` (interdit AC6.4 — lecture forcée via auth_v1.workstation_uuid attribute).";
                }
            }
        }

        self::assertSame(
            [],
            $violations,
            "Violations AC6.4 — workstation_uuid lu depuis input/query :\n  - " . implode("\n  - ", $violations),
        );
    }

    #[Test]
    public function all_8_routes_have_throttle_300_middleware(): void
    {
        foreach (self::EXPECTED_ROUTE_NAMES as $name) {
            $route = Route::getRoutes()->getByName($name);
            self::assertNotNull($route);

            $middlewares = $route->gatherMiddleware();
            $hasThrottle = collect($middlewares)->contains(function ($mw): bool {
                return str_contains((string) $mw, 'throttle:300');
            });

            self::assertTrue(
                $hasThrottle,
                "Route `{$name}` doit attacher `throttle:300,1` (parité 16.3b).",
            );
        }
    }
}
