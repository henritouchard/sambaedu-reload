<?php

declare(strict_types=1);

namespace App\Services\Parc;

use App\Config\SambaEduConfig;
use App\Models\MachineBootLog;
use App\Models\Workstation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Service natif pour les actions d'alimentation des machines
 *
 * Remplace les fonctions legacy de parcs.inc.php (start_machine_local, fping, get_vlan)
 * par des implémentations Laravel natives utilisant Illuminate\Support\Facades\Process.
 */
class MachinePowerService
{
    public function __construct(
        private SambaEduConfig $configService
    ) {
    }

    /**
     * Détecte si une machine est en ligne et son OS
     *
     * Reproduit le comportement de fping() legacy :
     * - Port 22 ouvert → 'linux'
     * - Port 445 ouvert → 'windows'
     * - Aucun port → false (éteint)
     *
     * @param string $ip Adresse IP ou hostname
     * @param float $timeout Timeout en secondes (par défaut 0.2s)
     * @return string|false 'windows', 'linux', ou false
     */
    public function ping(string $ip, float $timeout = 0.2): string|false
    {
        // Boucle avec timeout croissant comme le legacy fping()
        for ($t = 0.002; $t <= $timeout; $t *= 4) {
            if ($this->checkPort($ip, 22, $t)) {
                return 'linux';
            }
            if ($this->checkPort($ip, 445, $t)) {
                return 'windows';
            }
        }

        return false;
    }

    /**
     * Envoie un paquet Wake-on-LAN à une machine
     *
     * @param string $macAddress Adresse MAC (format xx:xx:xx:xx:xx:xx)
     * @param string $ip Adresse IP de la machine (pour calculer le broadcast)
     * @param string|null $machineName Nom de la machine (pour le logging)
     * @return array{success: bool, code: int, message: string}
     */
    public function wakeOnLan(string $macAddress, string $ip, ?string $machineName = null): array
    {
        if (empty($macAddress)) {
            return [
                'success' => false,
                'code' => 203,
                'message' => "Pas d'adresse MAC enregistrée pour cette machine",
            ];
        }

        if (!$this->isValidMacAddress($macAddress)) {
            return [
                'success' => false,
                'code' => 500,
                'message' => "Adresse MAC invalide: {$macAddress}",
            ];
        }

        $messages = [];
        $broadcast = $this->resolveBroadcast($ip);
        $safeMac = escapeshellarg($macAddress);

        if ($broadcast !== false) {
            $safeBroadcast = escapeshellarg($broadcast);
            $result = Process::run("/usr/bin/wakeonlan -i {$safeBroadcast} {$safeMac}");
            $messages[] = trim($result->output());
        }

        // Envoi supplémentaire sur wol_broadcast si configuré (fiabilité cross-VLAN)
        $wolBroadcast = $this->configService->get('wol_broadcast');
        if ($wolBroadcast) {
            $safeWolBroadcast = escapeshellarg($wolBroadcast);
            $result = Process::run("/usr/bin/wakeonlan -i {$safeWolBroadcast} {$safeMac}");
            $messages[] = trim($result->output());
        }

        if (empty($messages)) {
            if ($machineName) {
                $this->logAction($machineName, 'wake', false);
            }
            return [
                'success' => false,
                'code' => 203,
                'message' => "Impossible de déterminer l'adresse broadcast pour {$ip}",
            ];
        }

        if ($machineName) {
            $this->logAction($machineName, 'wake', true);
        }

        return [
            'success' => true,
            'code' => 202,
            'message' => 'WOL envoyé : ' . implode(' | ', array_filter($messages)),
        ];
    }

    /**
     * Éteint une machine (Windows via net rpc, Linux via SSH)
     *
     * @param string $machineName Nom de la machine
     * @param string $ip Adresse IP
     * @param bool $force Forcer même si un utilisateur est connecté
     * @return array{success: bool, code: int, message: string}
     */
    // NOTE: $force est prévu pour conditionner l'arrêt à l'absence d'utilisateur connecté (AC3).
    // Le legacy ne l'implémentait pas non plus. À traiter dans une story future si nécessaire.
    public function shutdown(string $machineName, string $ip, bool $force = false): array
    {
        $os = $this->ping($ip);

        if ($os === false) {
            $this->logAction($machineName, 'shutdown', false, null);
            return [
                'success' => false,
                'code' => 203,
                'message' => "{$machineName} est déjà éteinte — aucune action effectuée",
            ];
        }

        if ($os === 'windows') {
            $safeName = escapeshellarg($machineName);
            $command = "/usr/bin/net rpc shutdown --use-kerberos=required -t 30 -f -C \"Arrêt demandé par SambaEdu\" -S {$safeName} 2>&1";
            $result = Process::run($command);

            if ($result->exitCode() !== 0) {
                Log::warning("Échec shutdown Windows {$machineName}", ['output' => $result->output()]);
                $this->logAction($machineName, 'shutdown', false, $os);
                return [
                    'success' => false,
                    'code' => 203,
                    'message' => "Arrêt impossible pour {$machineName}: " . trim($result->output()),
                ];
            }

            $this->logAction($machineName, 'shutdown', true, $os);
            return [
                'success' => true,
                'code' => 201,
                'message' => "Arrêt de {$machineName} (Windows) : " . trim($result->output()),
            ];
        }

        // Linux
        $safeName = escapeshellarg($machineName);
        $command = "/usr/bin/ssh -i /etc/sambaedu/id_rsa -o StrictHostKeyChecking=no -o ConnectTimeout=1 root@{$safeName} shutdown -h now 2>&1";
        $result = Process::run($command);

        if ($result->exitCode() !== 0) {
            Log::warning("Échec shutdown Linux {$machineName}", ['output' => $result->output()]);
            $this->logAction($machineName, 'shutdown', false, $os);
            return [
                'success' => false,
                'code' => 203,
                'message' => "Arrêt impossible pour {$machineName}: " . trim($result->output()),
            ];
        }

        $this->logAction($machineName, 'shutdown', true, $os);
        return [
            'success' => true,
            'code' => 201,
            'message' => "Arrêt de {$machineName} (Linux) : OK",
        ];
    }

