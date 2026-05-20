<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeEnrollmentByodRequest;
use App\Ipxe\Services\IpxeEnrollmentOrchestrator;
use Illuminate\Http\Response;

/**
 * Story 3.3 — AC7.1.
 *
 * Controller fin — délègue 100% à {@see IpxeEnrollmentOrchestrator::handleByod()}.
 *
 * **Sert** : `GET|POST /ipxe/enrollment/byod` (port simplifié de
 * `sambaedu/ipxe/enregistrement_byod.php` — stub 3.3, extension 3.4).
 */
class IpxeEnrollmentByodController extends Controller
{
    public function __construct(
        private readonly IpxeEnrollmentOrchestrator $orchestrator,
    ) {
    }

    public function handle(IpxeEnrollmentByodRequest $request): Response
    {
        return $this->orchestrator->handleByod($request);
    }
}
