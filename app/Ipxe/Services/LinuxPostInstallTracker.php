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
 *   3. Pose un marqueur one-shot dans `Workstation::programmed_action`
 *      (`{type: linux_install_done|linux_install_failed, ret}`) consommé au
 *      prochain boot iPXE par {@see \App\Ipxe\Services\IpxeService::handleBoot()}
 *      pour afficher l'écran « installation terminée » + compte à rebours.
 *      **NE touche PAS `status`** : domaine fermé `varchar(20)`
 *      (`active|inactive|protected`) — y écrire la phrase d'issue provoquait
 *      un SQLSTATE 22001 (value too long) → 500 sur le callback.
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
     * Type de `programmed_action` posé lorsque l'install se termine avec
     * succès (`ret=0`). Marqueur one-shot consommé par
     * {@see \App\Ipxe\Services\IpxeService::handleBoot()} au prochain boot
     * iPXE pour afficher l'écran « installation terminée » + compte à rebours.
     */
    public const ACTION_INSTALL_DONE = 'linux_install_done';

    /**
     * Type de `programmed_action` posé lorsque l'install échoue (`ret != 0`).
     * Le code `ret` est conservé dans la clé `ret` du JSON.
     */
    public const ACTION_INSTALL_FAILED = 'linux_install_failed';

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
        // Fix install-debian — on NE touche PLUS `status`.
        //
        // `workstations.status` est un `varchar(20)` à domaine fermé
        // (`active|inactive|protected` — cf. scopes du modèle). Y écrire une
        // phrase d'issue d'install (`'installation Linux terminee'` = 27 c.)
        // provoquait un SQLSTATE[22001] « value too long » → l'UPDATE entier
        // échouait → HTTP 500 sur le callback `/ipxe/linux/action` (le poste
        // n'était alors jamais marqué). L'issue d'install est désormais tracée
        // via `os` + `last_report_at` + `MachineBootLog` (persistMachineBootLog
        // ci-dessous). Comme on ne réécrit plus `status`, la sémantique
        // « préserver `protected` post-install » (décision Henri #M3 : le
        // legacy `flag_poste=1` protège de la suppression mais ne bloque pas
        // la réinstall) est respectée nativement, sans hack de restauration.
        $workstation->os = 'linux';
        $workstation->last_report_at = Carbon::now();

        // Marqueur one-shot consommé au prochain boot iPXE
        // (IpxeService::handleBoot) : affiche l'écran « installation terminée »
        // + compte à rebours puis boot disque local, et efface le marqueur.
        $workstation->programmed_action = [
            'type' => $ret === 0 ? self::ACTION_INSTALL_DONE : self::ACTION_INSTALL_FAILED,
            'ret' => $ret,
        ];

        $workstation->save();

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