    /**
     * Redémarre une machine (Windows via net rpc, Linux via SSH)
     * Si la machine est éteinte, fallback automatique vers WOL
     *
     * @param string $machineName Nom de la machine
     * @param string $ip Adresse IP
     * @param string $macAddress Adresse MAC (pour fallback WOL)
     * @param bool $force Forcer le reboot
     * @return array{success: bool, code: int, message: string}
     */
    // NOTE: $force — même constat que shutdown(), non implémenté (fidèle au legacy).
    // Le fallback WOL sur échec de reboot reproduit le comportement legacy (start_machine_local).
    public function reboot(string $machineName, string $ip, string $macAddress = '', bool $force = false): array
    {
        $os = $this->ping($ip);

        // Machine éteinte → fallback WOL
        if ($os === false) {
            if (!empty($macAddress)) {
                $wolResult = $this->wakeOnLan($macAddress, $ip, $machineName);
                $this->logAction($machineName, 'reboot', $wolResult['success'], null);
                return [
                    'success' => $wolResult['success'],
                    'code' => $wolResult['code'],
                    'message' => "{$machineName} est éteinte, tentative WOL : " . $wolResult['message'],
                ];
            }

            $this->logAction($machineName, 'reboot', false, null);
            return [
                'success' => false,
                'code' => 203,
                'message' => "{$machineName} est éteinte et n'a pas d'adresse MAC pour le WOL",
            ];
        }

        if ($os === 'windows') {
            $safeName = escapeshellarg($machineName);
            $command = "/usr/bin/net rpc shutdown --use-kerberos=required -t 2 -f -r -C \"Reboot demandé par SambaEdu\" -S {$safeName} 2>&1";
            $result = Process::run($command);

            if ($result->exitCode() !== 0) {
                Log::warning("Échec reboot Windows {$machineName}", ['output' => $result->output()]);
                $this->logAction($machineName, 'reboot', false, $os);
                // Fallback WOL en cas d'échec reboot
                if (!empty($macAddress)) {
                    $wolResult = $this->wakeOnLan($macAddress, $ip);
                    return [
                        'success' => false,
                        'code' => 203,
                        'message' => "Reboot impossible pour {$machineName}, tentative WOL : " . $wolResult['message'],
                    ];
                }
                return [
                    'success' => false,
                    'code' => 203,
                    'message' => "Reboot impossible pour {$machineName}: " . trim($result->output()),
                ];
            }

            $this->logAction($machineName, 'reboot', true, $os);
            return [
                'success' => true,
                'code' => 201,
                'message' => "Reboot de {$machineName} (Windows) : " . trim($result->output()),
            ];
        }

        // Linux
        $safeName = escapeshellarg($machineName);
        $command = "/usr/bin/ssh -i /etc/sambaedu/id_rsa -o StrictHostKeyChecking=no -o ConnectTimeout=1 root@{$safeName} shutdown -r now 2>&1";
        $result = Process::run($command);

        if ($result->exitCode() !== 0) {
            Log::warning("Échec reboot Linux {$machineName}", ['output' => $result->output()]);
            $this->logAction($machineName, 'reboot', false, $os);
            if (!empty($macAddress)) {
                $wolResult = $this->wakeOnLan($macAddress, $ip);
                return [
                    'success' => false,
                    'code' => 203,
                    'message' => "Reboot impossible pour {$machineName}, tentative WOL : " . $wolResult['message'],
                ];
            }
            return [
                'success' => false,
                'code' => 203,
                'message' => "Reboot impossible pour {$machineName}: " . trim($result->output()),
            ];
        }

        $this->logAction($machineName, 'reboot', true, $os);
        return [
            'success' => true,
            'code' => 201,
            'message' => "Reboot de {$machineName} (Linux) : OK",
        ];
    }

