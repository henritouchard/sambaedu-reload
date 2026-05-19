<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeBootRequest;
use App\Ipxe\Services\IpxeService;
use Illuminate\Http\Response;

/**
 * Story 3.1 — AC5.1 / D2.
 *
 * Controller fin — délègue 100% à {@see IpxeService::handleBoot()}.
 *
 * **Sert** :
 *
 *  - `GET|POST /ipxe/boot`
 *  - `GET /ipxe/boot.ipxe` (alias parité legacy)
 *
 * **Middlewares attachés en `routes/web.php`** :
 *
 *  - `auth.v1.lan-only` (16.11 — restriction RFC1918, D3).
 *  - `throttle:600,1` (10 retries/poste/min — D2).
 *
 * **Anti-pattern** : pas de logique métier, pas d'accès direct à `Workstation`,
 * pas de Log direct. Tout est dans `IpxeService` qui est mockable en test
 * unit.
 *
 * Décision DO-6 : controller < 20 lignes (sans docblocks) — single
 * responsibility, simple à reviewer.
 */
class IpxeBootController extends Controller
{
    public function __construct(
        private readonly IpxeService $service,
    ) {
    }

    public function handle(IpxeBootRequest $request): Response
    {
        return $this->service->handleBoot($request);
    }
}
