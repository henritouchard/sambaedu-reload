<?php

declare(strict_types=1);

namespace App\Ipxe\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Ipxe\Enums\LinuxDesktopVariant;
use App\Ipxe\Enums\LinuxDistribution;
use App\Ipxe\Exceptions\PreseedGenerationException;
use App\Ipxe\Http\Requests\IpxeLinuxPreseedRequest;
use App\Ipxe\Services\LinuxPreseedService;
use App\Ipxe\Services\WorkstationLocator;
use App\Models\MachineBootLog;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Story 3.4 — AC5.2 / D2.
 *
 * Controller du endpoint `GET|POST /ipxe/linux/preseed` (port natif
 * `sambaedu/ipxe/linux/preseed.php`).
 *
 * **Flow** :
 *  1. Valide les inputs via {@see IpxeLinuxPreseedRequest}.
 *  2. Résout la Workstation via {@see WorkstationLocator}.
 *  3. Si null → 404 + log warning (D4 — diverge legacy qui renvoyait 200
 *     vide ; un 404 explicite est plus debug-friendly).
 *  4. Parse `os` via {@see LinuxDistribution::fromString()}. Si null → 422 + log.
 *  5. Parse `type` via {@see LinuxDesktopVariant::fromString()}. Si null → 422 + log.
 *  6. Génère le preseed via {@see LinuxPreseedService::generate()}.
 *  7. Insert `MachineBootLog` `action='ipxe_linux_preseed'`.
 *  8. Response 200 text/plain + headers D10.
 *
 * **Sécurité** : middleware `auth.v1.lan-only` (LAN scolaire) + matching
 * MAC/UUID strict via locator.
 */
class IpxeLinuxPreseedController extends Controller
{
    public function __construct(
        private readonly WorkstationLocator $locator,
        private readonly LinuxPreseedService $preseedService,
    ) {
    }

    public function handle(IpxeLinuxPreseedRequest $request): Response
    {
        $mac = (string) $request->input('mac', '');
        $uuid = (string) $request->input('uuid', '');
        $product = (string) $request->input('product', '');
        $rawOs = (string) $request->input('os', '');
        $rawType = (string) $request->input('type', 'base');
        $mask = (string) $request->input('mask', '');
        $gateway = (string) $request->input('gateway', '');
        $perso = filter_var($request->input('perso', false), FILTER_VALIDATE_BOOL);
        $ip = (string) ($request->ip() ?? '');

        // 1. Résolution Workstation.
        $workstation = $this->locator->locate($mac, $uuid, $product);
        if ($workstation === null) {
            Log::channel($this->channel())->warning('ipxe.linux.preseed.unknown_workstation', [
                'action_type' => 'ipxe.linux.preseed.unknown_workstation',
                'ip' => $ip,
                'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
                'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            ]);

            throw new NotFoundHttpException('Workstation inconnue pour ce preseed.');
        }

        // 2. Parse distribution via enum whitelist.
        $distribution = LinuxDistribution::fromString($rawOs);
        if ($distribution === null) {
            Log::channel($this->channel())->warning('ipxe.linux.preseed.invalid_distribution', [
                'action_type' => 'ipxe.linux.preseed.invalid_distribution',
                'ip' => $ip,
                'raw_distribution' => $this->sanitizeRaw($rawOs),
            ]);

            return $this->respondPlain('', 422);
        }

        // 3. Parse variant via enum whitelist. Nird = Base par défaut.
        // Le legacy passe parfois type=nird (qui n'est pas un desktop variant
        // mais un alias dist). On mappe vers Base si le type ne matche pas.
        $variant = LinuxDesktopVariant::fromString($rawType);
        if ($variant === null) {
            // Pour `type=nird` ou autres aliases, on fallback sur Base.
            if (in_array(strtolower(trim($rawType)), ['nird', ''], true)) {
                $variant = LinuxDesktopVariant::Base;
            } else {
                Log::channel($this->channel())->warning('ipxe.linux.preseed.invalid_variant', [
                    'action_type' => 'ipxe.linux.preseed.invalid_variant',
                    'ip' => $ip,
                    'raw_variant' => $this->sanitizeRaw($rawType),
                ]);

                return $this->respondPlain('', 422);
            }
        }

        // 4. Génération preseed.
        try {
            $preseed = $this->preseedService->generate(
                $workstation,
                $distribution,
                $variant,
                [
                    'mask' => $mask,
                    'gateway' => $gateway,
                    'perso' => $perso,
                    'ip' => $ip,
                ],
            );
        } catch (PreseedGenerationException $e) {
            Log::channel($this->channel())->error('ipxe.linux.preseed.generation_error', [
                'action_type' => 'ipxe.linux.preseed.generation_error',
                'ip' => $ip,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
            ]);

            return $this->respondPlain('', 500);
        }

        // 5. Audit MachineBootLog (best-effort).
        $this->persistMachineBootLog($workstation->id ?? null, (string) ($workstation->name ?? 'unknown'), $ip);

        return $this->respondPlain($preseed, 200);
    }

    /**
     * Insert MachineBootLog `action='ipxe_linux_preseed'` (best-effort, ne
     * bloque pas la réponse en cas d'échec DB).
     */
    private function persistMachineBootLog(?int $workstationId, string $name, string $ip): void
    {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $workstationId,
                'machine_name' => strtolower($name),
                'action' => 'ipxe_linux_preseed',
                'initiated_by' => 'ipxe',
                'success' => true,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.linux.preseed.machine_boot_log_failure', [
                'action_type' => 'ipxe.linux.preseed.machine_boot_log_failure',
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ]);
        }
    }

    /**
     * Sanitize une input externe brute pour le logging warning
     * (tronque 32 chars + replace non-ASCII par `?`). Iso 3.2
     * `IpxeService::sanitizeActionRequested()`.
     */
    private function sanitizeRaw(string $raw): string
    {
        $truncated = substr($raw, 0, 32);
        $clean = preg_replace('/[^\x20-\x7E]/', '?', $truncated);

        return $clean ?? $truncated;
    }

    /**
     * Construit une réponse text/plain avec les headers D10 iso 3.1.
     */
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