    /**
     * Résout l'adresse broadcast pour une IP donnée
     *
     * Stratégie :
     * 1. Cherche dans la config DHCP legacy (paramètres dhcp_reseau_*, dhcp_masque_*)
     * 2. Sinon, calcule depuis l'IP et le masque réseau de la config
     * 3. En dernier recours, broadcast /24 par défaut
     *
     * @param string $ip Adresse IP de la machine
     * @return string|false Adresse broadcast ou false
     */
    public function resolveBroadcast(string $ip): string|false
    {
        if (empty($ip)) {
            return false;
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        // Stratégie 1 : Résolution VLAN depuis la config DHCP legacy
        $broadcast = $this->resolveVlanBroadcast($ipLong);
        if ($broadcast !== false) {
            return $broadcast;
        }

        // Stratégie 2 : Calcul depuis le masque réseau de la config
        $network = $this->configService->network();
        if (!empty($network->mask)) {
            $maskLong = ip2long($network->mask);
            if ($maskLong !== false) {
                return long2ip(($ipLong | ~$maskLong) & 0xFFFFFFFF);
            }
        }

        // Stratégie 3 : Broadcast /24 par défaut
        return long2ip(($ipLong | 0x000000FF) & 0xFFFFFFFF);
    }

    /**
     * Logue une action power dans machine_boot_logs
     */
    private function logAction(string $machineName, string $action, bool $success, ?string $os = null): void
    {
        try {
            $workstation = Workstation::where('name', strtolower($machineName))->first();
            $initiatedBy = auth()->user()?->name ?? session('login') ?? 'system';

            $data = [
                'workstation_id' => $workstation?->id,
                'machine_name' => strtolower($machineName),
                'action' => $action,
                'initiated_by' => $initiatedBy,
                'success' => $success,
                'os' => $os,
            ];

            if ($action === 'wake') {
                $data['started_at'] = now();
            }

            if (in_array($action, ['shutdown', 'reboot'], true) && $success) {
                // Fermer un éventuel log WOL ouvert
                $openLog = MachineBootLog::where('machine_name', strtolower($machineName))
                    ->whereNotNull('started_at')
                    ->whereNull('stopped_at')
                    ->latest('started_at')
                    ->first();

                if ($openLog) {
                    $openLog->update(['stopped_at' => now()]);
                }

                $data['stopped_at'] = now();
            }

            MachineBootLog::create($data);
        } catch (\Exception $e) {
            Log::warning("Impossible de logger l'action {$action} pour {$machineName}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Vérifie si un port est ouvert sur une IP (fsockopen)
     */
    private function checkPort(string $ip, int $port, float $timeout): bool
    {
        $fp = @fsockopen($ip, $port, $errno, $errstr, $timeout);
        if ($fp === false) {
            return false;
        }
        fclose($fp);
        return true;
    }

    /**
     * Résout le broadcast VLAN depuis les paramètres DHCP de la config legacy
     *
     * Reproduit get_vlan() + get_network() du legacy dhcpd.inc.php
     */
    private function resolveVlanBroadcast(int $ipLong): string|false
    {
        $rawConfig = $this->configService->all();

        // Extraire les paramètres DHCP (dhcp_reseau_0, dhcp_masque_0, etc.)
        $reseaux = [];
        $masques = [];

        foreach ($rawConfig as $key => $value) {
            if (preg_match('/^dhcp_reseau_(\d+)$/', $key, $m)) {
                $parsed = ip2long($value);
                if ($parsed !== false) {
                    $reseaux[(int) $m[1]] = $parsed;
                }
            } elseif ($key === 'dhcp_reseau') {
                $parsed = ip2long($value);
                if ($parsed !== false) {
                    $reseaux[0] = $parsed;
                }
            }
            if (preg_match('/^dhcp_masque_(\d+)$/', $key, $m)) {
                $parsed = ip2long($value);
                if ($parsed !== false) {
                    $masques[(int) $m[1]] = $parsed;
                }
            } elseif ($key === 'dhcp_masque') {
                $parsed = ip2long($value);
                if ($parsed !== false) {
                    $masques[0] = $parsed;
                }
            }
        }

        if (empty($reseaux) || empty($masques)) {
            return false;
        }

        // Chercher le VLAN qui contient l'IP
        foreach ($reseaux as $idx => $reseauLong) {
            if (!isset($masques[$idx])) {
                continue;
            }
            $masqueLong = $masques[$idx];
            $broadcastLong = ($reseauLong | ~$masqueLong) & 0xFFFFFFFF;

            // L'IP appartient à ce réseau si (IP & masque) == réseau
            if (($ipLong & $masqueLong) === ($reseauLong & $masqueLong)) {
                return long2ip($broadcastLong);
            }
        }

        return false;
    }

    /**
     * Valide le format d'une adresse MAC
     */
    private function isValidMacAddress(string $mac): bool
    {
        return (bool) preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $mac);
    }
}
