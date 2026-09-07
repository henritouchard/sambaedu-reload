<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeInstallationWindowsRequest;
use App\Ipxe\Services\IpxeService;
use Illuminate\Http\Response;

/**
 * Controller fin — délègue 100% à
 * {@see IpxeService::handleInstallationWindowsMenu()}.
 *
 * **Sert** : `GET|POST /ipxe/installation-windows` (port natif du legacy
 * `sambaedu/ipxe/installation-windows.php` 110 LOC).
 *
 * **Middlewares attachés en `routes/web.php`** :
 *
 *  - `auth.v1.lan-only` (restriction RFC1918).
 *  - `throttle:600,1`.
 *
 * Pattern iso aux contrôleurs iPXE voisins.
 */
class IpxeInstallationWindowsController extends Controller
{
    public function __construct(
        private readonly IpxeService $service,
    ) {
    }

    public function handle(IpxeInstallationWindowsRequest $request): Response
    {
        return $this->service->handleInstallationWindowsMenu($request);
    }
}
