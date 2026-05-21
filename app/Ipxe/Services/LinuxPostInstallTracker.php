<?php

declare(strict_types=1);

namespace App\Ipxe\Services;

use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Story 3.4 — D6 / AC3.2.
 *
 * Hook fin d'install Linux : reçoit le callback `curl /ipxe/linux/action`
 * émis par debian-installer après le `preseed/late_command` (parité legacy
 * `preseed.cfg:83`) et met à jour le poste correspondant.
 *
 * **Port natif** de `sambaedu/ipxe/linux/action.php` (43 LOC) simplifié au
 * scope 3.4 (sans `set_progress` / `set_action` legacy — voir story 3.4 §
 * HORS-SCOPE granularité fine déférée Epic 17.4).
 *
 * **Workflow** :
 *
 *   1. Reçoit `(Workstation, ret:int, name:string)` (le caller a déjà résolu
 *      la Workstation via `WorkstationLocator::locate($uuid=...)`).
 *   2. Met à jour `Workstation::os = 'linux'` (parité legacy
 *      `preseed.php:84` `set_os($config, $machine['cn'], "linux")`).
 *   3. Met à jour `Workstation::status` selon `ret` :
 *      - `ret = 0` → `'installation Linux terminee'` (ASCII strict — pas
 *        d'accent fr car `Workstation::status` peut être réinjecté dans
 *        des cmdlines iPXE / UI mixed-charset).
 *      - `ret != 0` → `'installation Linux echouee (ret=<N>)'`.
 *   4. `last_report_at = now()` (parité audit).
 *   5. Insert `MachineBootLog` `action='ipxe_linux_report'`.
 *   6. Log info `ipxe.linux.action.success` ou warning
 *      `ipxe.linux.action.failure`.
 *
 * **Idempotence** : l'update `os`/`status` est idempotent (UPDATE WHERE),
 * mais l'insert `MachineBootLog` ajoute une ligne par appel — acceptable
 * Phase 2 (audit traçabilité).
 *
 * **Sécurité** :
 *  - Best-effort DB : un échec d'insert log ne bloque pas la réponse HTTP
 *    (le poste qui reboot ne doit pas être laissé pendant — le hook est
 *    informatif).
 *  - Pas de validation de signature : un attaquant LAN qui connaît un
 *    (MAC, UUID) valide peut spoofer le hook → impact limité (juste
 *    Workstation::os/status). Log warning pour audit.
 */
final class LinuxPostInstallTracker
{
    /**
     * Status string lorsque l'install se termine avec succès (`ret=0`).
     */
    public const STATUS_SUCCESS = 'installation Linux terminee';

    /**
     * Format de status lorsque l'install échoue (`ret != 0`). Le placeholder
     * `%d` est remplacé par la valeur `$ret` reçue.
     */
    public const STATUS_FAILURE_FORMAT = 'installation Linux echouee (ret=%d)';

    /**
     * Channel Monolog dédié (iso 3.1 D7).
     */
    private function channel(): string
    {
        return (string) config('ipxe.log.channel', 'ipxe');
    }

    /**
     * Enregistre la fin d'install Linux pour un poste donné.
     *
     * @param  Workstation  $workstation  Poste résolu via {@see WorkstationLocator}.
     * @param  int  $ret                  Code retour du `late_command`
     *                                    (`0` = success, sinon failure).
     * @param  string  $name              Nom du poste rapporté par
     *                                    debian-installer (déjà sanitizé par
     *                                    le controller).
     * @param  string  $ip                IP du poste appelant (pour log audit).
     */
    public function record(
        Workstation $workstation,
        int $ret,
        string $name,
        string $ip = '',
    ): void {
        // Post-review #M3 — décision Henri : préserver `status='protected'`
        // post-install. Le legacy `flag_poste=1` ne bloque JAMAIS la
        // réinstall iPXE (vérifié) ; il sert uniquement de protection
        // anti-suppression DB lors des resync AD. On respecte cette
        // sémantique en restaurant le status `protected` après l'install
        // (au lieu de l'écraser silencieusement par
        // `installation Linux terminee`).
        $wasProtected = $workstation->status === 'protected';

        $workstation->os = 'linux';
        $workstation->status = $ret === 0
            ? self::STATUS_SUCCESS
            : sprintf(self::STATUS_FAILURE_FORMAT, $ret);
        $workstation->last_report_at = Carbon::now();

        if ($wasProtected) {
            // Restore le marqueur de protection — l'install Linux a quand
            // même eu lieu (os/last_report_at à jour) mais on ne perd pas
            // la protection anti-suppression.
            $workstation->status = 'protected';
        }

        $workstation->save();

        if ($wasProtected) {
            Log::channel($this->channel())->info('ipxe.linux.action.protected_preserved', [
                'action_type' => 'ipxe.linux.action.protected_preserved',
                'workstation_id' => $workstation->id ?? null,
                'mac' => (string) ($workstation->mac ?? ''),
                'ret' => $ret,
            ]);
        }

        $this->persistMachineBootLog($workstation, $ret, $ip);

        if ($ret === 0) {
            Log::channel($this->channel())->info('ipxe.linux.action.success', [
                'action_type' => 'ipxe.linux.action.success',
                'ip' => $ip,
                'workstation_id' => $workstation->id ?? null,
                'workstation_name_prefix' => substr((string) ($workstation->name ?? ''), 0, 6),
            ]);

            return;
        }

        Log::channel($this->channel())->warning('ipxe.linux.action.failure', [
            'action_type' => 'ipxe.linux.action.failure',
            'ip' => $ip,
            'workstation_id' => $workstation->id ?? null,
            // Tronque à 16 chars iso D8.
            'name' => substr($name, 0, 16),
            'ret' => $ret,
        ]);
    }

    /**
     * Émet le log warning `ipxe.linux.action.unknown_workstation` quand le
     * controller a appelé sans pouvoir résoudre la Workstation (poste non
     * enregistré qui rapporte un install — cas edge rare).
     *
     * Pas d'update DB, pas d'insert MachineBootLog (D4 — silent).
     */
    public function recordUnknown(string $mac, string $uuid, string $ip): void
    {
        Log::channel($this->channel())->warning('ipxe.linux.action.unknown_workstation', [
            'action_type' => 'ipxe.linux.action.unknown_workstation',
            'ip' => $ip,
            'mac_prefix' => $mac !== '' ? substr($mac, 0, 6) : '',
            'uuid_prefix' => $uuid !== '' ? substr($uuid, 0, 8) : '',
        ]);
    }

    /**
     * Insert `MachineBootLog` avec `action='ipxe_linux_report'` (17 chars,
     * fit dans varchar(20) iso D12). Best-effort.
     */
    private function persistMachineBootLog(
        Workstation $workstation,
        int $ret,
        string $ip,
    ): void {
        try {
            $now = Carbon::now();
            MachineBootLog::query()->create([
                'workstation_id' => $workstation->id ?? null,
                'machine_name' => strtolower((string) ($workstation->name ?? 'unknown')),
                'action' => 'ipxe_linux_report',
                'initiated_by' => 'ipxe',
                'success' => $ret === 0,
                'started_at' => $now,
                'stopped_at' => $now,
            ]);
        } catch (Throwable $e) {
            Log::channel($this->channel())->warning('ipxe.linux.action.machine_boot_log_failure', [
                'action_type' => 'ipxe.linux.action.machine_boot_log_failure',
                'exception_class' => $e::class,
                'message' => substr($e->getMessage(), 0, 200),
                'ip' => $ip,
            ]);
        }
    }
}
