<?php

namespace App\Services\SE4;

use App\Config\SambaEduConfig;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service pour exécuter des commandes PowerShell sur des machines Windows distantes
 */
class PowerShellRemoteService
{
    private SambaEduConfig $configService;

    public function __construct(SambaEduConfig $configService)
    {
        $this->configService = $configService;
    }

    /**
     * Exécute une commande PowerShell sur une machine distante
     *
     * @param string $machineName Nom de la machine cible
     * @param string $command Commande PowerShell à exécuter
     * @param bool $silent Mode silencieux (pas d'attente de retour)
     * @return array ['success' => bool, 'output' => string, 'error' => string, 'method' => string]
     */
    public function executeCommand(string $machineName, string $command, bool $silent = false): array
    {
        $machineName = strtolower(trim($machineName));
        $config = $this->configService->legacy()->getConfig();

        // Vérifier que ce n'est pas un serveur SE4
        if ($machineName === $config['se4fs_name'] || $machineName === $config['se4ad_name']) {
            return [
                'success' => false,
                'error' => 'Impossible d\'exécuter des commandes sur les serveurs SE4',
                'output' => '',
                'method' => 'none'
            ];
        }

        // Rechercher la machine dans l'AD via les fonctions legacy
        $this->loadLegacyFunctions();
        $machine = search_machine($config, $machineName, true);
        if (empty($machine)) {
            return [
                'success' => false,
                'error' => 'Machine non trouvée dans l\'annuaire',
                'output' => '',
                'method' => 'none'
            ];
        }

        // Vérifier que la machine est accessible
        $ip = $machine['iphostnumber'] ?? $machineName;
        $ping = $this->checkMachineStatus($ip);

        if ($ping !== 'windows') {
            return [
                'success' => false,
                'error' => 'Machine non accessible ou non Windows (ping: ' . $ping . ')',
                'output' => '',
                'method' => 'none'
            ];
        }

        // Tenter l'exécution avec WinExe en priorité
        if (file_exists('/usr/bin/winexe')) {
            return $this->executeViaWinExe($machineName, $command, $config, $silent);
        }

        // Fallback : méthode par script déposé
        return $this->executeViaScriptDeploy($machineName, $command, $config, $machine);
    }

    /**
     * Exécute une commande CMD (non PowerShell) sur une machine distante
     *
     * @param string $machineName Nom de la machine cible
     * @param string $command Commande CMD à exécuter
     * @param bool $silent Mode silencieux
     * @return array
     */
    public function executeCmdCommand(string $machineName, string $command, bool $silent = false): array
    {
        $machineName = strtolower(trim($machineName));
        $config = $this->configService->legacy()->getConfig();

        if (!file_exists('/usr/bin/winexe')) {
            return [
                'success' => false,
                'error' => 'WinExe non installé sur le serveur',
                'output' => '',
                'method' => 'none'
            ];
        }

        $escapedCommand = escapeshellarg($command);

        $commande = "/usr/bin/winexe --use-kerberos=yes " .
            "-U " . escapeshellarg($config['samba_domain'] . "\\" . $config['ldap_admin_name']) .
            "%" . escapeshellarg($config['ldap_admin_passwd']) . " " .
            "//" . $machineName . " " . $escapedCommand;

        if ($silent) {
            $commande .= " >/dev/null 2>&1 &";
        } else {
            $commande .= " 2>&1";
        }

        Log::info('Executing CMD command', [
            'machine' => $machineName,
            'command' => $command
        ]);

        exec($commande, $out, $ret);

        $result = [
            'success' => ($ret === 0),
            'output' => implode("\n", $out),
            'error' => ($ret !== 0) ? implode("\n", $out) : '',
            'return_code' => $ret,
            'method' => 'winexe-cmd'
        ];

        Log::info('CMD command result', [
            'machine' => $machineName,
            'success' => $result['success'],
            'return_code' => $ret
        ]);

        return $result;
    }

