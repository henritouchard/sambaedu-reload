<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Http\Controllers;

use App\Ipxe\Http\Controllers\IpxeActionController;
use App\Ipxe\Http\Controllers\IpxeAdminController;
use App\Ipxe\Http\Controllers\IpxeMaintenanceController;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 3.2 — AC2.1 / T5.3.
 *
 * Smoke tests des 3 controllers fins — vérifie que la classe est instantiable
 * via le container Laravel (DI résolution OK).
 */
class IpxeAdminControllerSmokeTest extends TestCase
{
    #[Test]
    public function it_can_resolve_ipxe_admin_controller_from_container(): void
    {
        $controller = $this->app->make(IpxeAdminController::class);
        self::assertInstanceOf(IpxeAdminController::class, $controller);
    }

    #[Test]
    public function it_can_resolve_ipxe_maintenance_controller_from_container(): void
    {
        $controller = $this->app->make(IpxeMaintenanceController::class);
        self::assertInstanceOf(IpxeMaintenanceController::class, $controller);
    }

    #[Test]
    public function it_can_resolve_ipxe_action_controller_from_container(): void
    {
        $controller = $this->app->make(IpxeActionController::class);
        self::assertInstanceOf(IpxeActionController::class, $controller);
    }
}
