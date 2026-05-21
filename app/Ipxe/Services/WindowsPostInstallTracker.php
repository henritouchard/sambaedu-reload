<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 3.5 — D7 / AC3.3.
 *
 * Hook étapes Windows post-install : reçoit les callbacks `curl
 * /ipxe/windows/action` émis (a) depuis WinPE en début d'install
 * (`etape=winpe&ret=0`) et (b) depuis le 1er logon OOBE (`etape=oobe&ret=0`)
 * via `FirstLogonCommands` injecté dans `unattend.xml`.
 *
 * **Port natif PARTIEL** de `sambaedu/ipxe/Win10/action.php` (736 LOC).
 *
 * **Scope 3.5** : seuls 2 étapes tracées :
 *
 *  - `winpe` : set status `installation WinPE` + progress 5%.
 *  - `oobe`  : set os `windows` + status `installation Windows terminee` +
 *              progress 100% + last_report_at.
 *
 * **HORS-SCOPE 3.5** (déférée 3.7) : flows complets `sysprep`/`nosysprep`/
 * `join`/`renomme`/`post`/`wpkg` qui dépendent de
 * `IpxeProgrammedActionResolver` (GLM `actions[]` LDAP non porté SE5).
 *
 * **Idempotence** : l'update `os`/`status` est idempotent (UPDATE WHERE),
 * mais l'insert `MachineBootLog` ajoute une ligne par appel — acceptable
 * Phase 2 (audit traçabilité).
 */
final class WindowsPostInstallTracker
{
    /**
     * Status string lorsque WinPE démarre (étape `winpe`). ASCII strict — pas
     * d'accent fr (`Workstation::status` peut être réinjecté dans des
     * cmdlines iPXE / UI mixed-charset).
     */
    public const STATUS_WINPE = 'installation WinPE';

    /**
     * Status string lorsque l'install complète Windows aboutit (étape `oobe`).
     */
    public const STATUS_OOBE_COMPLETE = 'installation Windows terminee';