    /**
     * Exécute via WinExe (méthode recommandée)
     */
    private function executeViaWinExe(string $machineName, string $command, array $config, bool $silent): array
    {
        // Échapper la commande PowerShell pour l'exécution
        $escapedCommand = str_replace(['"', '$'], ['\"', '\$'], $command);

        $commande = "/usr/bin/winexe --use-kerberos=yes " .
            "-U " . escapeshellarg($config['samba_domain'] . "\\" . $config['ldap_admin_name']) .
            "%" . escapeshellarg($config['ldap_admin_passwd']) . " " .
            "//" . $machineName . " " .
            "\"powershell.exe -NoProfile -ExecutionPolicy Bypass -Command \\\"" . $escapedCommand . "\\\"\"";

        if ($silent) {
            $commande .= " >/dev/null 2>&1 &";
        } else {
            $commande .= " 2>&1";
        }

        Log::info('Executing PowerShell via WinExe', [
            'machine' => $machineName,
            'command' => $command
        ]);

        exec($commande, $out, $ret);

        $result = [
            'success' => ($ret === 0),
            'output' => implode("\n", $out),
            'error' => ($ret !== 0) ? implode("\n", $out) : '',
            'return_code' => $ret,
            'method' => 'winexe'
        ];

        Log::info('PowerShell execution result', [
            'machine' => $machineName,
            'success' => $result['success'],
            'return_code' => $ret
        ]);

        return $result;
    }

    /**
     * Exécute via déploiement de script (fallback)
     */
    private function executeViaScriptDeploy(string $machineName, string $command, array $config, array $machine): array
    {
        $scriptId = uniqid('ps_');
        $scriptPath = "/tmp/" . $scriptId . ".ps1";
        $remoteScript = "C:\\Windows\\Temp\\" . $scriptId . ".ps1";

        try {
            // Créer le script local
            file_put_contents($scriptPath, $command);

            // Copier sur la machine distante
            $smbCommand = "/usr/bin/smbclient //" . $machineName . "/C$ " .
                "-U " . escapeshellarg($config['ldap_admin_name']) .
                "%" . escapeshellarg($config['ldap_admin_passwd']) .
                " -c 'put " . $scriptPath . " Windows/Temp/" . $scriptId . ".ps1' 2>&1";

            exec($smbCommand, $out, $ret);

            if ($ret !== 0) {
                unlink($scriptPath);
                return [
                    'success' => false,
                    'error' => 'Impossible de copier le script : ' . implode("\n", $out),
                    'output' => '',
                    'method' => 'script-deploy'
                ];
            }

            // Créer un batch pour exécuter le script
            $batContent = "@echo off\r\n" .
                "powershell.exe -NoProfile -ExecutionPolicy Bypass -File \"" . $remoteScript . "\"\r\n" .
                "del \"" . $remoteScript . "\"\r\n";
            $batPath = "/tmp/" . $scriptId . ".bat";
            file_put_contents($batPath, $batContent);

            exec("/usr/bin/smbclient //" . $machineName . "/C$ " .
                "-U " . escapeshellarg($config['ldap_admin_name']) .
                "%" . escapeshellarg($config['ldap_admin_passwd']) .
                " -c 'put " . $batPath . " Windows/Temp/" . $scriptId . ".bat' 2>&1", $out, $ret);

            // Nettoyer les fichiers locaux
            unlink($scriptPath);
            unlink($batPath);

            if ($ret === 0) {
                return [
                    'success' => true,
                    'output' => 'Script PowerShell déployé avec succès (exécution asynchrone)',
                    'error' => '',
                    'method' => 'script-deploy'
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'Erreur lors du déploiement : ' . implode("\n", $out),
                    'output' => '',
                    'method' => 'script-deploy'
                ];
            }
        } catch (Exception $e) {
            if (file_exists($scriptPath)) {
                unlink($scriptPath);
            }
            return [
                'success' => false,
                'error' => 'Exception : ' . $e->getMessage(),
                'output' => '',
                'method' => 'script-deploy'
            ];
        }
    }

