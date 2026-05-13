<?php

namespace App\Services;

use App\Config\LdapDnHelper;
use App\Facades\SEConfig;
use App\LdapModels\MachineModel;
use App\Models\Workstation;
use App\Observers\WorkstationObserver;
use App\Repositories\WorkstationRepository;
use App\Services\Ldap\EstablishmentMatcher;
use App\Services\Parc\MachinePowerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service pour la gestion des postes de travail (workstations)
 *
 * Délègue les actions power (WOL, shutdown, reboot) à MachinePowerService.
 */
class WorkstationService
{
    private const ACTION_WAKE = 'wake';
    private const ACTION_SHUTDOWN = 'shutdown';
    private const ACTION_SHUTDOWN_FORCE = 'shutdown-force';
    private const ACTION_RESTART = 'restart';

    private const VALID_ACTIONS = [
        self::ACTION_WAKE,
        self::ACTION_SHUTDOWN,
        self::ACTION_SHUTDOWN_FORCE,
        self::ACTION_RESTART,
    ];

    public function __construct(
        private WorkstationRepository $workstationRepository,
        private MachinePowerService $machinePowerService,
        private LdapDnHelper $dnHelper,
    ) {
    }


    /**
     * Récupère les machines d'un parc via le repository
     */
    public function getMachinesByParc(string $parcId): array
    {
        try {
            // Utiliser le repository pour récupérer les machines du parc
            $machines = $this->workstationRepository->findByParc($parcId);

            if ($machines->isEmpty()) {
                return [];
            }

            // Convertir en format métier et enrichir avec le statut
            $result = [];
            foreach ($machines as $machine) {
                try {
                    $machineData = [
                        'cn' => $machine->getMachineName(),
                        'name' => $machine->getMachineName(),
                        'dn' => $machine->getDn(),
                        'samaccountname' => $machine->getAttribute('samaccountname', ''),
                        'description' => $machine->getDescription(),
                        'operatingsystem' => $machine->getOperatingSystem(),
                        'operatingsystemversion' => $machine->getOperatingSystemVersion(),
                        'ip' => $machine->getIpAddress(),
                        'mac' => $machine->getMacAddress(),
                        'location' => $machine->getLocation(),
                        'is_active' => $machine->isActive(),
                        'hostname' => $machine->getHostname(),
                    ];

                    // Enrichir avec le statut (nécessite encore les fonctions legacy pour l'instant)
                    $machineData['status'] = $this->getMachineStatus($machineData['cn']);

                    $result[] = $machineData;
                } catch (\Exception $e) {
                    Log::warning('Erreur lors du traitement d\'une machine', [
                        'machine_dn' => $machine->getDn(),
                        'error' => $e->getMessage()
                    ]);
                    continue;
                }
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Erreur MachineService::getMachinesByParc', [
                'parc_id' => $parcId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [];
        }
    }

    /**
     * Enrichit une machine avec son statut
     */
    private function enrichMachineWithStatus(array $machine): array
    {
        $machineName = $machine['cn'] ?? '';
        if (empty($machineName)) {
            return $machine;
        }

        $machine['status'] = $this->getMachineStatus($machineName);
        return $machine;
    }

    /**
     * Wake-on-LAN pour une liste de machines avec LdapRecord
     */
    public function wakeOnLan(array $machineNames): bool
    {
        $result = $this->executePowerAction($machineNames, self::ACTION_WAKE);

        return $result['requested_count'] > 0 && $result['failed_count'] === 0;
    }

    /**
     * Arrête une liste de machines avec LdapRecord
     */
    public function shutdownMachines(array $machineNames): bool
    {
        $result = $this->executePowerAction($machineNames, self::ACTION_SHUTDOWN);

        return $result['requested_count'] > 0 && $result['failed_count'] === 0;
    }

    /**
     * Redémarre une liste de machines avec LdapRecord
     */
    public function restartMachines(array $machineNames): bool
    {
        $result = $this->executePowerAction($machineNames, self::ACTION_RESTART);

        return $result['requested_count'] > 0 && $result['failed_count'] === 0;
    }

    /**
     * Exécute une action d'alimentation sur une liste de machines
     *
     * @param array<int, string> $machineNames
     * @return array{
     *   action: string,
     *   requested_count: int,
     *   success_count: int,
     *   failed_count: int,
     *   results: array<int, array{machine: string, success: bool, code: int}>
     * }
     */
    public function executePowerAction(array $machineNames, string $action): array
    {
        if (!in_array($action, self::VALID_ACTIONS, true)) {
            throw new \InvalidArgumentException("Action machine non supportée: {$action}");
        }

        try {
            $normalizedNames = array_values(array_filter(array_map(
                static fn (mixed $name): string => is_string($name) ? trim($name) : '',
                $machineNames
            )));

            $results = [];
            $successCount = 0;

            foreach ($normalizedNames as $machineName) {
                $machineModel = $this->resolveMachineModel($machineName);

                if (!$machineModel) {
                    Log::warning('Machine non trouvée pour action d\'alimentation', [
                        'machine' => $machineName,
                        'action' => $action,
                    ]);

                    $results[] = [
                        'machine' => $machineName,
                        'success' => false,
                        'code' => 404,
                    ];

                    continue;
                }

                $name = (string) $machineModel->getMachineName();
                $ip = (string) ($machineModel->getIpAddress() ?? $name);
                $mac = (string) ($machineModel->getMacAddress() ?? '');

                $actionResult = match ($action) {
                    self::ACTION_WAKE => $this->machinePowerService->wakeOnLan($mac, $ip, $name),
                    self::ACTION_SHUTDOWN => $this->machinePowerService->shutdown($name, $ip, false),
                    self::ACTION_SHUTDOWN_FORCE => $this->machinePowerService->shutdown($name, $ip, true),
                    self::ACTION_RESTART => $this->machinePowerService->reboot($name, $ip, $mac),
                };

                $isSuccess = $this->isLegacyActionSuccessful($actionResult['code']);

                if ($isSuccess) {
                    $successCount++;
                }

                $results[] = [
                    'machine' => $name,
                    'success' => $isSuccess,
                    'code' => $actionResult['code'],
                ];
            }

            $requestedCount = count($normalizedNames);

            return [
                'action' => $action,
                'requested_count' => $requestedCount,
                'success_count' => $successCount,
                'failed_count' => $requestedCount - $successCount,
                'results' => $results,
            ];

        } catch (\Exception $e) {
            Log::error('Erreur MachineService::executePowerAction', [
                'action' => $action,
                'machines' => $machineNames,
                'error' => $e->getMessage()
            ]);

            return [
                'action' => $action,
                'requested_count' => count($machineNames),
                'success_count' => 0,
                'failed_count' => count($machineNames),
                'results' => [],
            ];
        }
    }

    private function resolveMachineModel(string $machineName): ?MachineModel
    {
        $machineModel = $this->workstationRepository->findByName($machineName);

        if (!$machineModel) {
            $machineModel = $this->workstationRepository->findByHostname($machineName);
        }

        return $machineModel;
    }

    private function isLegacyActionSuccessful(int $code): bool
    {
        return $code >= 200 && $code < 300 && $code !== 203;
    }

    /**
     * Récupère les déploiements en cours (pour les actions en arrière-plan)
     */
    public function getRunningDeployments(): array
    {
        try {
            // TODO: Implémenter la récupération des déploiements en cours
            // Pour l'instant, retourne un tableau vide
            return [];

        } catch (\Exception $e) {
            Log::error('Erreur MachineService::getRunningDeployments', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère le statut d'une machine spécifique via ping natif
     */
    public function getMachineStatus(string $machineName): array
    {
        try {
            $machineModel = $this->resolveMachineModel($machineName);
            $ip = $machineModel ? (string) ($machineModel->getIpAddress() ?? $machineName) : $machineName;

            $os = $this->machinePowerService->ping($ip);

            if ($os === false) {
                return [
                    'status' => 'off',
                    'user' => null,
                    'login_time' => null,
                ];
            }

            return [
                'status' => $os, // 'windows' ou 'linux'
                'user' => null,
                'login_time' => null,
            ];
        } catch (\Exception $e) {
            Log::error('Erreur MachineService::getMachineStatus', [
                'machine' => $machineName,
                'error' => $e->getMessage()
            ]);

            return [
                'status' => 'unknown',
                'user' => null,
                'login_time' => null,
            ];
        }
    }

    /**
     * Vérifie si une machine existe via le repository
     */
    public function machineExists(string $machineName): bool
    {
        try {
            // Utiliser le repository pour vérifier l'existence
            return $this->workstationRepository->exists($machineName);

        } catch (\Exception $e) {
            Log::error('Erreur MachineService::machineExists', [
                'machine' => $machineName,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Importe les workstations depuis l'Active Directory vers la base de données SQL.
     * 
     * ⚠️ WARNING: Cette méthode ne devrait être utilisée QUE pour l'initialisation initiale
     * de la base de données Laravel. Une fois l'import effectué, SQL devient la source de vérité
     * et les modifications doivent être faites via l'interface Laravel, qui synchronisera
     * automatiquement vers l'AD via les observers.
     * 
     * @deprecated Utiliser uniquement pour la migration initiale AD → SQL
     * @param callable|null $logCallback Callback pour les logs (fn(string $level, string $message) => void)
     * @return array Statistiques d'import ['created' => int, 'updated' => int, 'skipped' => int, 'linked' => int, 'errors' => array]
     */
    public function importFromAd(?callable $logCallback = null): array
    {
        Log::warning('WorkstationService::importFromAd() appelé - Cette méthode ne devrait être utilisée que pour l\'initialisation initiale. SQL est la source de vérité.');

        $log = $logCallback ?? fn(string $level, string $message) => Log::log($level, $message);
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'etab_tree' => 0,
            'etab_ou_tree' => 0,
            'etab_member_of' => 0,
            'etab_excluded' => 0,
            'errors' => [],
        ];

        try {
            $computersDn = $this->dnHelper->computers();
            $log('info', "Recherche dans: {$computersDn}");

            $establishmentCode = SEConfig::getCurrentEstablishmentCode();
            $establishmentDn = null;
            if (! empty($establishmentCode) && $establishmentCode !== '0') {
                $establishmentDn = SEConfig::ldap()->etablissementDn($establishmentCode);
                $log('info', "Filtre établissement actif: {$establishmentCode}");
            } else {
                $log('info', 'Aucun établissement sélectionné — import en mode domaine entier');
            }

            $connection = \LdapRecord\Container::getDefaultConnection();
            $machinesAd = $connection->query()
                ->in($computersDn)
                ->rawFilter('(objectclass=computer)')
                ->select(['cn', 'objectguid', 'iphostnumber', 'networkaddress', 'operatingsystem', 'description', 'memberof'])
                ->get();

            $log('info', count($machinesAd) . ' machines trouvées dans l\'AD');

            // Désactiver la synchronisation AD pendant l'import pour éviter les boucles
            WorkstationObserver::disableSync();

            try {
                DB::beginTransaction();

                foreach ($machinesAd as $machine) {
                    try {
                        // La connexion directe retourne des tableaux
                        $name = $machine['cn'][0] ?? null;
                        if (empty($name)) {
                            continue;
                        }
                        $name = strtolower($name);

                        $dn = $machine['dn'] ?? '';
                        $memberOf = is_array($machine['memberof'] ?? null) ? $machine['memberof'] : [];

                        $matchType = EstablishmentMatcher::match($dn, $memberOf, $establishmentDn);
                        if ($matchType === null) {
                            $stats['etab_excluded']++;
                            continue;
                        }
                        if ($matchType === EstablishmentMatcher::MATCH_TREE) {
                            $stats['etab_tree']++;
                        } elseif ($matchType === EstablishmentMatcher::MATCH_OU_TREE) {
                            $stats['etab_ou_tree']++;
                        } elseif ($matchType === EstablishmentMatcher::MATCH_MEMBER_OF) {
                            $stats['etab_member_of']++;
                        }

                        $rawGuid = $machine['objectguid'][0] ?? null;
                        $uuid = $rawGuid ? $this->convertGuidToString($rawGuid) : null;

                        $ip = $machine['iphostnumber'][0] ?? null;
                        $mac = $machine['networkaddress'][0] ?? null;
                        $os = $machine['operatingsystem'][0] ?? null;

                        $existing = Workstation::where('name', $name)->first();

                        if ($existing) {
                            $updated = false;
                            
                            if (empty($existing->ad_guid) && !empty($uuid)) {
                                $existing->ad_guid = $uuid;
                                $updated = true;
                            }
                            if (empty($existing->ad_dn) && !empty($dn)) {
                                $existing->ad_dn = $dn;
                                $updated = true;
                            }
                            if (empty($existing->ip) && !empty($ip)) {
                                $existing->ip = $ip;
                                $updated = true;
                            }
                            if (empty($existing->mac) && !empty($mac)) {
                                $existing->mac = $mac;
                                $updated = true;
                            }
                            if (empty($existing->os) && !empty($os)) {
                                $existing->os = $os;
                                $updated = true;
                            }

                            // Les liens avec les groupes seront créés par WorkstationGroupService::importFromAd()

                            if ($updated) {
                                $existing->save();
                                $stats['updated']++;
                            } else {
                                $stats['skipped']++;
                            }
                        } else {
                            Workstation::create([
                                'name' => $name,
                                'ad_dn' => $dn,
                                'ad_guid' => $uuid,
                                'ip' => $ip,
                                'mac' => $mac,
                                'os' => $os,
                                'status' => 'active',
                            ]);

                            $stats['created']++;
                            $log('success', "Créé: {$name}");
                        }
                    } catch (\Exception $e) {
                        $machineName = $machine['cn'][0] ?? 'inconnu';
                        $stats['errors'][] = "Erreur pour {$machineName}: " . $e->getMessage();
                        $log('error', "Erreur pour {$machineName}: " . $e->getMessage());
                    }
                }

                DB::commit();
                
            } finally {
                WorkstationObserver::enableSync();
            }

            if ($establishmentDn !== null) {
                $log('info', sprintf(
                    'Filtre établissement: %d via CN-arbo, %d via OU-arbo, %d via memberOf, %d exclu(s)',
                    $stats['etab_tree'],
                    $stats['etab_ou_tree'],
                    $stats['etab_member_of'],
                    $stats['etab_excluded']
                ));
            }
            $log('info', "Résultat: {$stats['created']} créés, {$stats['updated']} mis à jour, {$stats['skipped']} ignorés");

        } catch (\Exception $e) {
            DB::rollBack();
            $stats['errors'][] = 'Erreur globale: ' . $e->getMessage();
            $log('error', 'Erreur lors de l\'import: ' . $e->getMessage());
            Log::error('WorkstationService::importFromAd erreur', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return $stats;
    }

    /**
     * Extrait le nom du groupe parent depuis le DN d'une machine
     */
    private function extractParentGroupFromDn(string $dn): ?string
    {
        if (preg_match('/^CN=[^,]+,OU=([^,]+),/i', $dn, $matches)) {
            $parent = $matches[1];
            if (strtolower($parent) !== 'computers') {
                return $parent;
            }
        }
        return null;
    }

    /**
     * Convertit un GUID binaire en chaîne formatée
     */
    private function convertGuidToString(string $binaryGuid): string
    {
        $hex = bin2hex($binaryGuid);
        if (strlen($hex) !== 32) {
            return $hex;
        }
        return sprintf(
            '%s%s%s%s-%s%s-%s%s-%s-%s',
            substr($hex, 6, 2), substr($hex, 4, 2), substr($hex, 2, 2), substr($hex, 0, 2),
            substr($hex, 10, 2), substr($hex, 8, 2),
            substr($hex, 14, 2), substr($hex, 12, 2),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Génère des données mockées pour les machines d'un parc
     * TEMPORAIRE - Pour tester l'interface
     */
    private function getMockedMachines(string $parcId): array
    {
        $machineNames = [
            'PC-01',
            'PC-02',
            'PC-03',
            'PC-04',
            'PC-05',
            'PC-06',
            'PC-07',
            'PC-08',
            'PC-09',
            'PC-10',
            'PC-11',
            'PC-12',
            'PC-13',
            'PC-14',
            'PC-15',
            'PC-16',
            'PC-17',
            'PC-18',
            'PC-19',
            'PC-20',
            'PROF-01',
            'PROF-02',
            'SERVEUR-01'
        ];

        $statuses = ['on', 'off', 'login', 'unknown'];
        $users = ['', 'jdupont', 'mmartin', 'adurand', 'smoreau', 'lbernard', 'pthomas', 'nrobert'];
        $osTypes = ['Windows 10', 'Windows 11', 'Ubuntu 20.04', 'Ubuntu 22.04'];
        $ips = [];

        // Générer des IPs dans la plage 192.168.1.x
        for ($i = 10; $i <= 250; $i++) {
            $ips[] = "192.168.1.$i";
        }

        $machines = [];
        $machineCount = rand(8, 23); // Nombre aléatoire de machines par parc

        for ($i = 0; $i < $machineCount; $i++) {
            $status = $statuses[array_rand($statuses)];
            $hasUser = $status === 'login' || ($status === 'on' && rand(0, 100) < 30); // 30% de chance d'avoir un utilisateur connecté
            $nonEmptyUsers = array_filter($users);
            $user = $hasUser && !empty($nonEmptyUsers) ? $nonEmptyUsers[array_rand($nonEmptyUsers)] : '';

            $machines[] = [
                'cn' => $machineNames[$i % count($machineNames)] . ($i >= count($machineNames) ? '-' . ceil($i / count($machineNames)) : ''),
                'name' => $machineNames[$i % count($machineNames)] . ($i >= count($machineNames) ? '-' . ceil($i / count($machineNames)) : ''),
                'dn' => "CN=" . $machineNames[$i % count($machineNames)] . ",OU=Computers,OU=$parcId,DC=lycee,DC=local",
                'samaccountname' => strtolower($machineNames[$i % count($machineNames)]) . '$',
                'description' => 'Machine ' . ($i + 1) . ' du parc ' . $parcId,
                'operatingsystem' => $osTypes[array_rand($osTypes)],
                'lastlogontimestamp' => time() - rand(0, 86400 * 7), // Dans la dernière semaine
                'whencreated' => time() - rand(86400 * 30, 86400 * 365), // Entre 1 mois et 1 an
                'pwdlastset' => time() - rand(0, 86400 * 90), // Mot de passe changé dans les 90 derniers jours
                'useraccountcontrol' => $status === 'off' && rand(0, 100) < 10 ? '514' : '4096', // 10% de machines désactivées quand off
                'ip' => $ips[array_rand($ips)],
                'mac' => sprintf(
                    '%02x:%02x:%02x:%02x:%02x:%02x',
                    rand(0, 255),
                    rand(0, 255),
                    rand(0, 255),
                    rand(0, 255),
                    rand(0, 255),
                    rand(0, 255)
                ),
                'status' => [
                    'status' => $status,
                    'user' => $user,
                    'login_time' => $hasUser ? (time() - rand(0, 28800)) : null, // Connecté depuis max 8h
                    'last_seen' => time() - rand(0, 3600), // Vue dans la dernière heure
                    'uptime' => $status === 'on' || $status === 'login' ? rand(3600, 86400 * 7) : 0, // Uptime si allumée
                    'cpu_usage' => $status === 'on' || $status === 'login' ? rand(5, 95) : 0,
                    'memory_usage' => $status === 'on' || $status === 'login' ? rand(20, 85) : 0,
                    'disk_usage' => rand(30, 90),
                ],
                'hardware' => [
                    'processor' => 'Intel Core i5-' . rand(8000, 12000),
                    'memory' => [4, 8, 16, 32][array_rand([4, 8, 16, 32])] . ' GB',
                    'disk_size' => [256, 512, 1024][array_rand([256, 512, 1024])] . ' GB SSD',
                    'graphics' => rand(0, 100) < 30 ? 'NVIDIA GeForce GTX ' . rand(1050, 1660) : 'Intel HD Graphics',
                ],
                'network' => [
                    'domain' => 'LYCEE.LOCAL',
                    'workgroup' => null,
                    'dns_servers' => ['192.168.1.1', '8.8.8.8'],
                    'gateway' => '192.168.1.1',
                    'subnet_mask' => '255.255.255.0',
                ],
                'software' => [
                    'antivirus' => ['Windows Defender', 'Avast', 'Kaspersky'][array_rand(['Windows Defender', 'Avast', 'Kaspersky'])],
                    'office_suite' => rand(0, 100) < 80 ? 'Microsoft Office 365' : 'LibreOffice',
                    'browser' => ['Chrome', 'Firefox', 'Edge'][array_rand(['Chrome', 'Firefox', 'Edge'])],
                    'last_update' => date('Y-m-d H:i:s', time() - rand(0, 86400 * 30)),
                ],
                'maintenance' => [
                    'last_reboot' => time() - rand(0, 86400 * 7),
                    'pending_updates' => rand(0, 15),
                    'disk_health' => ['Good', 'Good', 'Good', 'Warning', 'Critical'][array_rand(['Good', 'Good', 'Good', 'Warning', 'Critical'])], // 60% Good, 20% Warning, 20% Critical
                    'temperature' => $status === 'on' || $status === 'login' ? rand(35, 75) : rand(20, 30),
                ]
            ];
        }

        return $machines;
    }
}