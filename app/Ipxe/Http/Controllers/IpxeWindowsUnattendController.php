<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Enums\WindowsVersion;
use App\Ipxe\Exceptions\UnattendGenerationException;
use App\Ipxe\Http\Requests\IpxeWindowsUnattendRequest;
use App\Ipxe\Services\WindowsUnattendBuilder;
use App\Ipxe\Services\WorkstationLocator;
use App\Models\MachineBootLog;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Story 3.5 — AC5.2 / D2.
 *
 * Controller du endpoint `GET|POST /ipxe/windows/unattend.xml` (port natif
 * `sambaedu/ipxe/Win10/unattend.xml.php` 39 LOC + `windows.inc.php:3-380`).
 *
 * **Flow** :
 *  1. Valide les inputs via {@see IpxeWindowsUnattendRequest}.
 *  2. Résout la Workstation via {@see WorkstationLocator}.
 *  3. Si null → 404 + log warning (D4 — cohérence 3.4 preseed unknown 404).
 *  4. Parse `version` via {@see WindowsVersion::fromString()}. Si null → 422 + log.
 *  5. Génère le XML via {@see WindowsUnattendBuilder::build()}.
 *  6. Insert `MachineBootLog` `action='ipxe_win_unattend'` (best-effort).
 *  7. Log info `ipxe.windows.unattend.generated` (sha256 only, jamais le XML).
 *  8. Response 200 text/plain + headers D10.
 *
 * **Sécurité** : middleware `auth.v1.lan-only` (LAN scolaire) + matching
 * MAC/UUID strict via locator + sanitize XML special chars dans le builder.
 */
class IpxeWindowsUnattendController extends Controller
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly WindowsUnattendBuilder $builder,
    ) {
    }

    public function handle(IpxeWindowsUnattendRequest $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $rawVersion = (string) $request->input('version', 'Win11');
        $bios = (string) $request->input('bios', 'legacy');
        $disk = (int) filter_var($request->input('disk', 0), FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        $perso = (int) filter_var($request->input('perso', 0), FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
        $ip = (string) ($request->ip() ?? '');

        // 1. Résolution Workstation.
        $workstation = $this->locator->locate($mac, $uuid, $product);
        if ($workstation === null) {
            Log::channel($this->channel())->warning('ipxe.windows.unattend.unknown_workstation', [
                'action_type' => 'ipxe.windows.unattend.unknown_workstation',
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            ]);

            throw new NotFoundHttpException('Workstation inconnue pour cet unattend.');
        }

        // 2. Parse version via enum whitelist.
        $version = WindowsVersion::fromString($rawVersion);
        if ($version === null) {
            Log::channel($this->channel())->warning('ipxe.windows.unattend.invalid_version', [
                'action_type' => 'ipxe.windows.unattend.invalid_version',
                'ip' => $ip,
                'raw_version' => $this->sanitizeRaw($rawVersion),
            ]);

            return $this->respondPlain('', 422);
        }

        // 3. Résolution OU AD du poste — fallback config.
        $ou = $this->resolveOu($workstation);

        // 4. Génération XML.
        try {
            $xml = $this->builder->build(
                $workstation,
                $version,
                ['bios' => $bios, 'disk' => $disk, 'perso' => $perso, 'ou' => $ou],
            );
        } catch (UnattendGenerationException $e) {
            Log::channel($this->channel())->error('ipxe.windows.unattend.generation_error', [
                'action_type' => 'ipxe.windows.unattend.generation_error',
                'ip' => $ip,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ]);

            return $this->respondPlain('', 500);
        }

        // 5. Audit + log info.
        $this->persistMachineBootLog($workstation->id ?? null, (string) ($workstation->name ?? 'unknown'), $ip);

        $join = $perso === 0;
        Log::channel($this->channel())->info('ipxe.windows.unattend.generated', [
            'action_type' => 'ipxe.windows.unattend.generated',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            'version' => $version->value,
            'bios' => $bios,
            'join' => $join,
            'xml_sha256' => hash('sha256', $xml),
            'xml_size_bytes' => strlen($xml),
        ]);

        return $this->respondPlain($xml, 200);
    }

    /**
     * Résout l'OU AD du poste — utilisée par `MachineObjectOU` dans le bloc
     * `UnattendedJoin`. Fallback : `config('sambaedu.computers_rdn')`.
     *
     * @param  mixed  $workstation
     */
    private function resolveOu(mixed $workstation): string
    {
        // Source de vérité = `Workstation::getAdOu()` qui lit
        // `physicalRoom->ad_dn` (alimenté par l'enrollment 3-3). Fallback
        // `CN=Computers` si poste non enrôlé ou salle sans `ad_dn` synchro.
        if ($workstation instanceof \App\Models\Workstation) {
            $ou = (string) ($workstation->getAdOu() ?? '');
            if ($ou !== '') {
                return $ou;
            }
        }

        return (string) config('sambaedu.computers_rdn', 'CN=Computers');
    }

    private function persistMachineBootLog(?int $workstationId, string $name, string $ip): void
    {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $workstationId,
                'machine_name' => strtolower($name),
                'action' => 'ipxe_win_unattend',
                'initiated_by' => 'ipxe',
                'success' => true,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.windows.unattend.machine_boot_log_failure', [
                'action_type' => 'ipxe.windows.unattend.machine_boot_log_failure',
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ]);
        }
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
