<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeAdminRequest;
use App\Ipxe\Services\IpxeService;
use Illuminate\Http\Response;

/**
 * Story 3.2 — AC2.1 / D2.
 *
 * Controller fin — délègue 100% à {@see IpxeService::handleAdmin()}.
 *
 * **Sert** : `GET|POST /ipxe/admin` (port natif du legacy
 * `sambaedu/ipxe/admin.php` simplifié — sans login AD, sans set-name).
 *
 * **Middlewares attachés en `routes/web.php`** :
 *
 *  - `auth.v1.lan-only` (16.11 — restriction RFC1918).
 *  - `throttle:600,1`.
 *
 * Pattern iso 3.1 `IpxeBootController` (DO-6).
 */
class IpxeAdminController extends Controller
{
    public function __construct(
        private readonly IpxeService $service,
    ) {
    }

    public function handle(IpxeAdminRequest $request): Response
    {
        return $this->service->handleAdmin($request);
    }
}