    /**
     * Vérifie le statut d'une machine (ping)
     */
    private function checkMachineStatus(string $ip): string
    {
        // Utiliser fping si disponible
        if (file_exists('/usr/bin/fping')) {
            exec("/usr/bin/fping -c 1 -t 200 " . escapeshellarg($ip) . " 2>&1", $out, $ret);
            if ($ret === 0) {
                // Machine accessible, vérifier si Windows via SMB
                exec("/usr/bin/smbclient -L " . escapeshellarg($ip) . " -N 2>&1 | grep -i 'workgroup\\|domain'", $smbOut, $smbRet);
                return ($smbRet === 0) ? 'windows' : 'linux';
            }
            return 'off';
        }

        // Fallback : ping standard
        exec("/bin/ping -c 1 -W 1 " . escapeshellarg($ip) . " >/dev/null 2>&1", $out, $ret);
        return ($ret === 0) ? 'windows' : 'off';
    }

    /**
     * Liste les commandes PowerShell prédéfinies
     */
    public function getPredefinedCommands(): array
    {
        return [
            'system_info' => [
                'name' => 'Informations système',
                'command' => 'Get-ComputerInfo | Select-Object WindowsVersion, OsArchitecture, CsName, CsManufacturer, CsModel | Format-List',
                'description' => 'Récupère les informations système de base'
            ],
            'disk_space' => [
                'name' => 'Espace disque',
                'command' => 'Get-PSDrive -PSProvider FileSystem | Select-Object Name, @{Name="Used(GB)";Expression={[math]::Round($_.Used/1GB,2)}}, @{Name="Free(GB)";Expression={[math]::Round($_.Free/1GB,2)}} | Format-Table',
                'description' => 'Affiche l\'espace disque utilisé et disponible'
            ],
            'running_processes' => [
                'name' => 'Processus en cours',
                'command' => 'Get-Process | Sort-Object CPU -Descending | Select-Object -First 10 Name, CPU, @{Name="Memory(MB)";Expression={[math]::Round($_.WS/1MB,2)}} | Format-Table',
                'description' => 'Top 10 des processus par utilisation CPU'
            ],
            'services_stopped' => [
                'name' => 'Services arrêtés',
                'command' => 'Get-Service | Where-Object {$_.Status -eq "Stopped" -and $_.StartType -eq "Automatic"} | Select-Object Name, DisplayName | Format-Table',
                'description' => 'Liste les services automatiques qui sont arrêtés'
            ],
            'network_config' => [
                'name' => 'Configuration réseau',
                'command' => 'Get-NetIPAddress | Where-Object {$_.AddressFamily -eq "IPv4"} | Select-Object InterfaceAlias, IPAddress, PrefixLength | Format-Table',
                'description' => 'Affiche la configuration réseau IPv4'
            ],
            'windows_updates' => [
                'name' => 'Dernières mises à jour',
                'command' => 'Get-HotFix | Sort-Object InstalledOn -Descending | Select-Object -First 5 HotFixID, Description, InstalledOn | Format-Table',
                'description' => 'Liste les 5 dernières mises à jour Windows installées'
            ],
            'uptime' => [
                'name' => 'Temps de fonctionnement',
                'command' => '(Get-Date) - (Get-CimInstance Win32_OperatingSystem).LastBootUpTime | Select-Object Days, Hours, Minutes',
                'description' => 'Affiche le temps écoulé depuis le dernier démarrage'
            ],
            'event_errors' => [
                'name' => 'Erreurs système récentes',
                'command' => 'Get-EventLog -LogName System -EntryType Error -Newest 5 | Select-Object TimeGenerated, Source, Message | Format-List',
                'description' => 'Affiche les 5 dernières erreurs du journal système'
            ]
        ];
    }

    /**
     * Vérifie si WinExe est installé
     */
    public function isWinExeAvailable(): bool
    {
        return file_exists('/usr/bin/winexe');
    }

    /**
     * Charge les fonctions legacy nécessaires
     */
    private function loadLegacyFunctions(): void
    {
        static $loaded = false;

        if ($loaded) {
            return;
        }

        $basePath = base_path('../includes/');

        if (file_exists($basePath . 'config.inc.php')) {
            require_once $basePath . 'config.inc.php';
        }
        if (file_exists($basePath . 'ldap.inc.php')) {
            require_once $basePath . 'ldap.inc.php';
        }
        if (file_exists($basePath . 'parcs.inc.php')) {
            require_once $basePath . 'parcs.inc.php';
        }

        $loaded = true;
    }
}
