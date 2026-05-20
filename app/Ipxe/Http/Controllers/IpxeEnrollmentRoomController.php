<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeEnrollmentRoomRequest;
use App\Ipxe\Services\IpxeEnrollmentOrchestrator;
use Illuminate\Http\Response;

/**
 * Story 3.3 — AC7.1.
 *
 * Controller fin — délègue 100% à {@see IpxeEnrollmentOrchestrator::handleRoom()}.
 *
 * **Sert** : `GET|POST /ipxe/enrollment/room` (port natif de
 * `sambaedu/ipxe/salles.php`).
 */
class IpxeEnrollmentRoomController extends Controller
{
    public function __construct(
        private readonly IpxeEnrollmentOrchestrator $orchestrator,
    ) {
    }

    public function handle(IpxeEnrollmentRoomRequest $request): Response
    {
        return $this->orchestrator->handleRoom($request);
    }
}
