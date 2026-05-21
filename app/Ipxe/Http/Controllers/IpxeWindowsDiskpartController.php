<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Http\Requests\IpxeWindowsDiskpartRequest;
use App\Ipxe\Services\WindowsInstallBatBuilder;
use App\Ipxe\Services\WorkstationLocator;
use App\Models\MachineBootLog;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 3.5 — AC5.4 / D2.
 *
 * Controller du endpoint `GET|POST /ipxe/windows/diskpart.txt` (port natif
 * `sambaedu/ipxe/Win10/diskpart.php` 27 LOC).
 *
 * **Body statique iso-legacy** : `select disk O\r\nselect partition 1\r\n
 * assign letter=U\r\n`. Aucune interpolation conditionnelle.
 *
 * **Flow** :
 *  1. Valide les inputs (MAC/UUID nullable, best-effort).
 *  2. Résolution Workstation best-effort + log warning si null.
 *  3. Response 200 + body iso-legacy + headers D10.
 *  4. Insert MachineBootLog `action='ipxe_win_diskpart'` (best-effort).
 *
 * **Note 3.7** : ce controller est rendu prêt pour migration ultérieure de
 * `repair.bat.php` legacy qui consomme `Win10/diskpart.php?...` (action winpe
 * 3.2). En 3.5 le template `ipxe.actions.winpe` pointe encore sur le legacy
 * via catchall — pas de modification 3.5.
 */
class IpxeWindowsDiskpartController extends Controller
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly WindowsInstallBatBuilder $builder,
    ) {
    }

    public function handle(IpxeWindowsDiskpartRequest $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $ip = (string) ($request->ip() ?? '');

        // Résolution best-effort (le body est statique, aucun risque si poste
        // inconnu).
        $workstation = $this->locator->locate($mac, $uuid, $product);
        if ($workstation === null) {
            Log::channel($this->channel())->warning('ipxe.windows.diskpart.unknown_workstation', [
                'action_type' => 'ipxe.windows.diskpart.unknown_workstation',
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            ]);
        }

        $body = $this->builder->buildDiskpart();

        Log::channel($this->channel())->info('ipxe.windows.diskpart.served', [
            'action_type' => 'ipxe.windows.diskpart.served',
            'ip' => $ip,
            'workstation_id' => $workstation?->id,
            'workstation_name_prefix' => $workstation !== null
                ? substr((string) ($workstation->name ?? ''), 0, 6)
                : '',
        ]);

        // Audit MachineBootLog (best-effort).
        $this->persistMachineBootLog(
            $workstation?->id,
            (string) ($workstation?->name ?? 'unknown'),
            $ip,
        );

        return $this->respondPlain($body, 200);
    }

    private function persistMachineBootLog(?int $workstationId, string $name, string $ip): void
    {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $workstationId,
                'machine_name' => strtolower($name),
                'action' => 'ipxe_win_diskpart',
                'initiated_by' => 'ipxe',
                'success' => true,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.windows.diskpart.machine_boot_log_failure', [
                'action_type' => 'ipxe.windows.diskpart.machine_boot_log_failure',
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ]);
        }
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
