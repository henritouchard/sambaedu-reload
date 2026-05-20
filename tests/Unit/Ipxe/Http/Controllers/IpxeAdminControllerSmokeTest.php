<?php

declare(strict_types=1);

namespace Tests\Unit\Ipxe\Http\Controllers;

use App\Ipxe\Http\Controllers\IpxeActionController;
use App\Ipxe\Http\Controllers\IpxeAdminController;
use App\Ipxe\Http\Controllers\IpxeEnrollmentByodController;
use App\Ipxe\Http\Controllers\IpxeEnrollmentNameController;
use App\Ipxe\Http\Controllers\IpxeEnrollmentParcAddController;
use App\Ipxe\Http\Controllers\IpxeEnrollmentParcRemoveController;
use App\Ipxe\Http\Controllers\IpxeEnrollmentRoomController;
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

    /* ------------------------------------------------------------------
     * Story 3.3 — AC7.1 / T5.3 — smoke tests des 5 controllers enrollment
     * ------------------------------------------------------------------ */

    #[Test]
    public function it_can_resolve_ipxe_enrollment_name_controller_from_container(): void
    {
        $controller = $this->app->make(IpxeEnrollmentNameController::class);
        self::assertInstanceOf(IpxeEnrollmentNameController::class, $controller);
    }

    #[Test]
    public function it_can_resolve_ipxe_enrollment_byod_controller_from_container(): void
    {
        $controller = $this->app->make(IpxeEnrollmentByodController::class);
        self::assertInstanceOf(IpxeEnrollmentByodController::class, $controller);
    }

    #[Test]
    public function it_can_resolve_ipxe_enrollment_room_controller_from_container(): void
    {
        $controller = $this->app->make(IpxeEnrollmentRoomController::class);
        self::assertInstanceOf(IpxeEnrollmentRoomController::class, $controller);
    }

    #[Test]
    public function it_can_resolve_ipxe_enrollment_parc_add_controller_from_container(): void
    {
        $controller = $this->app->make(IpxeEnrollmentParcAddController::class);
        self::assertInstanceOf(IpxeEnrollmentParcAddController::class, $controller);
    }

    #[Test]
    public function it_can_resolve_ipxe_enrollment_parc_remove_controller_from_container(): void
    {
        $controller = $this->app->make(IpxeEnrollmentParcRemoveController::class);
        self::assertInstanceOf(IpxeEnrollmentParcRemoveController::class, $controller);
    }
}
