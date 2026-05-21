<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Enums\WindowsInstallStep;
use App\Ipxe\Http\Requests\IpxeWindowsActionRequest;
use App\Ipxe\Services\WindowsPostInstallTracker;
use App\Ipxe\Services\WorkstationLocator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.5 — AC5.6 / D2.
 *
 * Controller du endpoint `GET|POST /ipxe/windows/action` (port natif PARTIEL
 * `sambaedu/ipxe/Win10/action.php` — scope 3.5 = étapes `winpe` + `oobe`
 * seulement, autres étapes déférées 3.7).
 *
 * **Parité legacy** (`install.bat.php:57` + `unattend.xml:112`) :
 *   curl -F 'etape=winpe' -F 'name=PC-101' -F 'ret=0' http://se4fs/ipxe/windows/action
 *   curl -F 'etape=oobe' -F 'name=%computername%' -o action.cmd http://se4fs/ipxe/windows/action
 *
 * **Flow** :
 *  1. Reçoit (name, uuid, etape, ret) via multipart form-data.
 *  2. Résout la Workstation par UUID via {@see WorkstationLocator}. Si null
 *     → 200 + body vide + log warning (D4).
 *  3. Parse `etape` via {@see WindowsInstallStep::fromString()}. Si null →
 *     200 + log warning `ipxe.windows.action.unsupported_step` (déférée 3.7).
 *  4. Dispatch :
 *     - `winpe` + `ret='0'` → `tracker->recordWinpeStart()`.
 *     - `oobe`  + `ret='0'` → `tracker->recordOobeComplete()`.
 *     - autre combinaison → 200 + log warning.
 *  5. Response 200 + body vide + headers D10.
 */
class IpxeWindowsActionController extends Controller
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly WindowsPostInstallTracker $tracker,
    ) {
    }

    public function handle(IpxeWindowsActionRequest $request): Response
    {
        $name = (string) $request->input('name', '');
        $uuid = (string) $request->input('uuid', '');
        $mac = (string) $request->input('mac', '');
        $rawEtape = (string) $request->input('etape', '');
        $ret = (string) $request->input('ret', '');
        $ip = (string) ($request->ip() ?? '');

        // 1. Résolution Workstation (priorité UUID, fallback MAC iso 3.4 D4).
        $workstation = $this->locator->locate($mac, $uuid, '');
        if ($workstation === null) {
            $this->tracker->recordUnknown($uuid, $name, $ip);

            return $this->respondPlainEmpty();
        }

        // 2. Parse étape via enum whitelist.
        // Post-review code-review #N3 (décision Henri) : on renvoie 200 silent
        // sur étape inconnue (au lieu de 422) pour préparer 3.7 — les postes
        // WinPE legacy POST déjà des étapes (sysprep/join/etc.) qui ne sont
        // pas encore portées. Renvoyer 422 casserait ces postes en transition.
        // Le log warning ci-dessous trace l'événement pour audit / debug.
        $step = WindowsInstallStep::fromString($rawEtape);
        if ($step === null) {
            Log::channel($this->channel())->warning('ipxe.windows.action.unsupported_step', [
                'action_type' => 'ipxe.windows.action.unsupported_step',
                'ip' => $ip,
                'workstation_id' => $workstation->id ?? null,
                'raw_etape' => $this->sanitizeRaw($rawEtape),
                'ret' => $this->sanitizeRaw($ret),
            ]);

            return $this->respondPlainEmpty();
        }

        // 3. Dispatch step + ret.
        if ($ret !== '0') {
            // Étape connue mais ret ≠ 0 — déféré 3.7 (les codes retour
            // partiels sont gérés par `action.php` legacy en multi-étapes).
            // Scope 3.5 = log warning + 200 silent.
            Log::channel($this->channel())->warning('ipxe.windows.action.non_zero_ret', [
                'action_type' => 'ipxe.windows.action.non_zero_ret',
                'ip' => $ip,
                'workstation_id' => $workstation->id ?? null,
                'etape' => $step->value,
                'ret' => $this->sanitizeRaw($ret),
            ]);

            return $this->respondPlainEmpty();
        }

        match ($step) {
            WindowsInstallStep::Winpe => $this->tracker->recordWinpeStart($workstation, $name, $ip),
            WindowsInstallStep::Oobe => $this->tracker->recordOobeComplete($workstation, $name, $ip),
        };

        return $this->respondPlainEmpty();
    }

    private function sanitizeRaw(string $raw): string
    {
        $truncated = substr($raw, 0, 32);
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $truncated);

        return $clean ?? $truncated;
    }

    /**
     * Réponse 200 text/plain avec body vide (parité legacy `action.php:519`).
     */
    private function respondPlainEmpty(): Response
    {
        return (new Response('', 200))
            ->header('Content-Type', 'text/plain; charset=utf-8')
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex');
    }

    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }
}
