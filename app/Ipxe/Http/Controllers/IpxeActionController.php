<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeActionRequest;
use App\Ipxe\Services\IpxeService;
use Illuminate\Http\Response;

/**
 * Controller fin — délègue 100% à {@see IpxeService::handleAction()}.
 *
 * **Sert** : `GET|POST /ipxe/action/{action}` (port natif partiel du legacy
 * `sambaedu/ipxe/action.php` — whitelist enum {@see \App\Ipxe\Enums\IpxeAdminAction}
 * de 3 cases stricts : `rescuecd`, `winpe`, `factory_reset`).
 *
 * **Sécurité** :
 *
 *  - Route-level filter `->where('action', '[a-z_]+')` (cf. `routes/web.php`)
 *    bloque déjà les caractères dangereux (`/`, `..`, `;`, `&`).
 *  - Whitelist enum dans `IpxeService::handleAction()` est l'autorité
 *    finale — toute valeur hors whitelist retourne 404 + log warning
 *    `ipxe.action.unknown_action`.
 *
 * Pattern iso `IpxeBootController` (DO-6).
 */
class IpxeActionController extends Controller
{
    public function __construct(
        private readonly IpxeService $service,
    ) {
    }

    public function handle(IpxeActionRequest $request, string $action): Response
    {
        return $this->service->handleAction($request, $action);
    }
}
