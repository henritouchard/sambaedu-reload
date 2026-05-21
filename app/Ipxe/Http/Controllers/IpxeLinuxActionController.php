<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeLinuxActionRequest;
use App\Ipxe\Services\LinuxPostInstallTracker;
use App\Ipxe\Services\WorkstationLocator;
use Illuminate\Http\Response;

/**
 * Story 3.4 — AC5.3 / D2.
 *
 * Controller du endpoint `GET|POST /ipxe/linux/action` (port natif
 * `sambaedu/ipxe/linux/action.php`).
 *
 * **Parité legacy** (`preseed.cfg:83`) :
 *   curl -F 'ret=0' -F 'uuid=<uuid>' -F 'name=<hostname>' http://se4fs/ipxe/linux/action
 *
 * **Flow** :
 *  1. Reçoit (uuid, name, ret) après la fin d'install (debian-installer
 *     `late_command`).
 *  2. Résout la Workstation par UUID via {@see WorkstationLocator}.
 *  3. Si null → response 200 vide + log warning (D4 — pas d'echo erreur
 *     legacy `action.php:41`, juste silent + audit).
 *  4. Sinon → délègue à {@see LinuxPostInstallTracker::record()}.
 *  5. Response 200 text/plain vide (parité legacy `linux/action.php:39`).
 */
class IpxeLinuxActionController extends Controller
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly LinuxPostInstallTracker $tracker,
    ) {
    }

    public function handle(IpxeLinuxActionRequest $request): Response
    {
        $uuid = (string) $request->input('uuid', '');
        $name = (string) $request->input('name', '');
        $ret = (int) $request->input('ret', 0);
        $ip = (string) ($request->ip() ?? '');

        // Résolution par UUID uniquement (le hook legacy ne pose pas MAC).
        // Pas de MAC dans le call → WorkstationLocator::locate() prio sur
        // l'UUID; on passe explicitement mac='' / product=''.
        $workstation = $this->locator->locate('', $uuid, '');
        if ($workstation === null) {
            $this->tracker->recordUnknown('', $uuid, $ip);

            return $this->respondPlainEmpty();
        }

        $this->tracker->record($workstation, $ret, $name, $ip);

        return $this->respondPlainEmpty();
    }

    /**
     * Réponse 200 text/plain avec body vide (parité legacy `action.php:39`).
     */
    private function respondPlainEmpty(): Response
    {
        return (new Response('', 200))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }
}
