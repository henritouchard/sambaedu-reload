<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Enums\WindowsVersion;
use App\Ipxe\Http\Requests\IpxeWindowsInstallBatRequest;
use App\Ipxe\Services\WindowsInstallBatBuilder;
use App\Ipxe\Services\WindowsPostInstallTracker;
use App\Ipxe\Services\WorkstationLocator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Story 3.5 — AC5.3 / D2.
 *
 * Controller du endpoint `GET|POST /ipxe/windows/install.bat` (port natif
 * `sambaedu/ipxe/Win10/install.bat.php` 73 LOC).
 *
 * **Flow** :
 *  1. Valide les inputs via {@see IpxeWindowsInstallBatRequest}.
 *  2. Résout la Workstation via {@see WorkstationLocator}.
 *  3. Si null → 200 + body vide + log warning (D4 — parité legacy
 *     `install.bat.php:32` qui n'écrit rien sinon).
 *  4. Parse `version` via {@see WindowsVersion::fromString()}. Si null → 422 + log.
 *  5. Génère le bash via {@see WindowsInstallBatBuilder::build()}.
 *  6. Tracker {@see WindowsPostInstallTracker::recordInstallBatGenerated()}
 *     pour l'audit MachineBootLog `ipxe_win_install`.
 *  7. Log info `ipxe.windows.install_bat.generated` (sha256 only, jamais le bash).
 *  8. Response 200 text/plain + headers D10.
 *
 * **Sécurité** : middleware `auth.v1.lan-only` (LAN scolaire) + matching
 * MAC/UUID strict via locator + sanitize shell-arg dans le builder.
 */
class IpxeWindowsInstallBatController extends Controller
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly WindowsInstallBatBuilder $builder,
        private readonly WindowsPostInstallTracker $tracker,
    ) {
    }

    public function handle(IpxeWindowsInstallBatRequest $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $rawVersion = (string) $request->input('version', 'Win11');
        $bios = (string) $request->input('bios', 'legacy');
        $debug = (int) filter_var($request->input('debug', 0), FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        $perso = (int) filter_var($request->input('perso', 0), FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        $ip = (string) ($request->ip() ?? '');

        // 1. Résolution Workstation.
        $workstation = $this->locator->locate($mac, $uuid, $product);
        if ($workstation === null) {
            Log::channel($this->channel())->warning('ipxe.windows.install_bat.unknown_workstation', [
                'action_type' => 'ipxe.windows.install_bat.unknown_workstation',
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            ]);

            // Parité legacy install.bat.php:32 — 200 + body vide + log warning.
            return $this->respondPlain('', 200);
        }

        // 2. Parse version via enum whitelist.
        $version = WindowsVersion::fromString($rawVersion);
        if ($version === null) {
            Log::channel($this->channel())->warning('ipxe.windows.install_bat.invalid_version', [
                'action_type' => 'ipxe.windows.install_bat.invalid_version',
                'ip' => $ip,
                'raw_version' => $this->sanitizeRaw($rawVersion),
            ]);

            return $this->respondPlain('', 422);
        }

        // 3. Génération bash WinPE.
        $bash = $this->builder->build(
            $workstation,
            $version,
            ['bios' => $bios, 'debug' => $debug, 'perso' => $perso],
        );

        // 4. Audit + tracker (MachineBootLog action='ipxe_win_install').
        $this->tracker->recordInstallBatGenerated($workstation, $ip);

        Log::channel($this->channel())->info('ipxe.windows.install_bat.generated', [
            'action_type' => 'ipxe.windows.install_bat.generated',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            'version' => $version->value,
            'debug' => $debug,
            'bash_sha256' => hash('sha256', $bash),
            'bash_size_bytes' => strlen($bash),
        ]);

        return $this->respondPlain($bash, 200);
    }

    private function sanitizeRaw(string $raw): string
    {
        $truncated = substr($raw, 0, 32);
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $truncated);

        return $clean ?? $truncated;
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