    /**
     * Channel Monolog dédié (iso 3.1 D7).
     */
    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }

    /**
     * Enregistre le début d'install WinPE pour un poste donné.
     *
     * **Workflow** (parité legacy `action.php:411-491` étape `winpe`) :
     *  1. Set `$workstation->status = 'installation WinPE'`.
     *  2. Save Workstation.
     *  3. Insert MachineBootLog `action='ipxe_win_install'`.
     *  4. Log info `ipxe.windows.action.winpe_start`.
     *
     * @param  Workstation  $workstation  Poste résolu via {@see WorkstationLocator}.
     * @param  string  $name              Nom du poste rapporté par WinPE
     *                                    (déjà sanitizé par le controller).
     * @param  string  $ip                IP du poste appelant (pour log audit).
     */
    public function recordWinpeStart(
        Workstation $workstation,
        string $name = '',
        string $ip = '',
    ): void {
        // Parité 3.4 post-review #M3 (Linux) : préserver `status='protected'`.
        // Le marqueur `protected` sert d'anti-suppression DB lors des resync
        // AD et ne doit pas être écrasé silencieusement par un boot WinPE.
        $wasProtected = $workstation->status === 'protected';

        $workstation->status = self::STATUS_WINPE;

        if ($wasProtected) {
            $workstation->status = 'protected';
        }

        $workstation->save();

        if ($wasProtected) {
            Log::channel($this->channel())->info('ipxe.windows.action.protected_preserved', [
                'action_type' => 'ipxe.windows.action.protected_preserved',
                'workstation_id' => $workstation->id ?? null,
                'mac' => (string) ($workstation->mac ?? ''),
                'step' => 'winpe',
            ]);
        }

        $this->persistMachineBootLog($workstation, 'ipxe_win_install', true, $ip);

        Log::channel($this->channel())->info('ipxe.windows.action.winpe_start', [
            'action_type' => 'ipxe.windows.action.winpe_start',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            'reported_name_prefix' => $name !== '' ? substr($name, 0, 6) : '',
        ]);
    }

    /**
     * Enregistre la fin d'install Windows (1er logon OOBE) pour un poste.
     *
     * **Workflow** (parité legacy `action.php:720-730` default branch) :
     *  1. Set `$workstation->os = 'windows'`.
     *  2. Set `$workstation->status = 'installation Windows terminee'`.
     *  3. Set `$workstation->last_report_at = now()`.
     *  4. Save Workstation.
     *  5. Insert MachineBootLog `action='ipxe_win_report'`.
     *  6. Log info `ipxe.windows.action.oobe_complete`.
     *
     * **Idempotent** : un poste déjà en `os='windows'` est mis à jour sans
     * incident (last_report_at refreshe la timestamp).
     */
    public function recordOobeComplete(
        Workstation $workstation,
        string $name = '',
        string $ip = '',
    ): void {
        // Parité 3.4 post-review #M3 (Linux) : préserver `status='protected'`
        // post-install — l'install a quand même eu lieu (os/last_report_at à
        // jour) mais on ne perd pas la protection anti-suppression DB.
        $wasProtected = $workstation->status === 'protected';

        $workstation->os = 'windows';
        $workstation->status = self::STATUS_OOBE_COMPLETE;
        $workstation->last_report_at = Carbon::now();

        if ($wasProtected) {
            $workstation->status = 'protected';
        }

        $workstation->save();

        if ($wasProtected) {
            Log::channel($this->channel())->info('ipxe.windows.action.protected_preserved', [
                'action_type' => 'ipxe.windows.action.protected_preserved',
                'workstation_id' => $workstation->id ?? null,
                'mac' => (string) ($workstation->mac ?? ''),
                'step' => 'oobe',
            ]);
        }

        $this->persistMachineBootLog($workstation, 'ipxe_win_report', true, $ip);

        Log::channel($this->channel())->info('ipxe.windows.action.oobe_complete', [
            'action_type' => 'ipxe.windows.action.oobe_complete',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
            'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            'reported_name_prefix' => $name !== '' ? substr($name, 0, 6) : '',
        ]);
    }

    /**
     * Enregistre la génération du install.bat WinPE (acknowledgement du début
     * d'install par le serveur lui-même, avant le hook winpe du poste).
     *
     * **Workflow** : insert MachineBootLog `action='ipxe_win_install'` (audit).
     * **Pas** d'update `status` (le poste posera son ack winpe = recordWinpeStart
     * peu après — éviter double-écriture).
     */
    public function recordInstallBatGenerated(Workstation $workstation, string $ip = ''): void
    {
        $this->persistMachineBootLog($workstation, 'ipxe_win_install', true, $ip);
    }

    /**
     * Émet le log warning `ipxe.windows.action.unknown_workstation` quand le
     * controller a appelé sans pouvoir résoudre la Workstation (poste non
     * enregistré qui rapporte un install — cas edge rare).
     *
     * Pas d'update DB, pas d'insert MachineBootLog (D4 — silent).
     */
    public function recordUnknown(string $uuid, string $name, string $ip): void
    {
        Log::channel($this->channel())->warning('ipxe.windows.action.unknown_workstation', [
            'action_type' => 'ipxe.windows.action.unknown_workstation',
            'ip' => $ip,
            'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
            'reported_name_prefix' => $name !== '' ? substr($name, 0, 6) : '',
        ]);
    }

    /**
     * Insert `MachineBootLog` (best-effort). Iso 3.4 `LinuxPostInstallTracker`.
     */
    private function persistMachineBootLog(
        Workstation $workstation,
        string $action,
        bool $success,
        string $ip,
    ): void {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $workstation->id ?? null,
                'machine_name' => strtolower((string) ($workstation->name ?? 'unknown')),
                'action' => $action,
                'initiated_by' => 'ipxe',
                'success' => $success,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.windows.action.machine_boot_log_failure', [
                'action_type' => 'ipxe.windows.action.machine_boot_log_failure',
                'endpoint_action' => $action,
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ]);
        }
    }
}
