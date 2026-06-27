<?php

namespace App\Repositories;

use App\LdapModels\SambaEduGroup;
use App\Config\LdapDnHelper;
use App\Config\SambaEduConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use LdapRecord\Container;

/**
 * Repository pour la gestion des groupes LDAP
 */
class GroupRepository
{
    private LdapDnHelper $dnHelper;
    private SambaEduConfig $config;

    public function __construct(LdapDnHelper $dnHelper, SambaEduConfig $config)
    {
        $this->dnHelper = $dnHelper;
        $this->config = $config;
    }

    /**
     * Renomme un groupe AD (modrdn) sans recréation de l'objet.
     *
     * Conserve l'objectGUID et met à jour samAccountName.
     */
    public function renameGroup(string $oldCn, string $newCn): bool
    {
        try {
            $group = SambaEduGroup::query()
                ->where('cn', '=', $oldCn)
                ->first();

            if (!$group) {
                return false;
            }

            $suffix = $this->config->get('suffix', '');

            $group->rename("CN={$newCn}");
            $group->samaccountname = $newCn . $suffix;
            $group->save();

            $this->invalidateLdapCache();

            Log::info('Groupe renommé', [
                'old_cn' => $oldCn,
                'new_cn' => $newCn,
                'dn' => $group->getDn(),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Erreur lors du renommage du groupe', [
                'old_cn' => $oldCn,
                'new_cn' => $newCn,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
    /**
     * Récupère tous les groupes d'un établissement
     * 
     * @param string|null $establishmentCode Code UAI de l'établissement
     * @param int $limit Limite de résultats
     * @return Collection Collection de groupes
     */
    public function getGroupsByEstablishment(?string $establishmentCode = null, int $limit = 1000): Collection
    {
        try {
            $query = SambaEduGroup::query();
            
            // Si un établissement est spécifié, limiter la recherche à la branche de l'établissement
            if ($establishmentCode) {
                // Construire le DN de la branche Groups de l'établissement
                $dnHelper = app(\App\Config\LdapDnHelper::class);
                $groupsDn = $dnHelper->groups();
                
                Log::debug('GroupRepository: Recherche des groupes', [
                    'establishment' => $establishmentCode,
                    'groups_dn' => $groupsDn
                ]);
                
                // Limiter explicitement la recherche à cette branche
                $query->in($groupsDn);
            }
            
            $groups = $query->select(['cn', 'description', 'distinguishedname'])->limit($limit)->get();
            
            // Extraire les noms de groupes (CN)
            return $groups->map(function ($group) {
                $cn = $group->getFirstAttribute('cn');
                $description = $group->getFirstAttribute('description');
                
                return [
                    'cn' => $cn,
                    'name' => $cn,
                    'description' => $description,
                    'dn' => $group->getDn(),
                ];
            })->filter(function ($group) {
                // Exclure les groupes système
                return !empty($group['cn']) && 
                       !in_array($group['cn'], ['Domain Admins', 'Domain Users', 'Domain Computers']);
            })->sortBy('name')->values();
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des groupes", [
                'establishment' => $establishmentCode,
                'error' => $e->getMessage()
            ]);
            return collect([]);
        }
    }

    /**
     * Extrait la partie OU relative d'un DN complet (retire le baseDn final).
     * Ex: "ou=classes,OU=0991229y,ou=Groups,dc=lab1,dc=irundo,dc=fr" → "ou=classes,OU=0991229y,ou=Groups"
     */
    private function ouFromDn(string $fullDn): string
    {
        $baseDn = $this->dnHelper->base();
        $suffix = ',' . $baseDn;
        if (str_ends_with(strtolower($fullDn), strtolower($suffix))) {
            return substr($fullDn, 0, -strlen($suffix));
        }
        return $fullDn;
    }

    private function resolvePrefixedGroupOu(string $cn): ?string
    {
        if (str_starts_with($cn, 'Classe_')) {
            return $this->ouFromDn($this->dnHelper->classes());
        }

        if (str_starts_with($cn, 'Equipe_') || str_starts_with($cn, 'PP_')) {
            return $this->ouFromDn($this->dnHelper->equipes());
        }

        if (str_starts_with($cn, 'Cours_')) {
            return $this->ouFromDn($this->dnHelper->cours());
        }

        if (str_starts_with($cn, 'Projet_')) {
            return $this->ouFromDn($this->dnHelper->projets());
        }

        if (str_starts_with($cn, 'Matiere_') && str_contains($cn, '@')) {
            return $this->ouFromDn($this->dnHelper->equipes());
        }

        if (str_starts_with($cn, 'Matiere_')) {
            return $this->ouFromDn($this->dnHelper->matieres());
        }

        return null;
    }
    
    /**
     * Récupère tous les groupes (sans filtre établissement)
     * 
     * @param int $limit Limite de résultats
     * @return Collection Collection de groupes
     */
    public function getAllGroups(int $limit = 1000): Collection
    {
        return $this->getGroupsByEstablishment(null, $limit);
    }
    
    /**
     * Recherche des groupes par nom
     * 
     * @param string $search Terme de recherche
     * @param string|null $establishmentCode Code UAI de l'établissement
     * @param int $limit Limite de résultats
     * @return Collection Collection de groupes
     */
    public function searchGroups(string $search, ?string $establishmentCode = null, int $limit = 100): Collection
    {
        try {
            $query = SambaEduGroup::query()
                ->where('cn', 'contains', $search);
            
            if ($establishmentCode) {
                // Construire le DN de la branche Groups de l'établissement
                $dnHelper = app(\App\Config\LdapDnHelper::class);
                $groupsDn = $dnHelper->groups();
                
                // Limiter explicitement la recherche à cette branche
                $query->in($groupsDn);
            }
            
            $groups = $query->select(['cn', 'description', 'distinguishedname'])->limit($limit)->get();
            
            return $groups->map(function ($group) {
                $cn = $group->getFirstAttribute('cn');
                $description = $group->getFirstAttribute('description');
                
                return [
                    'cn' => $cn,
                    'name' => $cn,
                    'description' => $description,
                    'dn' => $group->getDn(),
                ];
            })->filter(function ($group) {
                return !empty($group['cn']);
            })->sortBy('name')->values();
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de la recherche de groupes", [
                'search' => $search,
                'error' => $e->getMessage()
            ]);
            return collect([]);
        }
    }

