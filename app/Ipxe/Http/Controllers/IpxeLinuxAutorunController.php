<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeLinuxAutorunRequest;
use App\Ipxe\Services\WorkstationLocator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.4 — AC5.4 / D15.
 *
 * Controller du endpoint `GET|POST /ipxe/linux/autorun` — **stub minimal**.
 *
 * **Décision D15** : le legacy `linux/autorun.php` construit un script bash
 * complet qui boucle pour exécuter des scripts post-install lus depuis l'AD
 * (`get_action($config, $uuid)`). Ce mécanisme n'est plus pertinent en SE5
 * (Epic 17 `script_assignments` est l'alternative).
 *
 * **Implémentation stub** :
 *   #!/bin/bash
 *   echo "install Linux completed for <name> (<uuid>)"
 *   exit 0
 *
 * Le sysrescuecd `ar_source=...` consomme ce script en boucle, mais avec
 * un `exit 0` immédiat, la boucle se termine sans action (= comportement
 * dégradé acceptable Phase 2).
 *
 * Si Henri arbitre besoin du flow complet → ouvrir story Phase 3 dédiée.
 */
class IpxeLinuxAutorunController extends Controller
{
    public function __construct(
        private readonly WorkstationLocator $locator,
    ) {
    }

    public function handle(IpxeLinuxAutorunRequest $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $name = (string) $request->input('name', '');
        $ip = (string) ($request->ip() ?? '');

        // Résolution best-effort pour audit (D4 — poste inconnu → réponse
        // 200 stub + log warning).
        $workstation = $this->locator->locate($mac, $uuid, '');

        Log::channel($this->channel())->info('ipxe.linux.autorun.served', [
            'action_type' => 'ipxe.linux.autorun.served',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
        ]);

        // Stub bash minimal. ASCII strict, pas de variables shell qui
        // pourraient être interprétées ($name / $uuid sont sanitizés AVANT
        // injection via echo single-quote).
        $safeName = $this->sanitizeShellArg($workstation !== null ? (string) $workstation->name : $name);
        $safeUuid = $this->sanitizeShellArg($uuid);

        $body = "#!/bin/bash\n"
            . "echo 'install Linux completed for {$safeName} ({$safeUuid})'\n"
            . "exit 0\n";

        return (new Response($body, 200))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    /**
     * Sanitize ASCII + tronque 32 chars + strip caractères shell-special
     * (`'`, `"`, `;`, `|`, `&`, `\`, backtick, `$`).
     */
    private function sanitizeShellArg(string $value): string
    {
        $truncated = substr($value, 0, 32);
        $clean = preg_replace("/[^A-Za-z0-9\\-_.:]/", '?', $truncated);

        return $clean ?? '';
    }

    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }
}
