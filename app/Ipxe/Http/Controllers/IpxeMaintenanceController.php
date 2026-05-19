<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeMaintenanceRequest;
use App\Ipxe\Services\IpxeService;
use Illuminate\Http\Response;

/**
 * Story 3.2 — AC2.1 / D2.
 *
 * Controller fin — délègue 100% à {@see IpxeService::handleMaintenance()}.
 *
 * **Sert** : `GET|POST /ipxe/maintenance` (port natif
 * `sambaedu/ipxe/maintenance.php`).
 *
 * Pattern iso 3.1 `IpxeBootController` (DO-6).
 */
class IpxeMaintenanceController extends Controller
{
    public function __construct(
        private readonly IpxeService $service,
    ) {
    }

    public function handle(IpxeMaintenanceRequest $request): Response
    {
        return $this->service->handleMaintenance($request);
    }
}
