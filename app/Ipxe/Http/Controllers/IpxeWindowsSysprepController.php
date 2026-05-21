<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeWindowsSysprepRequest;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.5 — AC5.5 / D15.
 *
 * Controller du endpoint `GET|POST /ipxe/windows/sysprep.xml`.
 *
 * **STUB MINIMAL D15** — la logique complète legacy `sysprep.xml.php:14-39`
 * dépend de `$machine['action']['type']` (= `renomme|clonage|postinst`) qui
 * consomme la GLM `actions[]` LDAP **non portée en SE5** (Epic 17.2 alternative).
 *
 * **Décision 3.5** : porter un endpoint stub qui :
 *  - Accepte les inputs `name` nullable.
 *  - Retourne 200 + body vide.
 *  - Log info `ipxe.windows.sysprep.stub_served` (audit traçabilité).
 *  - Pas d'insert MachineBootLog (stub minimal).
 *
 * Le vrai porting = Story 3.7 (clonage) qui enrichira
 * `IpxeProgrammedActionResolver`.
 */
class IpxeWindowsSysprepController extends Controller
{
    public function handle(IpxeWindowsSysprepRequest $request): Response
    {
        $name = (string) $request->input('name', '');
        $ip = (string) ($request->ip() ?? '');

        Log::channel($this->channel())->info('ipxe.windows.sysprep.stub_served', [
            'action_type' => 'ipxe.windows.sysprep.stub_served',
            'ip' => $ip,
            'reported_name_prefix' => $name !== '' ? substr($name, 0, 6) : '',
        ]);

        return $this->respondPlain('', 200);
    }

    private function respondPlain(string $body, int $status): Response
    {
        return (new Response($body, $status))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }
}
