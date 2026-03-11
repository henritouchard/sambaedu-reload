<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Imagick;
use App\Types\DeviceGroup;
use App\Collections\DeviceGroupCollection;
use App\Dto\DeviceGroupsResult;
use App\Repositories\WorkstationGroupRepository;
use App\Repositories\WorkstationRepository;
use App\LdapModels\DeviceGroupTagModel;
use App\LdapModels\DeviceGroupModel;
use App\LdapModels\MachineModel;

/**
 * Service pour la gestion des parcs informatiques
 * 
 * Utilise LdapRecord avec accesseurs sémantiques pour masquer la complexité LDAP
 */
class WorkstationGroupLdapService
{
    public function __construct(
        private WorkstationGroupRepository $workstationGroupRepository,
        private WorkstationRepository $workstationRepository
    ) {
    }

    /**
     * Récupère les groupes de périphériques (DeviceGroup/OU) et les tags orphelins (DeviceGroupTag/Group)
     * 
     * @param string|null $etabUai Code UAI de l'établissement pour filtrer les résultats (optionnel)
     * @return array Tableau avec 'rootGroup' (le parc racine), 'groups' (les autres groupes) et 'orphanTags' (tags non associés)
     */
    public function getGroupsWithTags(?string $etabUai = null): array
    {
        try {
            // Optimisation: construire le DN directement avec le code UAI
            $config = app(\App\Config\SambaEduConfig::class);
            $ldapConfig = $config->ldap();
            $baseDn = $ldapConfig->baseDn;

            // Construire le préfixe d'établissement si un code UAI valide est fourni
            $etabPrefix = '';
            if (!empty($etabUai) && $etabUai !== '0' && preg_match('/^[0-9]{7}[a-zA-Z]$/i', $etabUai)) {
                $etabPrefix = 'OU=' . $etabUai . ',';
            }

            // DN pour les ordinateurs (DeviceGroup/OU) - avec préfixe établissement
            // Structure: OU=<etab>,ou=computers,dc=...
            $computersDn = $etabPrefix . $ldapConfig->computersRdn . ',' . $baseDn;

            // DN pour les parcs (DeviceGroupTag/Group) - avec préfixe établissement
            // Structure legacy: OU=<etab>,ou=Parcs,dc=... (PAS dans ou=Groups!)
            $parcsDn = $etabPrefix . $ldapConfig->parcsRdn . ',' . $baseDn;

            Log::debug('[ParcService] Recherche parcs optimisée', [
                'etabUai' => $etabUai,
                'etabPrefix' => $etabPrefix,
                'computersDn' => $computersDn,
                'parcsDn' => $parcsDn
            ]);

            $groups = DeviceGroupModel::in($computersDn)->limit(500)->get();

            // Récupérer les tags dans le DN des parcs de l'établissement
            $allTags = DeviceGroupTagModel::in($parcsDn)->limit(500)->get();

            // Construire un index des noms de groupes pour vérifier les associations
            $groupNames = [];
            foreach ($groups as $group) {
                $groupName = $group->getGroupName();
                $groupNames[strtolower($groupName)] = true;
                // Vérifier aussi avec le format avec $ pour les associations
                $groupNames[strtolower($groupName . '$')] = true;
            }

            $rootGroup = null;
            $result = [];
            $orphanTags = [];

            // Traiter les groupes (DeviceGroup/OU)
            // Le filtrage par établissement est déjà fait via le DN de recherche
            foreach ($groups as $group) {
                $groupName = $group->getGroupName();
                $groupDn = $group->getDn();

                // Identifier le parc racine : c'est le groupe dont le DN correspond au DN des computers
                $isRootGroup = strcasecmp($groupDn, $computersDn) === 0;

                $groupData = [
                    'group' => [
                        'name' => $groupName,
                        'dn' => $groupDn,
                        'description' => $group->getGroupDescription(),
                        'isRoot' => $isRootGroup,
                    ],
                    'groupModel' => $group,
                    'tags' => [],
                ];

                // Séparer le parc racine des autres groupes
                if ($isRootGroup) {
                    $rootGroup = $groupData;
                } else {
                    $result[] = $groupData;
                }
            }

            // Retourner les tags bruts (modèles LDAP) - la conversion en Parc sera faite par l'appelant
            // Cela évite de faire N conversions ici + N conversions dans loadParcs()
            $orphanTagModels = [];
            foreach ($allTags as $tag) {
                $tagSam = strtolower($tag->getSamAccountName() ?? '');
                if (empty($tagSam) || !isset($groupNames[$tagSam])) {
                    $orphanTagModels[] = $tag;
                }
            }

            return [
                'rootGroup' => $rootGroup,
                'groups' => $result,              // Salles (DeviceGroup/OU) avec groupModel
                'tagModels' => $allTags,          // Modèles LDAP bruts des tags (pas encore convertis)
                'orphanTagModels' => $orphanTagModels, // Tags orphelins (modèles LDAP bruts)
            ];
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des groupes avec tags', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'rootGroup' => null,
                'groups' => [],
                'tagModels' => [],
                'orphanTags' => [],
            ];
        }
    }

    /**
     * Récupère uniquement les groupes (salles/OU) sans les tags WPKG
     * 
     * Cette méthode simplifiée ne récupère que les DeviceGroupModel (OU dans ou=computers)
     * qui représentent les salles avec GPO/imprimantes.
     * 
     * @param string|null $etabUai Code UAI de l'établissement pour filtrer les résultats (optionnel)
     */
    public function getGroups(?string $etabUai = null): DeviceGroupsResult
    {
        try {
            $config = app(\App\Config\SambaEduConfig::class);
            $ldapConfig = $config->ldap();
            $baseDn = $ldapConfig->baseDn;

            // Construire le préfixe d'établissement si un code UAI valide est fourni
            $etabPrefix = '';
            if (!empty($etabUai) && $etabUai !== '0' && preg_match('/^[0-9]{7}[a-zA-Z]$/i', $etabUai)) {
                $etabPrefix = 'OU=' . $etabUai . ',';
            }

            // DN pour les ordinateurs (DeviceGroup/OU)
            $computersDn = $etabPrefix . $ldapConfig->computersRdn . ',' . $baseDn;

            Log::debug('[ParcService] Recherche groupes (salles uniquement)', [
                'etabUai' => $etabUai,
                'computersDn' => $computersDn
            ]);

            $groups = DeviceGroupModel::in($computersDn)->limit(500)->get();

            $rootGroup = null;
            $result = [];

            foreach ($groups as $group) {
                $groupDn = $group->getDn();
                $isRootGroup = strcasecmp($groupDn, $computersDn) === 0;

                if ($isRootGroup) {
                    $rootGroup = $group;
                } else {
                    $result[] = $group;
                }
            }

            return new DeviceGroupsResult($rootGroup, $result);
        } catch (\Exception $e) {
            Log::error('[ParcService] Erreur récupération groupes', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new DeviceGroupsResult(null, []);
        }
    }

    /**
     * Récupère tous les parcs sous forme de DeviceGroupCollection
     * 
     * @param string|null $etabUai Code UAI de l'établissement pour filtrer les résultats (optionnel)
     * @return DeviceGroupCollection
     * @deprecated Utiliser getGroups() à la place
     */
    public function getAllParcs(?string $etabUai = null): DeviceGroupCollection
    {
        try {
            // Limiter à 1000 parcs pour éviter les timeouts
            $ldapParcs = $this->workstationGroupRepository->all(limit: 1000);

            // Normaliser le code UAI pour la comparaison
            $etabUaiNormalized = $etabUai ? strtolower($etabUai) : null;

            // Convertir les modèles LDAP en Types Parc
            $parcs = [];
            foreach ($ldapParcs as $ldapParc) {
                try {
                    $parcObject = $ldapParc->toBusinessObject();

                    // Filtrer par établissement si spécifié
                    if ($etabUaiNormalized) {
                        $parcEtab = $parcObject->etab ? strtolower($parcObject->etab) : null;

                        // Ne garder que les parcs du collège de l'utilisateur courant
                        if ($parcEtab !== $etabUaiNormalized) {
                            continue;
                        }
                    }

                    $parcs[] = $parcObject;
                } catch (\Exception $e) {
                    Log::warning('Erreur lors de la conversion d\'un parc', [
                        'parc_dn' => $ldapParc->getDn(),
                        'error' => $e->getMessage()
                    ]);
                    // Continuer avec les autres parcs
                }
            }

            return new DeviceGroupCollection($parcs);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des parcs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère un parc par son ID (CN ou samAccountName)
     * 
     * @param string $parcId
     * @return Parc|null
     */
    public function getParcById(string $parcId): ?Parc
    {
        try {
            // Essayer d'abord par nom
            $ldapParc = $this->workstationGroupRepository->findByName($parcId);

            // Si non trouvé, essayer par samAccountName
            if (!$ldapParc) {
                $ldapParc = $this->workstationGroupRepository->findBySamAccountName($parcId);
            }

            if ($ldapParc) {
                return $ldapParc->toBusinessObject();
            }

            // Recherche récursive dans tous les parcs (pour les enfants)
            $allParcs = $this->getAllParcs();
            return $this->findParcRecursively($allParcs, $parcId);

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération du parc', [
                'parc_id' => $parcId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupère un DeviceGroup (OU/Salle) par son nom avec toutes ses informations
     * 
     * @param string $groupName Nom du groupe (OU)
     * @return array|null Tableau avec 'group', 'parent', 'children', 'machines', 'tags'
     */
    public function getDeviceGroupDetails(string $groupName): ?array
    {
        try {
            // Rechercher le DeviceGroup par son nom (ou)
            $baseDn = DeviceGroupModel::baseDn();
            $deviceGroup = DeviceGroupModel::in($baseDn)
                ->where('ou', '=', $groupName)
                ->first();

            // Si non trouvé, essayer de chercher dans OU=computers (niveau supérieur)
            // pour les codes UAI d'établissement
            if (!$deviceGroup) {
                $computersDn = "OU=computers," . config('ldap.connections.default.base_dn');
                $deviceGroup = DeviceGroupModel::in($computersDn)
                    ->where('ou', '=', $groupName)
                    ->first();

                Log::debug('[ParcService] Recherche établissement dans computers', [
                    'groupName' => $groupName,
                    'computersDn' => $computersDn,
                    'found' => $deviceGroup !== null
                ]);
            }

            // Dernier recours: construire le DN directement
            if (!$deviceGroup) {
                $computersDnFallback = $computersDn ?? "OU=computers," . config('ldap.connections.default.base_dn');
                $directDn = "OU={$groupName},{$computersDnFallback}";
                try {
                    $deviceGroup = DeviceGroupModel::find($directDn);
                    Log::debug('[ParcService] Recherche par DN direct', [
                        'directDn' => $directDn,
                        'found' => $deviceGroup !== null
                    ]);
                } catch (\Exception $e) {
                    Log::debug('[ParcService] DN direct non trouvé', ['dn' => $directDn]);
                }
            }

            if (!$deviceGroup) {
                return null;
            }

            // Convertir le DeviceGroup en Parc (infos de base uniquement)
            $groupParc = $deviceGroup->toBusinessObject();

            // Récupérer le parent (léger, une seule requête)
            $parent = $deviceGroup->parentGroup();
            $parentParc = $parent ? $parent->toBusinessObject() : null;

            // NE PAS charger les enfants et machines ici - sera fait en lazy loading
            // Retourner uniquement les infos de base
            return [
                'group' => $groupParc,
                'groupModel' => $deviceGroup,
                'parent' => $parentParc,
                'children' => [], // Chargé séparément via getDeviceGroupChildren()
                'machines' => [], // Chargé séparément via getDeviceGroupMachines()
                'tags' => [],     // Chargé avec les machines
                'associatedTag' => null,
            ];

        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des détails du DeviceGroup', [
                'group_name' => $groupName,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return null;
        }
    }

    /**
     * Récupère les enfants d'un DeviceGroup (lazy loading)
     */
    public function getDeviceGroupChildren(string $groupName, int $limit = 50): array
    {
        try {
            $baseDn = DeviceGroupModel::baseDn();
            $deviceGroup = DeviceGroupModel::in($baseDn)
                ->where('ou', '=', $groupName)
                ->first();

            if (!$deviceGroup) {
                $computersDn = "OU=computers," . config('ldap.connections.default.base_dn');
                $deviceGroup = DeviceGroupModel::in($computersDn)
                    ->where('ou', '=', $groupName)
                    ->first();
            }

            if (!$deviceGroup) {
                return [];
            }

            // Récupérer les enfants avec limite
            $children = DeviceGroupModel::in($deviceGroup->getDn())
                ->limit($limit)
                ->get();

            $childrenParcs = [];
            foreach ($children as $child) {
                // Exclure l'OU courante
                if (strcasecmp($child->getDn(), $deviceGroup->getDn()) === 0) {
                    continue;
                }
                try {
                    $childrenParcs[] = $child->toBusinessObject()->toArray();
                } catch (\Exception $e) {
                    continue;
                }
            }

            return $childrenParcs;
        } catch (\Exception $e) {
            Log::error('Erreur chargement enfants', ['group' => $groupName, 'error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Récupère les machines d'un DeviceGroup (lazy loading)
     */
    public function getDeviceGroupMachines(string $groupName, int $limit = 50): array
    {
        try {
            $baseDn = DeviceGroupModel::baseDn();
            $deviceGroup = DeviceGroupModel::in($baseDn)
                ->where('ou', '=', $groupName)
                ->first();

            if (!$deviceGroup) {
                $computersDn = "OU=computers," . config('ldap.connections.default.base_dn');
                $deviceGroup = DeviceGroupModel::in($computersDn)
                    ->where('ou', '=', $groupName)
                    ->first();
            }

            if (!$deviceGroup) {
                return ['machines' => [], 'tags' => []];
            }

            $machines = MachineModel::in($deviceGroup->getDn())
                ->limit($limit)
                ->get();

            $machinesData = [];
            $allTags = [];
            $baseDnParcs = DeviceGroupTagModel::baseDn();

            foreach ($machines as $machine) {
                try {
                    $machineData = [
                        'cn' => $machine->getMachineName(),
                        'hostname' => $machine->getHostname(),
                        'ip_address' => $machine->getIpAddress(),
                        'mac_address' => $machine->getMacAddress(),
                        'operating_system' => $machine->getOperatingSystem(),
                        'description' => $machine->getDescription(),
                        'is_active' => $machine->isActive(),
                        'dn' => $machine->getDn(),
                        'tags' => []
                    ];

                    // Extraire les tags depuis memberof
                    $memberOf = $machine->getAttribute('memberof', []);
                    if (!is_array($memberOf)) {
                        $memberOf = [$memberOf];
                    }
                    if (isset($memberOf['count'])) {
                        unset($memberOf['count']);
                    }

                    foreach ($memberOf as $dn) {
                        if (!is_string($dn))
                            continue;
                        if (stripos($dn, $baseDnParcs) !== false) {
                            if (preg_match('/^CN=([^,]+),/i', $dn, $matches)) {
                                $tagName = $matches[1];
                                $machineData['tags'][] = $tagName;
                                if (!isset($allTags[$tagName])) {
                                    $allTags[$tagName] = $tagName;
                                }
                            }
                        }
                    }

                    $machinesData[] = $machineData;
                } catch (\Exception $e) {
                    continue;
                }
            }

            return [
                'machines' => $machinesData,
                'tags' => array_values($allTags)
            ];
        } catch (\Exception $e) {
            Log::error('Erreur chargement machines', ['group' => $groupName, 'error' => $e->getMessage()]);
            return ['machines' => [], 'tags' => []];
        }
    }

    /**
     * Recherche récursive d'un parc dans une collection
     */
    private function findParcRecursively($parcs, string $parcId): ?Parc
    {
        foreach ($parcs as $parc) {
            if ($parc->cn === $parcId || $parc->samAccountName === $parcId) {
                return $parc;
            }

            // Recherche dans les enfants
            if ($parc->children && count($parc->children) > 0) {
                $found = $this->findParcRecursively($parc->children, $parcId);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Crée un nouveau groupe (OU dans ou=computers)
     * 
     * Un groupe est une OrganizationalUnit dans l'arborescence des Computers.
     * C'est l'équivalent d'une "salle" dans le legacy.
     * Note: La notion de "parc" (groupe LDAP dans ou=Parcs) n'est plus utilisée.
     * 
     * @param array $data Données du groupe:
     *   - name: string (requis) Nom du groupe (caractères alphanumériques minuscules + underscore)
     *   - description: string (optionnel) Description du groupe
     *   - parent_id: string|null (optionnel) Nom du groupe parent (OU parente)
     *   - etab: string (optionnel) Code UAI de l'établissement
     * @return array{success: bool, message: string, group_name?: string, dn?: string}
     */
    public function createGroup(array $data): array
    {
        try {
            $name = $data['name'] ?? '';
            $description = $data['description'] ?? '';
            $parentId = $data['parent_id'] ?? null;
            $etabCode = $data['etab'] ?? null;

            // Validation du nom
            if (empty($name)) {
                return [
                    'success' => false,
                    'message' => 'Le nom du groupe ne doit pas être vide.'
                ];
            }

            // Normaliser le nom en minuscules
            $name = strtolower($name);

            // Valider les caractères (alphanumériques minuscules + underscore uniquement)
            if (strlen(preg_replace('/[0-9a-z_]/', '', $name)) !== 0) {
                return [
                    'success' => false,
                    'message' => 'Le nom du groupe ne doit contenir que des caractères alphanumériques minuscules et le caractère underscore (_).'
                ];
            }

            // Construire le DN de base pour les computers
            $config = app(\App\Config\SambaEduConfig::class);
            $ldapConfig = $config->ldap();
            $baseDn = $ldapConfig->baseDn;

            // Construire le préfixe d'établissement si un code UAI valide est fourni
            $etabPrefix = '';
            if (!empty($etabCode) && $etabCode !== '0' && preg_match('/^[0-9]{7}[a-zA-Z]$/i', $etabCode)) {
                $etabPrefix = 'OU=' . $etabCode . ',';
            }

            // DN de base des computers pour cet établissement
            $computersDn = $etabPrefix . $ldapConfig->computersRdn . ',' . $baseDn;

            // Déterminer le DN parent
            if (!empty($parentId)) {
                // Chercher le groupe parent
                $parentGroup = DeviceGroupModel::in($computersDn)
                    ->where('ou', '=', $parentId)
                    ->first();

                if (!$parentGroup) {
                    return [
                        'success' => false,
                        'message' => "Le groupe parent '$parentId' n'existe pas."
                    ];
                }

                $parentDn = $parentGroup->getDn();
            } else {
                // Pas de parent, créer directement sous computers
                $parentDn = $computersDn;
            }

            // Construire le DN du nouveau groupe
            $newDn = 'OU=' . $name . ',' . $parentDn;

            // Vérifier si le groupe existe déjà
            $existingGroup = DeviceGroupModel::find($newDn);
            if ($existingGroup) {
                return [
                    'success' => false,
                    'message' => "Un groupe avec le nom '$name' existe déjà à cet emplacement."
                ];
            }

            // Créer l'OU via LdapRecord
            $newGroup = new DeviceGroupModel();
            $newGroup->setDn($newDn);
            $newGroup->ou = $name;

            if (!empty($description)) {
                $newGroup->description = $description;
            }

            $newGroup->save();

            Log::info('Groupe créé avec succès', [
                'name' => $name,
                'dn' => $newDn,
                'parent_dn' => $parentDn
            ]);

            // TODO: Créer également le profil d'applications associé via AppProfileService
            // quand la synchronisation AD sera implémentée

            return [
                'success' => true,
                'message' => "Groupe '$name' créé avec succès.",
                'group_name' => $name,
                'dn' => $newDn
            ];

        } catch (\LdapRecord\LdapRecordException $e) {
            Log::error('Erreur LDAP lors de la création du groupe', [
                'data' => $data,
                'error' => $e->getMessage(),
                'detailed_error' => $e->getDetailedError()?->getErrorMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Erreur LDAP: ' . ($e->getDetailedError()?->getErrorMessage() ?? $e->getMessage())
            ];
        } catch (\Exception $e) {
            Log::error('Erreur lors de la création du groupe', [
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'Erreur lors de la création: ' . $e->getMessage()
            ];
        }
    }

    /**
     * @deprecated Utiliser createGroup() à la place
     * @param array $data
     * @return bool
     */
    public function createParc(array $data): bool
    {
        $result = $this->createGroup($data);
        return $result['success'];
    }

    /**
     * Met à jour un groupe (OU dans ou=computers)
     * 
     * @param string $groupName Nom actuel du groupe
     * @param array $data Données à mettre à jour:
     *   - name: string (optionnel) Nouveau nom du groupe
     *   - description: string (optionnel) Nouvelle description
     * @return array{success: bool, message: string, new_name?: string}
     * 
     * @todo RENOMMAGE COMPLET - Implémenter les étapes suivantes (cf. legacy rename_parc.php):
     * 
     * Actuellement, seule l'OU est renommée. Pour un renommage complet, il faudra :
     * 
     * 1. GESTION DES MACHINES (si le groupe contient des machines)
     *    - Lister toutes les machines du groupe via list_members_parc()
     *    - Créer une OU temporaire pour stocker les machines
     *    - Déplacer les machines vers l'OU temporaire
     *    - Renommer l'OU du groupe
     *    - Remettre les machines dans l'OU renommée
     *    - Supprimer l'OU temporaire
     *    @see rename_salle() dans includes/ldap.inc.php lignes 3674-3741
     * 
     * 2. GESTION DES SOUS-GROUPES (si le groupe a des enfants)
     *    - Récupérer récursivement tous les sous-groupes via list_salle_childrens()
     *    - Appliquer la même logique de déplacement temporaire pour chaque niveau
     *    - Traiter du niveau le plus profond vers la racine
     * 
     * 3. SYNCHRONISATION DU GROUPE LDAP DANS ou=Parcs (profil applicatif)
     *    - Chercher le groupe LDAP correspondant dans ou=Parcs (DeviceGroupTagModel)
     *    - Modifier son samaccountname: $newName . $config['suffix']
     *    - Renommer son CN: move_ad vers "CN=$newName,ou=Parcs,..."
     *    @see rename_parc() dans includes/ldap.inc.php lignes 3837-3850
     * 
     * 4. MISE À JOUR DES RÉFÉRENCES
     *    - Mettre à jour les références dans les GPO si applicable
     *    - Mettre à jour les références dans les profils WPKG si applicable
     * 
     * Prérequis pour implémenter :
     *    - Fonctionnalité d'ajout de machines aux groupes
     *    - Fonctionnalité d'association de profils applicatifs (ou=Parcs)
     */
    public function updateGroup(string $groupName, array $data): array
    {
        try {
            $newName = $data['name'] ?? null;
            $description = $data['description'] ?? null;

            // Récupérer le groupe actuel
            $group = DeviceGroupModel::query()
                ->where('ou', '=', $groupName)
                ->first();

            if (!$group) {
                return [
                    'success' => false,
                    'message' => "Le groupe '$groupName' n'existe pas."
                ];
            }

            $currentDn = $group->getDn();
            $hasChanges = false;

            // Mise à jour de la description
            if ($description !== null && $description !== ($group->description[0] ?? '')) {
                $group->description = $description ?: null;
                $hasChanges = true;
            }

            // Renommage du groupe (si le nom change)
            if ($newName && strtolower($newName) !== strtolower($groupName)) {
                // Valider le nouveau nom
                $newName = strtolower($newName);
                if (strlen(preg_replace('/[0-9a-z_]/', '', $newName)) !== 0) {
                    return [
                        'success' => false,
                        'message' => 'Le nom du groupe ne doit contenir que des caractères alphanumériques minuscules et le caractère underscore (_).'
                    ];
                }

                // Vérifier que le nouveau nom n'existe pas déjà au même niveau
                $parentDn = preg_replace('/^OU=[^,]+,/', '', $currentDn);
                $newDn = 'OU=' . $newName . ',' . $parentDn;

                $existingGroup = DeviceGroupModel::find($newDn);
                if ($existingGroup) {
                    return [
                        'success' => false,
                        'message' => "Un groupe avec le nom '$newName' existe déjà à cet emplacement."
                    ];
                }

                // TODO: Vérifier si le groupe contient des machines
                // $machines = $this->getDeviceGroupMachines($groupName);
                // if (!empty($machines['machines'])) {
                //     // Implémenter la logique de déplacement temporaire des machines
                //     // Voir rename_salle() dans le legacy
                // }

                // TODO: Vérifier si le groupe a des sous-groupes
                // $children = $this->getDeviceGroupChildren($groupName);
                // if (!empty($children)) {
                //     // Implémenter la logique récursive pour les sous-groupes
                // }

                // TODO: Synchroniser le groupe LDAP dans ou=Parcs si existant
                // $parcTag = DeviceGroupTagModel::query()
                //     ->where('cn', '=', $groupName)
                //     ->first();
                // if ($parcTag) {
                //     // Modifier samaccountname et renommer CN
                // }

                // Sauvegarder d'abord la description si elle a changé
                if ($hasChanges) {
                    $group->save();
                }

                // Renommer l'OU (via rename)
                // Note: Fonctionne uniquement si l'OU est vide (pas de machines ni sous-groupes)
                $group->rename('OU=' . $newName);

                Log::info('Groupe renommé avec succès', [
                    'old_name' => $groupName,
                    'new_name' => $newName,
                    'old_dn' => $currentDn,
                    'new_dn' => $newDn
                ]);

                return [
                    'success' => true,
                    'message' => "Groupe renommé de '$groupName' à '$newName' avec succès.",
                    'new_name' => $newName
                ];
            }

            // Sauvegarder les modifications (description uniquement)
            if ($hasChanges) {
                $group->save();

                Log::info('Groupe mis à jour avec succès', [
                    'name' => $groupName,
                    'changes' => $data
                ]);

                return [
                    'success' => true,
                    'message' => "Groupe '$groupName' mis à jour avec succès."
                ];
            }

            return [
                'success' => true,
                'message' => 'Aucune modification à effectuer.'
            ];

        } catch (\LdapRecord\LdapRecordException $e) {
            Log::error('Erreur LDAP lors de la mise à jour du groupe', [
                'group_name' => $groupName,
                'data' => $data,
                'error' => $e->getMessage(),
                'detailed_error' => $e->getDetailedError()?->getErrorMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Erreur LDAP: ' . ($e->getDetailedError()?->getErrorMessage() ?? $e->getMessage())
            ];
        } catch (\Exception $e) {
            Log::error('Erreur lors de la mise à jour du groupe', [
                'group_name' => $groupName,
                'data' => $data,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'message' => 'Erreur lors de la mise à jour: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Supprime un parc
     * 
     * @param string $parcId
     * @return bool
     */
    public function deleteParc(string $parcId): bool
    {
        try {
            // TODO: Implémenter la suppression avec LdapRecord
            Log::info('Suppression de parc demandée', ['parc_id' => $parcId]);
            return false;
        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du parc', [
                'parc_id' => $parcId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Récupère la hiérarchie des parcs
     * 
     * @return DeviceGroupCollection
     */
    public function getParcHierarchy(): DeviceGroupCollection
    {
        try {
            $parcs = $this->getAllParcs();
            return $parcs->buildHierarchy();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la construction de la hiérarchie', [
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère les parcs racines (niveau supérieur)
     * 
     * @return DeviceGroupCollection
     */
    public function getRootParcs(): DeviceGroupCollection
    {
        try {
            return $this->getAllParcs()->getRoots();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des parcs racines', [
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Recherche des parcs par nom
     * 
     * @param string $query
     * @return DeviceGroupCollection
     */
    public function searchParcs(string $query): DeviceGroupCollection
    {
        try {
            $ldapParcs = $this->workstationGroupRepository->search($query, limit: 100);

            $parcs = $ldapParcs->map(function ($ldapParc) {
                return $ldapParc->toBusinessObject();
            })->toArray();

            return new DeviceGroupCollection($parcs);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la recherche de parcs', [
                'query' => $query,
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère les statistiques globales
     * 
     * @return array
     */
    public function getGlobalStats(): array
    {
        try {
            $parcs = $this->getAllParcs();
            $parcStats = $parcs->getStats();

            // Ajouter les statistiques de machines
            $machineStats = $this->calculateMachineStats();

            return array_merge($parcStats, $machineStats);
        } catch (\Exception $e) {
            Log::error('Erreur lors du calcul des statistiques globales', [
                'error' => $e->getMessage()
            ]);
            return [
                'total_parcs' => 0,
                'buildings' => 0,
                'rooms' => 0,
                'labs' => 0,
                'active_parcs' => 0,
                'inactive_parcs' => 0,
                'total_machines' => 0,
                'machines_on' => 0,
                'machines_off' => 0,
                'machines_login' => 0,
                'connected_users' => 0
            ];
        }
    }

    /**
     * Récupère les parcs par type
     * 
     * @param string $type
     * @return DeviceGroupCollection
     */
    public function getParcsByType(string $type): DeviceGroupCollection
    {
        try {
            return $this->getAllParcs()->ofType($type);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des parcs par type', [
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère les parcs par établissement
     * 
     * @param string $etab
     * @return DeviceGroupCollection
     */
    public function getParcsByEtab(string $etab): DeviceGroupCollection
    {
        try {
            return $this->getAllParcs()->filterByEtab($etab);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des parcs par établissement', [
                'etab' => $etab,
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère les parcs actifs uniquement
     * 
     * @return DeviceGroupCollection
     */
    public function getActiveParcs(): DeviceGroupCollection
    {
        try {
            return $this->getAllParcs()->onlyActive();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des parcs actifs', [
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère les parcs qui peuvent contenir des enfants
     * 
     * @return DeviceGroupCollection
     */
    /**
     * Récupère les parcs capables d'avoir des enfants (parents)
     * 
     * @param string|null $etabUai Code UAI de l'établissement pour filtrer les résultats (optionnel)
     * @return DeviceGroupCollection
     */
    public function getParentCapableParcs(?string $etabUai = null): DeviceGroupCollection
    {
        try {
            return $this->getAllParcs($etabUai)->canHaveChildren()->sortByName();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des parcs parents', [
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère les parcs feuilles (terminaux)
     * 
     * @return DeviceGroupCollection
     */
    public function getLeafParcs(): DeviceGroupCollection
    {
        try {
            return $this->getAllParcs()->leaves()->sortByName();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des parcs feuilles', [
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère les enfants d'un parc
     * 
     * @param Parc $parent
     * @return DeviceGroupCollection
     */
    public function getChildrenParcs(Parc $parent): DeviceGroupCollection
    {
        try {
            return $this->getAllParcs()->getChildren($parent);
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des enfants du parc', [
                'parent' => $parent->cn,
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Calcule les statistiques globales des machines
     * 
     * @return array
     */
    private function calculateMachineStats(): array
    {
        try {
            $stats = [
                'total_machines' => 0,
                'machines_on' => 0,
                'machines_off' => 0,
                'machines_login' => 0,
                'connected_users' => 0
            ];

            // Récupère tous les parcs et calcule les stats machine
            $parcs = $this->getAllParcs();
            foreach ($parcs as $parc) {
                $parcStats = $this->getParcStats($parc->getId());
                $stats['total_machines'] += $parcStats['total_machines'] ?? 0;
                $stats['machines_on'] += $parcStats['machines_on'] ?? 0;
                $stats['machines_off'] += $parcStats['machines_off'] ?? 0;
                $stats['machines_login'] += $parcStats['machines_login'] ?? 0;
                $stats['connected_users'] += $parcStats['connected_users'] ?? 0;
            }

            return $stats;
        } catch (\Exception $e) {
            Log::error('Erreur lors du calcul des statistiques machines', [
                'error' => $e->getMessage()
            ]);
            return [
                'total_machines' => 0,
                'machines_on' => 0,
                'machines_off' => 0,
                'machines_login' => 0,
                'connected_users' => 0
            ];
        }
    }

    /**
     * Récupère les statistiques détaillées par type
     * 
     * @return array
     */
    public function getDetailedStatsByType(): array
    {
        try {
            $parcs = $this->getAllParcs();
            $statsByType = [];

            foreach (['building', 'room', 'lab'] as $type) {
                $typeParcs = $parcs->ofType($type);
                $statsByType[$type] = [
                    'count' => $typeParcs->count(),
                    'active' => $typeParcs->onlyActive()->count(),
                    'parcs' => $typeParcs->getDisplayNames()
                ];
            }

            return $statsByType;
        } catch (\Exception $e) {
            Log::error('Erreur lors du calcul des statistiques détaillées', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère les statistiques par établissement
     * 
     * @return array
     */
    public function getStatsByEtab(): array
    {
        try {
            return $this->getAllParcs()->countByEtab();
        } catch (\Exception $e) {
            Log::error('Erreur lors du calcul des statistiques par établissement', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Vérifie si un parc existe
     * 
     * @param string $parcId
     * @return bool
     */
    public function parcExists(string $parcId): bool
    {
        try {
            return $this->getAllParcs()->hasCn($parcId) ||
                $this->getAllParcs()->findById($parcId) !== null;
        } catch (\Exception $e) {
            Log::error('Erreur lors de la vérification d\'existence du parc', [
                'parc_id' => $parcId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Récupère plusieurs parcs par leurs IDs
     * 
     * @param array $parcIds
     * @return DeviceGroupCollection
     */
    public function getParcsByIds(array $parcIds): DeviceGroupCollection
    {
        try {
            $parcs = $this->getAllParcs();
            $found = new DeviceGroupCollection([]);

            foreach ($parcIds as $id) {
                $parc = $parcs->findById($id);
                if ($parc) {
                    $found->push($parc);
                }
            }

            return $found;
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des parcs par IDs', [
                'ids' => $parcIds,
                'error' => $e->getMessage()
            ]);
            return new DeviceGroupCollection([]);
        }
    }

    /**
     * Récupère les noms d'affichage de tous les parcs
     * 
     * @return array
     */
    public function getAllParcNames(): array
    {
        try {
            return $this->getAllParcs()->getDisplayNames();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la récupération des noms de parcs', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère la hiérarchie complète avec compteurs
     * 
     * @return array
     */
    public function getHierarchyWithCounts(): array
    {
        try {
            $parcs = $this->getAllParcs();
            $hierarchy = $parcs->buildHierarchy();

            // Ajouter les compteurs pour chaque parc
            return $hierarchy->map(function (Parc $parc) use ($parcs) {
                $parcData = $parc->toArray();
                $children = $parcs->getChildren($parc);
                $parcData['children_count'] = $children->count();
                $parcData['descendants_count'] = $parcs->getDescendants($parc)->count();
                $parcData['machine_stats'] = $this->getParcStats($parc->getId());
                return $parcData;
            })->toArray();
        } catch (\Exception $e) {
            Log::error('Erreur lors de la construction de la hiérarchie avec compteurs', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère les statistiques détaillées d'un parc spécifique
     * 
     * @param string $parcId
     * @return array
     */
    public function getParcStats(string $parcId): array
    {
        try {
            // Récupération du parc
            $parc = $this->getParcById($parcId);
            if (!$parc) {
                return [
                    'total_machines' => 0,
                    'machines_on' => 0,
                    'machines_off' => 0,
                    'machines_login' => 0,
                    'connected_users' => 0,
                    'machines_unknown' => 0
                ];
            }

            // Récupération des machines du parc via le repository
            $machines = $this->workstationRepository->findByParc($parc->cn);

            $stats = [
                'total_machines' => $machines->count(),
                'machines_on' => 0,
                'machines_off' => 0,
                'machines_login' => 0,
                'connected_users' => 0,
                'machines_unknown' => 0
            ];

            // Pour chaque machine, déterminer le statut
            // Note: Le statut réel nécessite des appels système (WOL, ping, etc.)
            // Pour l'instant, on utilise uniquement le statut LDAP
            foreach ($machines as $machine) {
                $status = $machine->status; // 'active' ou 'disabled' depuis l'accesseur sémantique

                if ($status === 'active') {
                    $stats['machines_on']++;
                } else {
                    $stats['machines_off']++;
                }
            }

            return $stats;

        } catch (\Exception $e) {
            Log::error('Erreur lors du calcul des statistiques du parc', [
                'parc_id' => $parcId,
                'error' => $e->getMessage()
            ]);

            return [
                'total_machines' => 0,
                'machines_on' => 0,
                'machines_off' => 0,
                'machines_login' => 0,
                'connected_users' => 0,
                'machines_unknown' => 0
            ];
        }
    }

    // ========================================================================
    // GESTION DES FONDS D'ÉCRAN
    // ========================================================================

    /**
     * Répertoire de stockage des fonds d'écran
     */
    private const WALLPAPER_DIR = '/etc/sambaedu/applications/wallpaper';

    /**
     * Récupère les informations sur les fonds d'écran d'un groupe (salle)
     * 
     * @param string $groupName Nom du groupe/salle
     * @return array{wallpaper: array|null, lockscreen: array|null}
     */
    public function getWallpaperInfo(string $groupName): array
    {
        $wallpaperPath = self::WALLPAPER_DIR . '/wallpaper@' . $groupName . '.jpg';
        $lockscreenPath = self::WALLPAPER_DIR . '/lockscreen@' . $groupName . '.jpg';

        return [
            'wallpaper' => $this->getImageInfo($wallpaperPath, 'wallpaper', $groupName),
            'lockscreen' => $this->getImageInfo($lockscreenPath, 'lockscreen', $groupName),
        ];
    }

    /**
     * Récupère les informations sur les fonds d'écran effectifs d'un groupe
     * en remontant la hiérarchie des parents si nécessaire
     * 
     * @param string $groupName Nom du groupe/salle
     * @return array{wallpaper: array|null, lockscreen: array|null, inherited_from: string|null}
     */
    public function getEffectiveWallpaperInfo(string $groupName): array
    {
        // D'abord vérifier si le groupe a ses propres wallpapers
        $info = $this->getWallpaperInfo($groupName);

        $result = [
            'wallpaper' => $info['wallpaper'],
            'lockscreen' => $info['lockscreen'],
            'wallpaper_inherited_from' => null,
            'lockscreen_inherited_from' => null,
        ];

        // Si on a déjà les deux, pas besoin de remonter
        if ($result['wallpaper'] && $result['lockscreen']) {
            return $result;
        }

        // Récupérer la hiérarchie des parents
        $group = DeviceGroupModel::query()
            ->where('ou', '=', $groupName)
            ->first();

        if (!$group) {
            return $result;
        }

        // Remonter la hiérarchie des parents
        $currentDn = $group->getDn();
        $baseDn = config('ldap.connections.default.base_dn');
        $parcsDn = 'OU=Parcs,' . $baseDn;

        while ($currentDn !== $parcsDn) {
            // Extraire le DN parent
            $parentDn = preg_replace('/^OU=[^,]+,/', '', $currentDn);

            if ($parentDn === $currentDn || $parentDn === $baseDn) {
                break;
            }

            // Extraire le nom du parent
            if (preg_match('/^OU=([^,]+),/', $parentDn, $matches)) {
                $parentName = $matches[1];
                $parentInfo = $this->getWallpaperInfo($parentName);

                // Chercher le wallpaper si pas encore trouvé
                if (!$result['wallpaper'] && $parentInfo['wallpaper']) {
                    $result['wallpaper'] = $parentInfo['wallpaper'];
                    $result['wallpaper_inherited_from'] = $parentName;
                }

                // Chercher le lockscreen si pas encore trouvé
                if (!$result['lockscreen'] && $parentInfo['lockscreen']) {
                    $result['lockscreen'] = $parentInfo['lockscreen'];
                    $result['lockscreen_inherited_from'] = $parentName;
                }

                // Si on a trouvé les deux, on peut s'arrêter
                if ($result['wallpaper'] && $result['lockscreen']) {
                    break;
                }
            }

            $currentDn = $parentDn;
        }

        return $result;
    }

    /**
     * Récupère les informations d'une image
     */
    private function getImageInfo(string $path, string $type, string $groupName): ?array
    {
        if (!file_exists($path)) {
            return null;
        }

        $stat = stat($path);
        return [
            'path' => $path,
            'type' => $type,
            'group' => $groupName,
            'exists' => true,
            'size' => $stat['size'] ?? 0,
            'modified' => $stat['mtime'] ?? null,
            'url' => route('app.parc.wallpaper.image', [
                'parc' => $groupName,
                'type' => $type,
            ]),
        ];
    }

    /**
     * Upload et traite un fond d'écran pour un groupe
     * 
     * @param string $groupName Nom du groupe/salle
     * @param UploadedFile $file Fichier uploadé
     * @param string $type Type d'image ('wallpaper' ou 'lockscreen')
     * @return array{success: bool, message: string, path?: string}
     */
    public function uploadWallpaper(string $groupName, UploadedFile $file, string $type = 'wallpaper'): array
    {
        try {
            // Valider le type
            if (!in_array($type, ['wallpaper', 'lockscreen'])) {
                return [
                    'success' => false,
                    'message' => 'Type d\'image invalide. Utilisez "wallpaper" ou "lockscreen".'
                ];
            }

            // Valider l'extension
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, $allowedExtensions)) {
                return [
                    'success' => false,
                    'message' => 'Format d\'image non supporté. Formats acceptés: ' . implode(', ', $allowedExtensions)
                ];
            }

            // Vérifier que le répertoire existe
            if (!is_dir(self::WALLPAPER_DIR)) {
                if (!mkdir(self::WALLPAPER_DIR, 0755, true)) {
                    return [
                        'success' => false,
                        'message' => 'Impossible de créer le répertoire de stockage.'
                    ];
                }
            }

            // Chemin de destination
            $destPath = self::WALLPAPER_DIR . '/' . $type . '@' . $groupName . '.jpg';

            // Récupérer le chemin réel du fichier uploadé (compatible Livewire)
            $sourcePath = $file->getRealPath();

            // Traiter l'image avec Imagick
            if (!class_exists('Imagick')) {
                // Fallback sans Imagick: copier directement si c'est un JPG
                if ($extension === 'jpg' || $extension === 'jpeg') {
                    if (copy($sourcePath, $destPath)) {
                        return [
                            'success' => true,
                            'message' => 'Image uploadée avec succès (sans redimensionnement).',
                            'path' => $destPath
                        ];
                    }
                }
                return [
                    'success' => false,
                    'message' => 'Extension Imagick non disponible pour le traitement d\'image.'
                ];
            }

            $imagick = new Imagick($sourcePath);

            // Redimensionner à 1920x1080 (Full HD)
            $imagick->resizeImage(1920, 1080, Imagick::FILTER_LANCZOS, 1, true);

            // Convertir en JPEG avec qualité optimisée
            $imagick->setImageFormat('jpg');
            $imagick->setImageCompressionQuality(85);

            // Sauvegarder
            $imagick->writeImage($destPath);
            $imagick->destroy();

            Log::info('Fond d\'écran uploadé avec succès', [
                'group' => $groupName,
                'type' => $type,
                'path' => $destPath
            ]);

            return [
                'success' => true,
                'message' => ucfirst($type) . ' uploadé et traité avec succès.',
                'path' => $destPath
            ];

        } catch (\Exception $e) {
            // Gère les erreurs Imagick et autres exceptions
            $isImagickError = str_contains(get_class($e), 'Imagick');
            $logContext = [
                'group' => $groupName,
                'type' => $type,
                'error' => $e->getMessage()
            ];

            if ($isImagickError) {
                Log::error('Erreur Imagick lors de l\'upload du fond d\'écran', $logContext);
                return [
                    'success' => false,
                    'message' => 'Erreur lors du traitement de l\'image: ' . $e->getMessage()
                ];
            }

            Log::error('Erreur lors de l\'upload du fond d\'écran', $logContext);
            return [
                'success' => false,
                'message' => 'Erreur lors de l\'upload: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Supprime un fond d'écran d'un groupe
     * 
     * @param string $groupName Nom du groupe/salle
     * @param string $type Type d'image ('wallpaper' ou 'lockscreen')
     * @return array{success: bool, message: string}
     */
    public function deleteWallpaper(string $groupName, string $type = 'wallpaper'): array
    {
        try {
            if (!in_array($type, ['wallpaper', 'lockscreen'])) {
                return [
                    'success' => false,
                    'message' => 'Type d\'image invalide.'
                ];
            }

            $path = self::WALLPAPER_DIR . '/' . $type . '@' . $groupName . '.jpg';

            if (!file_exists($path)) {
                return [
                    'success' => false,
                    'message' => 'Le fichier n\'existe pas.'
                ];
            }

            if (!unlink($path)) {
                return [
                    'success' => false,
                    'message' => 'Impossible de supprimer le fichier.'
                ];
            }

            Log::info('Fond d\'écran supprimé', [
                'group' => $groupName,
                'type' => $type,
                'path' => $path
            ]);

            return [
                'success' => true,
                'message' => ucfirst($type) . ' supprimé avec succès.'
            ];

        } catch (\Exception $e) {
            Log::error('Erreur lors de la suppression du fond d\'écran', [
                'group' => $groupName,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return [
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Récupère le contenu d'une image de fond d'écran pour affichage
     * 
     * @param string $groupName Nom du groupe/salle
     * @param string $type Type d'image ('wallpaper' ou 'lockscreen')
     * @return string|null Contenu binaire de l'image ou null si non trouvée
     */
    public function getWallpaperContent(string $groupName, string $type = 'wallpaper'): ?string
    {
        if (!in_array($type, ['wallpaper', 'lockscreen'])) {
            return null;
        }

        $path = self::WALLPAPER_DIR . '/' . $type . '@' . $groupName . '.jpg';

        if (!file_exists($path)) {
            // Essayer l'image par défaut
            $defaultPath = self::WALLPAPER_DIR . '/' . $type . '.jpg';
            if (file_exists($defaultPath)) {
                $path = $defaultPath;
            } else {
                // Image par défaut système
                $systemDefault = '/usr/share/sambaedu/applications/wallpaper/default.jpg';
                if (file_exists($systemDefault)) {
                    $path = $systemDefault;
                } else {
                    return null;
                }
            }
        }

        return file_get_contents($path);
    }

    /**
     * Génère une miniature d'un fond d'écran
     * 
     * @param string $groupName Nom du groupe/salle
     * @param string $type Type d'image ('wallpaper' ou 'lockscreen')
     * @param int $height Hauteur de la miniature
     * @return string|null Contenu binaire de la miniature PNG
     */
    public function getWallpaperThumbnail(string $groupName, string $type = 'wallpaper', int $height = 100): ?string
    {
        if (!in_array($type, ['wallpaper', 'lockscreen'])) {
            return null;
        }

        $path = self::WALLPAPER_DIR . '/' . $type . '@' . $groupName . '.jpg';
        $hasCustom = file_exists($path);

        if (!$hasCustom) {
            // Essayer l'image par défaut
            $defaultPath = self::WALLPAPER_DIR . '/' . $type . '.jpg';
            if (file_exists($defaultPath)) {
                $path = $defaultPath;
            } else {
                $systemDefault = '/usr/share/sambaedu/applications/wallpaper/default.jpg';
                if (file_exists($systemDefault)) {
                    $path = $systemDefault;
                } else {
                    return null;
                }
            }
        }

        try {
            if (!class_exists('Imagick')) {
                // Sans Imagick, retourner l'image originale
                return file_get_contents($path);
            }

            $imagick = new Imagick($path);
            $imagick->scaleImage(0, $height);
            $imagick->setImageFormat('png');

            // Ajouter un indicateur si c'est l'image par défaut
            if (!$hasCustom) {
                $defaultIndicator = '/var/www/sambaedu/elements/images/left.gif';
                if (file_exists($defaultIndicator)) {
                    $logo = new Imagick($defaultIndicator);
                    $imagick->compositeImage($logo, Imagick::COMPOSITE_OVER, 5, 5);
                    $logo->destroy();
                }
            }

            $content = $imagick->getImageBlob();
            $imagick->destroy();

            return $content;

        } catch (\Exception $e) {
            Log::warning('Erreur génération miniature', [
                'group' => $groupName,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return file_get_contents($path);
        }
    }
}
