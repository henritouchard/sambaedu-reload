<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeEnrollmentNameRequest;
use App\Ipxe\Services\IpxeEnrollmentOrchestrator;
use Illuminate\Http\Response;

/**
 * Story 3.3 — AC7.1.
 *
 * Controller fin — délègue 100% à {@see IpxeEnrollmentOrchestrator::handleName()}.
 *
 * **Sert** : `GET|POST /ipxe/enrollment/name` (port natif de
 * `sambaedu/ipxe/enregistrement.php`).
 *
 * **Middlewares routes/web.php** : `auth.v1.lan-only` + `throttle:600,1`.
 */
class IpxeEnrollmentNameController extends Controller
{
    public function __construct(
        private readonly IpxeEnrollmentOrchestrator $orchestrator,
    ) {
    }

    public function handle(IpxeEnrollmentNameRequest $request): Response
    {
        return $this->orchestrator->handleName($request);
    }
}