    /**
     * Récupère tous les groupes avec le nombre de membres
     * 
     * @param string|null $establishmentCode Code UAI de l'établissement
     * @param int $limit Limite de résultats
     * @return Collection Collection de groupes avec memberCount
     */
    public function getGroupsWithMemberCount(?string $establishmentCode = null, int $limit = 1000): Collection
    {
        try {
            // Rechercher dans la branche groups de l'établissement
            $groupsDn = $this->dnHelper->groups();
            
            $groups = SambaEduGroup::query()
                ->in($groupsDn)
                ->select(['cn', 'description', 'distinguishedname', 'member', 'objectguid'])
                ->limit($limit)
                ->get();
            
            // Ajouter les matières (branche globale, comme le legacy)
            try {
                $matieresDn = $this->dnHelper->matieres();
                $matieres = SambaEduGroup::query()
                    ->in($matieresDn)
                    ->select(['cn', 'description', 'distinguishedname', 'member', 'objectguid'])
                    ->limit($limit)
                    ->get();
                $groups = $groups->concat($matieres);
            } catch (\Exception $e) {
                Log::warning("Impossible de charger les matières: " . $e->getMessage());
            }
            
            // Ajouter les groupes de droits (rights, comme le legacy)
            try {
                $rightsDn = $this->dnHelper->rights();
                $rights = SambaEduGroup::query()
                    ->in($rightsDn)
                    ->select(['cn', 'description', 'distinguishedname', 'member', 'objectguid'])
                    ->limit($limit)
                    ->get();
                $groups = $groups->concat($rights);
            } catch (\Exception $e) {
                Log::warning("Impossible de charger les groupes de droits: " . $e->getMessage());
            }
            
            // Récupérer le code établissement pour filtrer les membres
            $etabCode = $this->config->getCurrentEstablishmentCode();
            $etabPattern = $etabCode ? strtolower(',ou=' . $etabCode . ',') : null;
            
            return $groups->map(function ($group) use ($etabPattern) {
                $cn = $group->getFirstAttribute('cn');
                $description = $group->getFirstAttribute('description');
                $members = $group->getAttribute('member') ?? [];
                $dn = $group->getDn();
                
                // Déterminer la catégorie basée sur le DN
                $category = $this->getCategoryFromDn($dn, $cn);
                
                // Compter uniquement les membres de l'établissement
                $memberCount = 0;
                if (is_array($members) && $etabPattern) {
                    foreach ($members as $memberDn) {
                        if (str_contains(strtolower($memberDn), $etabPattern)) {
                            $memberCount++;
                        }
                    }
                } else {
                    $memberCount = is_array($members) ? count($members) : 0;
                }
                
                return [
                    'cn' => $cn,
                    'name' => $cn,
                    'description' => $description,
                    'dn' => $dn,
                    'objectguid' => $group->getFirstAttribute('objectguid'),
                    'memberCount' => $memberCount,
                    'category' => $category,
                ];
            })->filter(function ($group) {
                // Exclure les groupes système
                return !empty($group['cn']) && 
                       !in_array($group['cn'], ['Domain Admins', 'Domain Users', 'Domain Computers', 'Domain Guests', 'Administrators', 'Users', 'Guests']);
            })->sortByDesc('memberCount')->values();
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des groupes avec membres", [
                'establishment' => $establishmentCode,
                'error' => $e->getMessage()
            ]);
            return collect([]);
        }
    }

    /**
     * Détermine la catégorie d'un groupe basée sur son DN et son CN
     * 
     * @param string $dn DN du groupe
     * @param string $cn CN du groupe
     * @return string Catégorie du groupe
     */
    private function getCategoryFromDn(string $dn, string $cn): string
    {
        // Vérifier d'abord par le DN (plus fiable)
        $dnLower = strtolower($dn);
        
        if (str_contains($dnLower, 'ou=matieres,')) {
            return 'Matière';
        }
        if (str_contains($dnLower, 'ou=rights,')) {
            return 'Droits';
        }
        if (str_contains($dnLower, 'ou=delegations,')) {
            return 'Délégation';
        }
        if (str_contains($dnLower, 'ou=classes,')) {
            return 'Classe';
        }
        if (str_contains($dnLower, 'ou=equipes,')) {
            // Distinguer Équipe et PP par le préfixe du CN
            if (str_starts_with($cn, 'PP_')) {
                return 'PP';
            }
            return 'Équipe';
        }
        if (str_contains($dnLower, 'ou=cours,')) {
            return 'Cours';
        }
        if (str_contains($dnLower, 'ou=projets,')) {
            return 'Projet';
        }
        if (str_contains($dnLower, 'ou=autres,')) {
            return 'Autre';
        }
        
        // Fallback sur le préfixe du CN
        if (str_starts_with($cn, 'Classe_')) return 'Classe';
        if (str_starts_with($cn, 'Equipe_')) return 'Équipe';
        if (str_starts_with($cn, 'Cours_')) return 'Cours';
        if (str_starts_with($cn, 'Matiere_')) return 'Matière';
        if (str_starts_with($cn, 'Projet_')) return 'Projet';
        if (str_starts_with($cn, 'PP_')) return 'PP';
        
        return 'Autre';
    }

    /**
     * Vérifie si un groupe existe
     * 
     * @param string $cn Nom du groupe (CN)
     * @return bool
     */
    public function groupExists(string $cn): bool
    {
        try {
            $group = SambaEduGroup::query()
                ->where('cn', '=', $cn)
                ->first();
            
            return $group !== null;
        } catch (\Exception $e) {
            Log::error("Erreur lors de la vérification d'existence du groupe", [
                'cn' => $cn,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Récupère un groupe par son CN
     * 
     * @param string $cn Nom du groupe
     * @return array|null
     */
    public function getGroupByCn(string $cn): ?array
    {
        try {
            $group = SambaEduGroup::query()
                ->where('cn', '=', $cn)
                ->first();
            
            if (!$group) {
                return null;
            }

            $members = $group->getAttribute('member') ?? [];
            
            return [
                'cn' => $group->getFirstAttribute('cn'),
                'name' => $group->getFirstAttribute('cn'),
                'description' => $group->getFirstAttribute('description'),
                'dn' => $group->getDn(),
                'members' => is_array($members) ? $members : [],
                'memberCount' => is_array($members) ? count($members) : 0,
            ];
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération du groupe", [
                'cn' => $cn,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Crée un nouveau groupe (logique similaire au legacy create_group)
     * 
     * @param string $name Intitulé du groupe
     * @param string $description Description du groupe
     * @param string $type Type de groupe (classe, cours, projet, matiere, other_group)
     * @param string $prefix Préfixe optionnel
     * @return bool Succès de la création
     */
    public function createGroup(string $name, string $description, string $type = 'other_group', string $prefix = ''): bool
    {
        try {
            $connection = Container::getDefaultConnection();
            // `ldap_base_dn` peut être absent de la config brute (hôte sans
            // /etc/sambaedu/sambaedu.conf) → null casserait addGroup(string $baseDn).
            // On passe par dnHelper->base() (renvoie '' au pire), cohérent avec
            // l'usage ligne 128 de cette même classe.
            $baseDn = $this->dnHelper->base();
            $suffix = $this->config->get('suffix', '');

            // Si le nom est déjà un CN "legacy" préfixé, on crée exactement
            // ce groupe (1 SQL = 1 AD) dans la bonne OU, sans expansion.
            $prefixedOu = $this->resolvePrefixedGroupOu($name);
            if ($prefixedOu !== null) {
                return $this->addGroup($connection, $name, $prefixedOu, $description, $baseDn, $suffix);
            }
            
            // Construire le préfixe si fourni
            $prefixPart = !empty($prefix) ? $prefix . '_' : '';
            
            $results = [];
            
            if ($type === 'classe' || $type === 'equipe') {
                // Créer Classe_X
                $classeCn = 'Classe_' . $prefixPart . $name;
                $classeOu = $this->ouFromDn($this->dnHelper->classes());
                $results[] = $this->addGroup($connection, $classeCn, $classeOu, $description, $baseDn, $suffix);

                // Créer Equipe_X
                $equipeCn = 'Equipe_' . $prefixPart . $name;
                $equipeOu = $this->ouFromDn($this->dnHelper->equipes());
                $results[] = $this->addGroup($connection, $equipeCn, $equipeOu, 'Equipe pédagogique de ' . $description, $baseDn, $suffix);

                // Créer PP_X (Profs principaux)
                $ppCn = 'PP_' . $prefixPart . $name;
                $results[] = $this->addGroup($connection, $ppCn, $equipeOu, 'Profs principaux de ' . $description, $baseDn, $suffix);

            } elseif ($type === 'cours') {
                // Créer Cours_X
                $coursCn = 'Cours_' . $prefixPart . $name;
                $coursOu = $this->ouFromDn($this->dnHelper->cours());
                $results[] = $this->addGroup($connection, $coursCn, $coursOu, 'Cours de ' . $description, $baseDn, $suffix);

                // Créer Equipe_X
                $equipeCn = 'Equipe_' . $prefixPart . $name;
                $equipeOu = $this->ouFromDn($this->dnHelper->equipes());
                $results[] = $this->addGroup($connection, $equipeCn, $equipeOu, 'Equipe pédagogique de ' . $description, $baseDn, $suffix);

            } elseif ($type === 'projet') {
                // Créer Projet_X
                $projetCn = 'Projet_' . $prefixPart . $name;
                $projetOu = $this->ouFromDn($this->dnHelper->projets());
                $results[] = $this->addGroup($connection, $projetCn, $projetOu, 'Projet ' . $description, $baseDn, $suffix);

            } elseif ($type === 'matiere') {
                // Créer Matiere_X
                $matiereCn = 'Matiere_' . $prefixPart . $name;
                $matiereOu = $this->ouFromDn($this->dnHelper->matieres());
                $results[] = $this->addGroup($connection, $matiereCn, $matiereOu, $description, $baseDn, $suffix);

            } else {
                // other_group - groupe sans préfixe de catégorie
                $groupCn = ucfirst($prefixPart . $name);
                $groupOu = $this->ouFromDn($this->dnHelper->otherGroups());
                $results[] = $this->addGroup($connection, $groupCn, $groupOu, $description, $baseDn, $suffix);
            }
            
            // Invalider le cache
            $this->invalidateLdapCache();
            
            return !in_array(false, $results, true);
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de la création du groupe", [
                'name' => $name,
                'type' => $type,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Ajoute un groupe dans LDAP
     */
    private function addGroup($connection, string $cn, string $ouRdn, string $description, string $baseDn, string $suffix): bool
    {
        try {
            $samAccountName = $cn . $suffix;
            $groupDn = "cn={$cn},{$ouRdn},{$baseDn}";
            
            // Vérifier si le groupe existe déjà
            if ($this->groupExists($cn)) {
                Log::debug("Groupe déjà existant", ['cn' => $cn]);
                return true;
            }
            
            $entry = [
                'cn' => $cn,
                'objectclass' => ['top', 'group'],
                'samaccountname' => $samAccountName,
                'grouptype' => 0x80000002, // Global security group
            ];
            
            if (!empty($description)) {
                $entry['description'] = $description;
            }
            
            $result = $connection->getLdapConnection()->add($groupDn, $entry);
            
            Log::info("Groupe créé avec succès", ['cn' => $cn, 'dn' => $groupDn]);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de l'ajout du groupe", [
                'cn' => $cn,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Supprime un groupe
     * 
     * @param string $cn Nom du groupe
     * @return bool
     */
    public function deleteGroup(string $cn): bool
    {
        try {
            $group = SambaEduGroup::query()
                ->where('cn', '=', $cn)
                ->first();
            
            if (!$group) {
                return false;
            }
            
            $group->delete();
            
            // Invalider le cache
            $this->invalidateLdapCache();
            
            Log::info("Groupe supprimé", ['cn' => $cn]);
            return true;
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de la suppression du groupe", [
                'cn' => $cn,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Met à jour la description d'un groupe
     * 
     * @param string $cn Nom du groupe
     * @param string $description Nouvelle description
     * @return bool
     */
    public function updateGroupDescription(string $cn, string $description): bool
    {
        try {
            $group = SambaEduGroup::query()
                ->where('cn', '=', $cn)
                ->first();
            
            if (!$group) {
                return false;
            }
            
            $group->description = $description;
            $group->save();
            
            Log::info("Description du groupe mise à jour", ['cn' => $cn]);
            return true;
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de la mise à jour du groupe", [
                'cn' => $cn,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Ajoute un membre à un groupe
     * 
     * @param string $groupCn CN du groupe
     * @param string $memberDn DN du membre à ajouter
     * @return bool
     */
    public function addMember(string $groupCn, string $memberDn): bool
    {
        try {
            $group = SambaEduGroup::query()
                ->where('cn', '=', $groupCn)
                ->first();
            
            if (!$group) {
                return false;
            }
            
            $members = $group->getAttribute('member') ?? [];
            if (!in_array($memberDn, $members)) {
                $members[] = $memberDn;
                $group->member = $members;
                $group->save();
            }
            
            // Invalider le cache
            $this->invalidateLdapCache();
            
            Log::info("Membre ajouté au groupe", ['group' => $groupCn, 'member' => $memberDn]);
            return true;
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de l'ajout du membre", [
                'group' => $groupCn,
                'member' => $memberDn,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Supprime un membre d'un groupe
     * 
     * @param string $groupCn CN du groupe
     * @param string $memberDn DN du membre à supprimer
     * @return bool
     */
    public function removeMember(string $groupCn, string $memberDn): bool
    {
        try {
            $group = SambaEduGroup::query()
                ->where('cn', '=', $groupCn)
                ->first();
            
            if (!$group) {
                return false;
            }
            
            $members = $group->getAttribute('member') ?? [];
            $members = array_filter($members, fn($m) => $m !== $memberDn);
            $group->member = array_values($members);
            $group->save();
            
            // Invalider le cache
            $this->invalidateLdapCache();
            
            Log::info("Membre supprimé du groupe", ['group' => $groupCn, 'member' => $memberDn]);
            return true;
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de la suppression du membre", [
                'group' => $groupCn,
                'member' => $memberDn,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Récupère les membres d'un groupe avec leurs informations
     * Filtre par établissement courant
     * 
     * @param string $cn CN du groupe
     * @return Collection
     */
    public function getGroupMembers(string $cn): Collection
    {
        try {
            // Chercher d'abord dans la branche de l'établissement
            $groupsDn = $this->dnHelper->groups();
            $group = SambaEduGroup::query()
                ->in($groupsDn)
                ->where('cn', '=', $cn)
                ->first();
            
            // Si non trouvé, chercher dans les matières (globales)
            if (!$group) {
                $matieresDn = $this->dnHelper->matieres();
                $group = SambaEduGroup::query()
                    ->in($matieresDn)
                    ->where('cn', '=', $cn)
                    ->first();
            }
            
            // Si non trouvé, chercher dans les droits (globaux)
            if (!$group) {
                $rightsDn = $this->dnHelper->rights();
                $group = SambaEduGroup::query()
                    ->in($rightsDn)
                    ->where('cn', '=', $cn)
                    ->first();
            }
            
            if (!$group) {
                return collect([]);
            }
            
            $memberDns = $group->getAttribute('member') ?? [];
            
            // Filtrer par établissement courant
            $etabCode = $this->config->getCurrentEstablishmentCode();
            $etabPattern = $etabCode ? strtolower(',ou=' . $etabCode . ',') : null;
            
            if ($etabPattern) {
                $memberDns = array_filter($memberDns, function ($dn) use ($etabPattern) {
                    return str_contains(strtolower($dn), $etabPattern);
                });
            }
            
            // Récupérer les informations de chaque membre
            $members = collect($memberDns)->map(function ($memberDn) {
                try {
                    // Extraire le CN du DN
                    if (preg_match('/^cn=([^,]+)/i', $memberDn, $matches)) {
                        $memberCn = $matches[1];
                        
                        // Rechercher l'utilisateur
                        $user = \App\LdapModels\LdapUser::query()
                            ->where('cn', '=', $memberCn)
                            ->first();
                        
                        if ($user) {
                            return [
                                'cn' => $user->getFirstAttribute('cn'),
                                'dn' => $user->getDn(),
                                'displayName' => $user->getFirstAttribute('displayname') ?? $memberCn,
                                'mail' => $user->getFirstAttribute('mail'),
                            ];
                        }
                    }
                    
                    return [
                        'cn' => $memberDn,
                        'dn' => $memberDn,
                        'displayName' => $memberDn,
                        'mail' => null,
                    ];
                } catch (\Exception $e) {
                    return null;
                }
            })->filter()->values();
            
            return $members;
            
        } catch (\Exception $e) {
            Log::error("Erreur lors de la récupération des membres", [
                'cn' => $cn,
                'error' => $e->getMessage()
            ]);
            return collect([]);
        }
    }

    private function invalidateLdapCache(): void
    {
        if (function_exists('apcu_store')) {
            \call_user_func('apcu_store', 'ldap_cache_invalid', true, 60);
        }
    }
}
