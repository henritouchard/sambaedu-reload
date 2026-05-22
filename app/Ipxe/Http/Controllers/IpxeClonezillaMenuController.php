<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeClonezillaMenuRequest;
use App\Ipxe\Services\IpxeService;
use Illuminate\Http\Response;

/**
 * Story 3.7 — AC3.1 / D1 / D4.
 *
 * Controller fin — delègue 100% à {@see IpxeService::handleClonezillaMenu()}.
 *
 * **Sert** : `GET|POST /ipxe/clonezilla-menu` (port natif
 * `sambaedu/ipxe/clonezilla_menu.php`).
 *
 * Pattern iso 3.2 `IpxeMaintenanceController` (DO-6).
 */
class IpxeClonezillaMenuController extends Controller
{
    public function __construct(
        private readonly IpxeService $service,
    ) {
    }

    public function handle(IpxeClonezillaMenuRequest $request): Response
    {
        return $this->service->handleClonezillaMenu($request);
    }
}
