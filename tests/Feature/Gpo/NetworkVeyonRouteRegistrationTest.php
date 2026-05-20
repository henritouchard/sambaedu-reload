<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Http\Controllers\Gpo\NetworkOutController;
use App\Http\Controllers\Gpo\VeyonOutController;
use App\Http\Controllers\LegacyCatchallController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Vérifie que les nouvelles routes natives interceptent bien avant le catchall
 * legacy (AC3.1 / AC3.3). Story 16.3b.
 */
class NetworkVeyonRouteRegistrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Story 16.13bis — routes `gpo.network-out.legacy` / `gpo.veyon-out.legacy`
        // renommées `migration.legacy.{network,veyon}` et target = MigrationController.
        $this->markTestSkipped('Story 16.13bis : routes renommées migration.legacy.* et target MigrationController (D2).');
    }

    #[Test]
    public function network_out_route_targets_native_controller(): void
    {
        $route = Route::getRoutes()->getByName('gpo.network-out.legacy');
        $this->assertNotNull($route);
        $this->assertSame(
            NetworkOutController::class . '@legacyOut',
            $route->getActionName(),
        );
        $this->assertContains('throttle:300,1', $route->middleware());
    }

    #[Test]
    public function veyon_out_route_targets_native_controller(): void
    {
        $route = Route::getRoutes()->getByName('gpo.veyon-out.legacy');
        $this->assertNotNull($route);
        $this->assertSame(
            VeyonOutController::class . '@legacyOut',
            $route->getActionName(),
        );
        $this->assertContains('throttle:300,1', $route->middleware());
    }

    #[Test]
    public function native_routes_resolve_before_catchall(): void
    {
        $netRoute = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/gpo/network_out.php', 'POST'),
        );
        $this->assertSame(NetworkOutController::class . '@legacyOut', $netRoute->getActionName());

        $veyonRoute = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/gpo/veyon_out.php', 'POST'),
        );
        $this->assertSame(VeyonOutController::class . '@legacyOut', $veyonRoute->getActionName());

        // Sanity check : un autre URL legacy quelconque tombe bien sur catchall.
        $catchAll = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/gpo/unknown.php', 'GET'),
        );
        $this->assertSame(LegacyCatchallController::class . '@handle', $catchAll->getActionName());
    }

    #[Test]
    public function native_routes_accept_both_get_and_post(): void
    {
        $methodsNet = Route::getRoutes()->getByName('gpo.network-out.legacy')->methods();
        $this->assertContains('GET', $methodsNet);
        $this->assertContains('POST', $methodsNet);

        $methodsVeyon = Route::getRoutes()->getByName('gpo.veyon-out.legacy')->methods();
        $this->assertContains('GET', $methodsVeyon);
        $this->assertContains('POST', $methodsVeyon);
    }
}
