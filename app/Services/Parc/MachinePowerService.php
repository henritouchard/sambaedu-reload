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
    ) {}

    /**
     * Détecte si une machine est en ligne et son OS
     *
     * Reproduit le comportement de fping() legacy :
     * - Port 22 ouvert → 'linux'
     * - Port 445 ouvert → 'windows'
     * - Aucun port → false (éteint)
     *
     * @param  string  $ip  Adresse IP ou hostname
     * @param  float  $timeout  Timeout en secondes (par défaut 0.2s)
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
     * @param  string  $macAddress  Adresse MAC (format xx:xx:xx:xx:xx:xx)
     * @param  string  $ip  Adresse IP de la machine (pour calculer le broadcast)
     * @param  string|null  $machineName  Nom de la machine (pour le logging)
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

        if (! $this->isValidMacAddress($macAddress)) {
            return [
                'success' => false,
                'code' => 500,
                'message' => "Adresse MAC invalide: {$macAddress}",
            ];
        }

        $safeMac = escapeshellarg($macAddress);

        // Le WOL est une diffusion L2 : il ne dépend PAS de l'IP de la cible (qui
        // est éteinte, donc sans IP). On arrose tous les broadcasts plausibles —
        // broadcast de l'IP si connue, broadcast de chaque subnet DHCP configuré,
        // wol_broadcast, et 255.255.255.255 en dernier recours.
        $sent = 0;
        $messages = [];
        foreach ($this->resolveAllBroadcasts($ip !== '' ? $ip : null) as $broadcast) {
            $safeBroadcast = escapeshellarg($broadcast);
            $result = Process::run("/usr/bin/wakeonlan -i {$safeBroadcast} {$safeMac}");
            if ($result->successful()) {
                $sent++;
            }
            $output = trim($result->output());
            if ($output !== '') {
                $messages[] = $output;
            }
        }

        if ($sent === 0) {
            if ($machineName) {
                $this->logAction($machineName, 'wake', false);
            }

            return [
                'success' => false,
                'code' => 203,
                'message' => "Échec de l'envoi du paquet WOL (aucun broadcast joignable)",
            ];
        }

        if ($machineName) {
            $this->logAction($machineName, 'wake', true);
        }

        return [
            'success' => true,
            'code' => 202,
            'message' => 'WOL envoyé : '.implode(' | ', array_filter($messages)),
        ];
    }

    /**
     * Éteint une machine (Windows via net rpc, Linux via SSH)
     *
     * Sémantique de $force (cohérente avec le legacy start_machine_local() ligne 271) :
     * - $force=false (défaut) : l'arrêt est conditionné à l'absence d'utilisateur
     *   connecté. Le legacy appelait $machine['user'] — non porté côté Laravel,
     *   donc en pratique l'arrêt part tout de même (comportement dégradé connu).
     * - $force=true : court-circuite toute vérification "utilisateur connecté".
     *   Côté Windows, le flag `-f` du `net rpc shutdown` ferme les sessions
     *   interactives sans attendre (cf. manpage net(8)). Côté Linux, `shutdown -h now`
     *   est déjà inconditionnel. L'action est loggée sous `shutdown-force` dans
     *   `machine_boot_logs` pour audit trail (distinct de `shutdown` classique).
     *
     * @param  string  $machineName  Nom de la machine
     * @param  string  $ip  Adresse IP
     * @param  bool  $force  Forcer l'arrêt même si un utilisateur est connecté
     * @return array{success: bool, code: int, message: string}
     */
    public function shutdown(string $machineName, string $ip, bool $force = false): array
    {
        $os = $this->ping($ip);
        $logAction = $force ? 'shutdown-force' : 'shutdown';
        $labelSuffix = $force ? ' (forcée)' : '';

        if ($os === false) {
            $this->logAction($machineName, $logAction, false, null);

            return [
                'success' => false,
                'code' => 203,
                'message' => "{$machineName} est déjà éteinte — aucune action effectuée",
            ];
        }

        // Si non-force, on pourrait rejeter l'extinction quand un utilisateur est
        // connecté. Le check "user connected" legacy n'étant pas porté côté SER,
        // on continue dans tous les cas — $force sert aujourd'hui principalement
        // à distinguer l'intention dans les logs et à verrouiller `-f` Windows.

        if ($os === 'windows') {
            $safeName = escapeshellarg($machineName);
            // Note : le flag `-f` de net rpc shutdown force la fermeture des
            // sessions interactives côté Windows ; il est historiquement toujours
            // présent dans le legacy (comportement non régressé ici).
            $command = "/usr/bin/net rpc shutdown --use-kerberos=required -t 30 -f -C \"Arrêt demandé par SambaEdu\" -S {$safeName} 2>&1";
            $result = Process::run($command);

            if ($result->exitCode() !== 0) {
                Log::warning("Échec shutdown Windows {$machineName}", ['output' => $result->output(), 'force' => $force]);
                $this->logAction($machineName, $logAction, false, $os);

                return [
                    'success' => false,
                    'code' => 203,
                    'message' => "Arrêt impossible pour {$machineName}: ".trim($result->output()),
                ];
            }

            $this->logAction($machineName, $logAction, true, $os);

            return [
                'success' => true,
                'code' => 201,
                'message' => "Arrêt{$labelSuffix} de {$machineName} (Windows) : ".trim($result->output()),
            ];
        }

        // Linux
        $safeName = escapeshellarg($machineName);
        $command = "/usr/bin/ssh -i /etc/sambaedu/id_rsa -o StrictHostKeyChecking=no -o ConnectTimeout=1 root@{$safeName} shutdown -h now 2>&1";
        $result = Process::run($command);

        if ($result->exitCode() !== 0) {
            Log::warning("Échec shutdown Linux {$machineName}", ['output' => $result->output(), 'force' => $force]);
            $this->logAction($machineName, $logAction, false, $os);

            return [
                'success' => false,
                'code' => 203,
                'message' => "Arrêt impossible pour {$machineName}: ".trim($result->output()),
            ];
        }

        $this->logAction($machineName, $logAction, true, $os);

        return [
            'success' => true,
            'code' => 201,
            'message' => "Arrêt{$labelSuffix} de {$machineName} (Linux) : OK",
        ];
    }

    /**
     * Redémarre une machine (Windows via net rpc, Linux via SSH)
     * Si la machine est éteinte, fallback automatique vers WOL
     *
     * @param  string  $machineName  Nom de la machine
     * @param  string  $ip  Adresse IP
     * @param  string  $macAddress  Adresse MAC (pour fallback WOL)
     * @param  bool  $force  Forcer le reboot
     * @return array{success: bool, code: int, message: string}
     */
    // NOTE: $force — même constat que shutdown(), non implémenté (fidèle au legacy).
    // Le fallback WOL sur échec de reboot reproduit le comportement legacy (start_machine_local).
    public function reboot(string $machineName, string $ip, string $macAddress = '', bool $force = false): array
    {
        $os = $this->ping($ip);

        // Machine éteinte → fallback WOL
        if ($os === false) {
            if (! empty($macAddress)) {
                $wolResult = $this->wakeOnLan($macAddress, $ip, $machineName);
                $this->logAction($machineName, 'reboot', $wolResult['success'], null);

                return [
                    'success' => $wolResult['success'],
                    'code' => $wolResult['code'],
                    'message' => "{$machineName} est éteinte, tentative WOL : ".$wolResult['message'],
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
                if (! empty($macAddress)) {
                    $wolResult = $this->wakeOnLan($macAddress, $ip);

                    return [
                        'success' => false,
                        'code' => 203,
                        'message' => "Reboot impossible pour {$machineName}, tentative WOL : ".$wolResult['message'],
                    ];
                }

                return [
                    'success' => false,
                    'code' => 203,
                    'message' => "Reboot impossible pour {$machineName}: ".trim($result->output()),
                ];
            }

            $this->logAction($machineName, 'reboot', true, $os);

            return [
                'success' => true,
                'code' => 201,
                'message' => "Reboot de {$machineName} (Windows) : ".trim($result->output()),
            ];
        }

        // Linux
        $safeName = escapeshellarg($machineName);
        $command = "/usr/bin/ssh -i /etc/sambaedu/id_rsa -o StrictHostKeyChecking=no -o ConnectTimeout=1 root@{$safeName} shutdown -r now 2>&1";
        $result = Process::run($command);

        if ($result->exitCode() !== 0) {
            Log::warning("Échec reboot Linux {$machineName}", ['output' => $result->output()]);
            $this->logAction($machineName, 'reboot', false, $os);
            if (! empty($macAddress)) {
                $wolResult = $this->wakeOnLan($macAddress, $ip);

                return [
                    'success' => false,
                    'code' => 203,
                    'message' => "Reboot impossible pour {$machineName}, tentative WOL : ".$wolResult['message'],
                ];
            }

            return [
                'success' => false,
                'code' => 203,
                'message' => "Reboot impossible pour {$machineName}: ".trim($result->output()),
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
     * @param  string  $ip  Adresse IP de la machine
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
        if (! empty($network->mask)) {
            $maskLong = ip2long($network->mask);
            if ($maskLong !== false) {
                return long2ip(($ipLong | ~$maskLong) & 0xFFFFFFFF);
            }
        }

        // Stratégie 3 : Broadcast /24 par défaut
        return long2ip(($ipLong | 0x000000FF) & 0xFFFFFFFF);
    }

    /**
     * Liste tous les broadcasts plausibles pour atteindre une machine.
     *
     * Utilisé par le WOL : la cible étant éteinte, on ne connaît pas son VLAN
     * courant — on diffuse donc sur le broadcast de CHAQUE subnet DHCP configuré,
     * plus éventuellement le broadcast dérivé d'une IP connue et le wol_broadcast
     * explicite. 255.255.255.255 reste le dernier recours (segment local).
     *
     * @param  string|null  $ip  IP courante de la machine si connue (souvent null en WOL)
     * @return list<string> Adresses broadcast dédupliquées, jamais vide
     */
    public function resolveAllBroadcasts(?string $ip = null): array
    {
        $broadcasts = [];

        // 1. Broadcast dérivé de l'IP courante si on la connaît.
        if (! empty($ip)) {
            $perIp = $this->resolveBroadcast($ip);
            if ($perIp !== false) {
                $broadcasts[] = $perIp;
            }
        }

        // 2. Broadcast de chaque subnet DHCP configuré (la machine peut être sur
        //    n'importe quel VLAN quand elle est éteinte).
        foreach ($this->allConfiguredBroadcasts() as $bc) {
            $broadcasts[] = $bc;
        }

        // 3. wol_broadcast explicite (override site, fiabilité cross-VLAN).
        $wolBroadcast = $this->configService->get('wol_broadcast');
        if ($wolBroadcast) {
            $broadcasts[] = (string) $wolBroadcast;
        }

        $broadcasts = array_values(array_unique(array_filter($broadcasts)));

        // 4. Dernier recours : broadcast global (atteint le segment local du serveur).
        if (empty($broadcasts)) {
            $broadcasts[] = '255.255.255.255';
        }

        return $broadcasts;
    }

    /**
     * Logue un timeout de readiness post-WOL (AC4 story 4-2).
     *
     * Utilisé par le composant Livewire MachineShow quand le polling wire:poll.3s
     * n'a pas détecté la machine comme disponible dans les
     * MACHINE_READINESS_TIMEOUT_SECONDS (config/parc.php).
     *
     * Correction review #11 (2026-04-20) : on ferme le log WOL ouvert (stopped_at
     * null) plutôt que de créer une nouvelle ligne — sinon (a) le log WOL initial
     * reste ouvert indéfiniment et un futur shutdown le fermera erronément, (b)
     * on perd le started_at d'origine et la durée d'attente devient incalculable.
     * En fallback (aucun log ouvert trouvé — concurrence ou reset DB), on crée
     * une ligne avec started_at/stopped_at cohérents pour préserver l'audit trail.
     */
    public function logReadinessTimeout(string $machineName, string $originalAction): void
    {
        try {
            $normalizedName = strtolower($machineName);

            // 1. Chercher un log WOL encore ouvert (started_at renseigné, stopped_at null)
            //    pour le fermer avec le flag de timeout.
            $openLog = MachineBootLog::where('machine_name', $normalizedName)
                ->where('action', 'wake')
                ->whereNotNull('started_at')
                ->whereNull('stopped_at')
                ->latest('started_at')
                ->first();

            if ($openLog) {
                $openLog->update([
                    'stopped_at' => now(),
                    'success' => false,
                    'error_flags' => 1,
                ]);

                return;
            }

            // 2. Fallback : pas de log ouvert trouvé — on crée une ligne dédiée
            //    avec started_at calculé depuis le timeout configuré pour rester
            //    cohérent (la durée d'attente observée côté UI).
            $timeoutSeconds = (int) config('parc.machine_readiness_timeout_seconds', 120);
            $workstation = Workstation::where('name', $normalizedName)->first();
            $initiatedBy = auth()->user()?->name ?? session('login') ?? 'system';

            MachineBootLog::create([
                'workstation_id' => $workstation?->id,
                'machine_name' => $normalizedName,
                'action' => $originalAction,
                'initiated_by' => $initiatedBy,
                'success' => false,
                'os' => null,
                'started_at' => now()->subSeconds($timeoutSeconds),
                'stopped_at' => now(),
                // Bit 0 réservé pour le timeout readiness (schéma error_flags libre dans la table).
                'error_flags' => 1,
            ]);
        } catch (\Exception $e) {
            Log::warning("Impossible de logger le timeout readiness pour {$machineName}", [
                'error' => $e->getMessage(),
            ]);
        }
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

            if (in_array($action, ['shutdown', 'shutdown-force', 'reboot'], true) && $success) {
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
        ['reseaux' => $reseaux, 'masques' => $masques] = $this->parseDhcpNetworks();

        if (empty($reseaux) || empty($masques)) {
            return false;
        }

        // Chercher le VLAN qui contient l'IP
        foreach ($reseaux as $idx => $reseauLong) {
            if (! isset($masques[$idx])) {
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
     * Calcule le broadcast de chaque subnet DHCP configuré (tous VLAN confondus).
     *
     * @return list<string>
     */
    private function allConfiguredBroadcasts(): array
    {
        ['reseaux' => $reseaux, 'masques' => $masques] = $this->parseDhcpNetworks();

        $broadcasts = [];
        foreach ($reseaux as $idx => $reseauLong) {
            if (! isset($masques[$idx])) {
                continue;
            }
            $broadcasts[] = long2ip(($reseauLong | ~$masques[$idx]) & 0xFFFFFFFF);
        }

        return $broadcasts;
    }

    /**
     * Parse les paramètres DHCP legacy (dhcp_reseau_*, dhcp_masque_*, et les
     * variantes sans suffixe) en deux maps indexées par VLAN.
     *
     * @return array{reseaux: array<int,int>, masques: array<int,int>}
     */
    private function parseDhcpNetworks(): array
    {
        $rawConfig = $this->configService->all();

        $reseaux = [];
        $masques = [];

        foreach ($rawConfig as $key => $value) {
            if (preg_match('/^dhcp_reseau_(\d+)$/', $key, $m)) {
                $parsed = ip2long((string) $value);
                if ($parsed !== false) {
                    $reseaux[(int) $m[1]] = $parsed;
                }
            } elseif ($key === 'dhcp_reseau') {
                $parsed = ip2long((string) $value);
                if ($parsed !== false) {
                    $reseaux[0] = $parsed;
                }
            }
            if (preg_match('/^dhcp_masque_(\d+)$/', $key, $m)) {
                $parsed = ip2long((string) $value);
                if ($parsed !== false) {
                    $masques[(int) $m[1]] = $parsed;
                }
            } elseif ($key === 'dhcp_masque') {
                $parsed = ip2long((string) $value);
                if ($parsed !== false) {
                    $masques[0] = $parsed;
                }
            }
        }

        return ['reseaux' => $reseaux, 'masques' => $masques];
    }

    /**
     * Valide le format d'une adresse MAC
     */
    private function isValidMacAddress(string $mac): bool
    {
        return (bool) preg_match('/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/', $mac);
    }
}
