<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeEnrollmentParcRequest;
use App\Ipxe\Services\IpxeEnrollmentOrchestrator;
use Illuminate\Http\Response;

/**
 * Story 3.3 — AC7.1.
 *
 * Controller fin — délègue 100% à
 * {@see IpxeEnrollmentOrchestrator::handleParcRemove()}.
 *
 * **Sert** : `GET|POST /ipxe/enrollment/parc-remove` (port natif de
 * `sambaedu/ipxe/enleveparc.php`).
 */
class IpxeEnrollmentParcRemoveController extends Controller
{
    public function __construct(
        private readonly IpxeEnrollmentOrchestrator $orchestrator,
    ) {
    }

    public function handle(IpxeEnrollmentParcRequest $request): Response
    {
        return $this->orchestrator->handleParcRemove($request);
    }
}
