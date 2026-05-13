<?php

declare(strict_types=1);

namespace App\Gpo\Services;

use App\Gpo\Enums\ApplicationActionError;
use App\Ldap\AdMachineManager;
use App\Models\MachineBootLog;
use App\Services\AppCustomization\Contracts\AppContextWriter;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Port natif de `log_application_scripts()` legacy (`applications.inc.php:775-824`).
 *
 * **Responsabilité unique** : persister une entrée `MachineBootLog` à chaque
 * appel `applications.php` (début/fin d'action) + clean-up APCu au shutdown/logoff.
 *
 * Le legacy faisait en plus :
 *  - `delete_remote_connexion` au shutdown/logoff → reporté dans une story
 *    Guacamole dédiée (cf. tech-debt-gpo.md).
 *  - Détection cycle court (appel double < 10s) → conservé ici, log warning
 *    + retour false (le caller n'écrit pas de script).
 *
 * @legacy-port path="sambaedu/includes/applications.inc.php:775-824"
 * @legacy-port path="sambaedu/includes/logs.inc.php (log_connexion)"
 */
final class ApplicationLoggerService
{
    public function __construct(
        private readonly AppContextWriter $contextWriter,
        private readonly AdMachineManager $adMachines,
    ) {}

    /**
     * Enregistre la trace boot/logon/logoff/shutdown.
     *
     * @param  array<string,mixed>  $info  Contexte issu de ApplicationScriptsGenerator.
     * @param  int  $ret  Code retour script précédent (0 = fin OK, sinon en cours).
     * @return bool  `true` = le caller doit générer le script (cas début d'action).
     *               `false` = pas de script à générer (fin d'action ou double-appel).
     */
    public function logScripts(array $info, int $ret): bool
    {
        if (! is_array($info['machine'] ?? null)) {
            return false;
        }

        $machineCn = (string) ($info['machine']['cn'] ?? '');
        $userCn = (string) ($info['user']['cn'] ?? $machineCn);
        $action = (string) ($info['action'] ?? '');
        $os = (string) ($info['os'] ?? '');
        $id = (string) ($info['id'] ?? '');
        $speed = (int) ($info['speed'] ?? 0);
        $time = (int) ($info['time'] ?? 0);

        if ($action === '') {
            return false;
        }

        // Bitmask iso-legacy (parité `$err[$action]` :787-795).
        try {
            $errorBitmask = ApplicationActionError::fromAction($action)->bitmask();
        } catch (Throwable $e) {
            Log::channel('daily')->warning('[ApplicationLoggerService] unknown action, skipping log', [
                'action' => $action,
                'id' => $id,
            ]);
            return false;
        }

        if ($ret === 0) {
            // Fin d'exécution scripts côté poste : log « connexion terminée »
            // + clean-up APCu pour shutdown/logoff (parité :797-810).
            // Garde `userCn`/`machineCn` non vides : parité legacy ligne 798
            // (évite entrées orphelines `MachineBootLog`, review #17).
            if ($action !== 'wpkg' && $userCn !== '' && $machineCn !== '') {
                $this->persistLog($info, $errorBitmask, success: true);
            }

            // Iso-legacy `:799-800` : `register_machine_hardware` + `set_os` sont
            // appelés au footer (`$ret==0`) — review #1. On délègue ici au lieu
            // de les pré-positionner au 1er appel dans le Generator.
            $uuid = (string) ($info['uuid'] ?? '');
            if ($action !== 'wpkg' && $machineCn !== '' && $uuid !== '') {
                try {
                    $this->adMachines->registerHardware($machineCn, $uuid);
                } catch (Throwable $e) {
                    Log::channel('gpo')->warning('[ApplicationLoggerService] registerHardware failed', [
                        'machine' => $machineCn,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            $machineOsGroups = (array) ($info['machine']['os_groups'] ?? []);
            if ($action !== 'wpkg'
                && $machineCn !== ''
                && in_array($os, ['linux', 'windows'], true)
                && ! in_array($os, $machineOsGroups, true)
            ) {
                try {
                    $this->adMachines->setOs($machineCn, $os);
                } catch (Throwable $e) {
                    Log::channel('gpo')->warning('[ApplicationLoggerService] setOs failed', [
                        'machine' => $machineCn,
                        'os' => $os,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($action === 'shutdown' || $action === 'logoff') {
                $this->contextWriter->forget($id);
            }

            // Le caller ne doit pas re-générer un script (réponse vide ou
            // header-only).
            return false;
        }

        // Détection appel double (entre 1s et 10s du précédent appel).
        if ($time > 0) {
            $delta = time() - $time;
            if ($delta > 1 && $delta < 10) {
                Log::channel('daily')->warning('[ApplicationLoggerService] double call detected', [
                    'machine' => $machineCn,
                    'action' => $action,
                    'delta_s' => $delta,
                ]);
                return false;
            }
        }

        // Début d'exécution scripts côté poste : log « connexion entamée ».
        if ($action !== 'wpkg' && $userCn !== '' && $machineCn !== '') {
            $this->persistLog($info, $errorBitmask, success: false);
        }

        return true;
    }

    /**
     * Persiste un enregistrement `MachineBootLog` (port simplifié de
     * `log_connexion` legacy).
     *
     * @param  array<string,mixed>  $info
     */
    private function persistLog(array $info, int $errorBitmask, bool $success): void
    {
        $machineCn = (string) ($info['machine']['cn'] ?? '');
        $userCn = (string) ($info['user']['cn'] ?? '');
        $action = (string) ($info['action'] ?? '');
        $os = (string) ($info['os'] ?? '');
        $speed = (int) ($info['speed'] ?? 0);

        try {
            MachineBootLog::create([
                'machine_name' => strtolower($machineCn),
                'action' => $action,
                'initiated_by' => $userCn,
                'success' => $success,
                'started_at' => $success ? null : now(),
                'stopped_at' => $success ? now() : null,
                'os' => $os,
                'error_flags' => $errorBitmask,
                'boot_speed' => $speed,
            ]);
        } catch (Throwable $e) {
            // Iso-legacy gracieux : un échec de log MySQL ne doit pas casser
            // le boot du poste.
            Log::channel('daily')->error('[ApplicationLoggerService] MachineBootLog create failed', [
                'machine' => $machineCn,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
