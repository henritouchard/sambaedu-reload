<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeInstallationLinuxRequest;
use App\Ipxe\Services\IpxeService;
use Illuminate\Http\Response;

/**
 * Controller fin — délègue 100% à
 * {@see IpxeService::handleInstallationLinuxMenu()}.
 *
 * **Sert** : `GET|POST /ipxe/installation-linux` (port natif du legacy
 * `sambaedu/ipxe/installation-linux.php` simplifié).
 *
 * **Middlewares attachés en `routes/web.php`** :
 *
 *  - `auth.v1.lan-only` (restriction RFC1918).
 *  - `throttle:600,1`.
 *
 * Pattern iso (DO-6).
 */
class IpxeInstallationLinuxController extends Controller
{
    public function __construct(
        private readonly IpxeService $service,
    ) {
    }

    public function handle(IpxeInstallationLinuxRequest $request): Response
    {
        return $this->service->handleInstallationLinuxMenu($request);
    }
}
