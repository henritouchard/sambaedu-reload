<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Http\Controllers\Gpo\AssociationsOutController;
use App\Http\Controllers\LegacyCatchallController;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Test Feature — Route `gpo/associations_out.php` enregistrée AVANT catchall.
 *
 * Story 16.3c — AC4.1.
 */
class AssociationsOutRouteRegistrationTest extends TestCase
{
    #[Test]
    public function associations_out_route_targets_native_controller(): void
    {
        $route = Route::getRoutes()->getByName('gpo.associations-out.legacy');
        $this->assertNotNull($route);
        $this->assertSame(
            AssociationsOutController::class . '@legacyOut',
            $route->getActionName(),
        );
        $this->assertContains('throttle:300,1', $route->middleware());
    }

    #[Test]
    public function associations_out_post_resolves_before_catchall(): void
    {
        $matched = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/gpo/associations_out.php', 'POST'),
        );
        $this->assertSame(
            AssociationsOutController::class . '@legacyOut',
            $matched->getActionName(),
        );
    }

    #[Test]
    public function associations_out_get_falls_through_to_catchall(): void
    {
        // AC3.1 — endpoint POST only. GET tombe sur catchall.
        $matched = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/gpo/associations_out.php', 'GET'),
        );
        $this->assertSame(
            LegacyCatchallController::class . '@handle',
            $matched->getActionName(),
        );
    }

    #[Test]
    public function associations_out_route_only_accepts_post(): void
    {
        $methods = Route::getRoutes()->getByName('gpo.associations-out.legacy')->methods();
        $this->assertContains('POST', $methods);
        // HEAD est ajouté automatiquement par Laravel sur les routes POST/GET ; vérifier seulement absence de GET.
        $this->assertNotContains('GET', $methods);
    }
}
