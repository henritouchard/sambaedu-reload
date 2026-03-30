<?php

namespace App\Repositories;

use App\LdapModels\LdapUser;
use App\LdapModels\SambaEduGroup;
use App\Constants\Ldap\MainGroups;
use App\Types\User;
use App\Types\UserSearchCriteria;
use App\Types\UserSearchResult;
use App\Facades\SEConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Repository pour les utilisateurs
 * 
 * Masque complètement la complexité LDAP et expose uniquement des objets User (DTO)
 * 
 * IMPORTANT: Ce repository retourne TOUJOURS des objets User (App\Types\User)
 * et jamais des LdapUser directement. Cela garantit une séparation claire entre
 * la couche LDAP et la logique métier.
 * 
 * Utilise l'approche legacy : récupère uniquement les utilisateurs membres des groupes
 * Eleves, Profs ou Administratifs (inclusion) plutôt que d'exclure les comptes système
 */
class UserRepository
{
    /**
     * Durée du cache en secondes (60s comme le legacy)
     */
    private const CACHE_TTL = 60;

    /**
     * Préfixe pour les clés de cache
     */
    private const CACHE_PREFIX = 'ldap_user_';

    /**
     * Attributs LDAP minimaux à charger pour optimiser les performances
     * Liste uniquement les attributs utilisés par le DTO User
     */
    private const MINIMAL_ATTRIBUTES = [
        'cn',
        'displayname',
        'givenname',
        'sn',
        'mail',
        'telephonenumber',
        'description',
        'homedirectory',
        'profilepath',
        'scriptpath',
        'useraccountcontrol',
        'pwdlastset',
        'lastlogon',
        'whencreated',
        'whenchanged',
        'memberof',
        'distinguishedname',
    ];
    /**
     * Récupère le DN des groupes principaux (Eleves, Profs, Administratifs)
     * 
     * Utilise le modèle SambaEduGroup pour récupérer les DN depuis LDAP
     * plutôt que de les construire manuellement depuis la config
     * 
     * @return array Tableau associatif avec les DN des groupes ['Eleves' => 'CN=Eleves,...', ...]
     */
    private function getMainGroupsDn(): array
    {
        try {
            return SambaEduGroup::getAllMainGroupsDn();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Erreur lors de la récupération des DN des groupes principaux", [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère le DN du groupe établissement si un établissement est sélectionné
     * 
     * @param string|null $establishmentCode Code UAI de l'établissement (ex: '0123456A')
     * @return string|null DN du groupe établissement ou null
     */
    private function getEstablishmentGroupDn(?string $establishmentCode = null): ?string
    {
        if (empty($establishmentCode)) {
            return null;
        }

        try {
            // Utiliser LdapConfig pour construire le DN de l'établissement
            return SEConfig::ldap()->etablissementDn($establishmentCode);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Erreur lors de la récupération du DN de l'établissement", [
                'establishment' => $establishmentCode,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Recherche un utilisateur par son login
     * 
     * Utilise un cache de 60s comme le legacy pour éviter les requêtes LDAP répétitives
     * 
     * @param string $login
     * @param bool $noCache Force le bypass du cache
     * @return User|null Objet métier User ou null si non trouvé
     */
    public function findByLogin(string $login, bool $noCache = false): ?User
    {
        $cacheKey = $this->getCacheKey('login', $login);

        // Bypass du cache si demandé
        if ($noCache) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($login) {
            $ldapUser = LdapUser::findByLogin($login);
            return $ldapUser ? $ldapUser->toBusinessObject() : null;
        });
    }

    /**
     * Génère une clé de cache unique
     * Inclut l'établissement courant pour éviter les collisions multi-établissements
     */
    private function getCacheKey(string $type, string $identifier): string
    {
        $etab = SEConfig::getCurrentEstablishmentCode() ?? '0';
        return self::CACHE_PREFIX . $etab . '_' . $type . '_' . md5($identifier);
    }

    /**
     * Invalide le cache pour un utilisateur spécifique
     */
    public function invalidateCache(string $login): void
    {
        Cache::forget($this->getCacheKey('login', $login));
    }

    /**
     * Invalide tout le cache utilisateur (après une opération d'écriture LDAP)
     */
    public function invalidateAllCache(): void
    {
        // Laravel ne supporte pas la suppression par pattern nativement
        // On utilise le tag si disponible, sinon on laisse expirer naturellement
        try {
            if (method_exists(Cache::getStore(), 'flush')) {
                // Pour les drivers qui supportent flush par tag
                Cache::tags([self::CACHE_PREFIX])->flush();
            }
        } catch (\Exception $e) {
            Log::debug('Cache flush non supporté, expiration naturelle', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Recherche un utilisateur par son email
     * 
     * @param string $email
     * @return User|null
     */
    public function findByEmail(string $email): ?User
    {
        $ldapUser = LdapUser::where('mail', '=', $email)->first();

        return $ldapUser ? $ldapUser->toBusinessObject() : null;
    }

    /**
     * Recherche un utilisateur par son numéro d'employé
     * 
     * @param string $employeeNumber
     * @return User|null
     */
    public function findByEmployeeNumber(string $employeeNumber): ?User
    {
        $ldapUser = LdapUser::findByEmployeeNumber($employeeNumber);

        return $ldapUser ? $ldapUser->toBusinessObject() : null;
    }

    /**
     * Recherche un utilisateur par son externalId ENT (attribut LDAP 'title')
     */
    public function findByExternalId(string $externalId): ?User
    {
        $ldapUser = LdapUser::findByExternalId($externalId);

        return $ldapUser ? $ldapUser->toBusinessObject() : null;
    }

    /**
     * Recherche des utilisateurs par terme de recherche
     *
     * OPTIMISATION: Utilise un filtre LDAP natif combiné au lieu de 15 requêtes (3 groupes × 5 champs)
     * Format: (&(|(memberOf=DN1)(memberOf=DN2)(memberOf=DN3))(|(cn=*term*)(sn=*term*)(displayname=*term*)(givenname=*term*)(mail=*term*)))
     * 
     * @param string $searchQuery Terme de recherche
     * @param int $limit
     * @param string|null $establishmentCode Code UAI de l'établissement pour filtrer
     * @param array $groupFilters Filtres par groupes (CNs des groupes)
     * @return Collection<User> Collection de User (DTO)
     */
    public function search(string $searchQuery, int $limit = 50, ?string $establishmentCode = null, array $groupFilters = []): Collection
    {
        // Clé de cache basée sur les paramètres de recherche
        $cacheKey = self::CACHE_PREFIX . 'search_' . md5($searchQuery . '_' . ($establishmentCode ?? 'all') . '_' . $limit . '_' . implode(',', $groupFilters));

        // Vérifier le cache
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            Log::info('[UserRepository] Résultat de recherche depuis le cache', [
                'cache_key' => $cacheKey,
                'search_query' => $searchQuery,
                'users_count' => count($cached)
            ]);
            return collect($cached);
        }

        $escapedQuery = $this->escapeLdapSearch($searchQuery);
        $groupsDn = $this->getMainGroupsDn();

        if (empty($groupsDn)) {
            return collect([]);
        }

        $allUsers = collect([]);
        $seenLogins = [];
        $establishmentGroupDn = $this->getEstablishmentGroupDn($establishmentCode);

        try {
            // OPTIMISATION: Construire un filtre LDAP natif combiné
            // Filtre pour les groupes principaux: (|(memberOf=DN1)(memberOf=DN2)(memberOf=DN3))
            $groupFiltersLdap = [];
            foreach ($groupsDn as $groupDn) {
                $groupFiltersLdap[] = "(memberOf={$groupDn})";
            }
            $groupsFilter = '(|' . implode('', $groupFiltersLdap) . ')';

            // Filtre pour la recherche multi-champs: (|(cn=*term*)(sn=*term*)(displayname=*term*)(givenname=*term*)(mail=*term*))
            $searchFields = ['cn', 'sn', 'displayname', 'givenname', 'mail'];
            $searchFiltersLdap = [];
            foreach ($searchFields as $field) {
                $searchFiltersLdap[] = "({$field}=*{$escapedQuery}*)";
            }
            $searchFilter = '(|' . implode('', $searchFiltersLdap) . ')';

            // Combiner les deux filtres avec AND: (&(groupsFilter)(searchFilter))
            $combinedFilter = '(&' . $groupsFilter . $searchFilter . ')';

            $query = LdapUser::query()
                ->rawFilter($combinedFilter)
                ->select(self::MINIMAL_ATTRIBUTES); // OPTIMISATION: Limiter les attributs

            // Filtrage par établissement si spécifié
            if ($establishmentCode && $establishmentGroupDn) {
                $query->where('memberof', 'contains', $establishmentGroupDn);
            }

            $ldapUsers = $query->limit($limit * 2)->get();

            // Convertir en DTO et fusionner
            foreach ($ldapUsers as $ldapUser) {
                $user = $ldapUser->toBusinessObject();

                // Exclure les comptes système
                if (MainGroups::isSystemAccount($user->login)) {
                    continue;
                }

                // Éviter les doublons
                if (!isset($seenLogins[$user->login])) {
                    $seenLogins[$user->login] = true;
                    $allUsers->push($user);
                }
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("Erreur lors de la recherche d'utilisateurs", [
                'error' => $e->getMessage()
            ]);
        }

        // Filtrer par groupes si spécifié (utilise les données du DTO)
        if (!empty($groupFilters)) {
            $allUsers = $allUsers->filter(function (User $user) use ($groupFilters) {
                // Utiliser memberOf du DTO
                foreach ($groupFilters as $groupCn) {
                    if ($user->isMemberOf($groupCn)) {
                        return true;
                    }
                }
                return false;
            });
        }

        // Limiter au nombre demandé
        $result = $allUsers->take($limit);

        // Mettre en cache le résultat (60s comme le legacy)
        Cache::put($cacheKey, $result->toArray(), self::CACHE_TTL);
        Log::info('[UserRepository] Résultat de recherche mis en cache', [
            'cache_key' => $cacheKey,
            'ttl_seconds' => self::CACHE_TTL
        ]);

        return $result;
    }

    /**
     * Recherche un utilisateur par login et retourne le modèle LDAP
     * 
     * @internal Utiliser uniquement pour les opérations d'écriture LDAP
     * 
     * @param string $login
     * @return LdapUser|null
     */
    public function findLdapModelByLogin(string $login): ?LdapUser
    {
        return LdapUser::findByLogin($login);
    }

    /**
     * Échappe les caractères spéciaux pour la recherche LDAP
     */
    private function escapeLdapSearch(string $search): string
    {
        // Échapper les caractères spéciaux LDAP sauf * qui est utilisé pour la recherche
        return preg_replace('/([\(\)\\\\])/', '\\\\$1', $search);
    }

    /**
     * Récupère tous les utilisateurs d'un établissement
     * 
     * @param string $establishmentCode Code UAI de l'établissement
     * @param int $limit
     * @return Collection Collection de User
     */
    public function findByEstablishment(string $establishmentCode, int $limit = 1000): Collection
    {
        // Recherche dans le DN pour trouver les utilisateurs de l'établissement
        $baseDn = LdapUser::baseDn();
        $searchDn = "OU={$establishmentCode}," . $baseDn;

        $ldapUsers = LdapUser::in($searchDn)
            ->limit($limit)
            ->get();

        return $ldapUsers->map(fn($user) => $user->toBusinessObject());
    }

    /**
     * Récupère tous les utilisateurs actifs
     * 
     * @param int $limit
     * @return Collection Collection de User
     */
    public function findActive(int $limit = 1000): Collection
    {
        $ldapUsers = LdapUser::where('useraccountcontrol', '=', 512)
            ->limit($limit)
            ->get();

        return $ldapUsers->map(fn($user) => $user->toBusinessObject());
    }

    /**
     * Récupère tous les utilisateurs d'un type spécifique (élève, prof, administratif)
     * 
     * Utilise l'approche legacy : récupère directement les membres du groupe correspondant
     * Utilise le modèle SambaEduGroup pour récupérer le DN du groupe
     * 
     * Pour les Profs et Administratifs, si un établissement est spécifié, filtre également
     * par appartenance au groupe de l'établissement
     * 
     * @param string $type 'eleve', 'prof', 'administratif'
     * @param int $limit
     * @param string|null $establishmentCode Code UAI de l'établissement pour filtrer les Profs/Administratifs
     * @return Collection Collection de User
     */
    public function findByType(string $type, int $limit = 1000, ?string $establishmentCode = null): Collection
    {
        // Mapper le type vers le nom du groupe
        $groupName = match ($type) {
            'eleve' => MainGroups::ELEVES,
            'prof' => MainGroups::PROFS,
            'administratif' => MainGroups::ADMINISTRATIFS,
            default => null,
        };

        if (!$groupName) {
            return collect([]);
        }

        // Récupérer le DN du groupe depuis LDAP
        $groupDn = SambaEduGroup::getMainGroupDn($groupName);

        if (!$groupDn) {
            \Illuminate\Support\Facades\Log::warning("Groupe principal $groupName non trouvé dans LDAP");
            return collect([]);
        }

        try {
            $query = LdapUser::query()
                ->where('memberof', 'contains', $groupDn);

            // Logique legacy : filtrer par établissement
            $establishmentGroupDn = $this->getEstablishmentGroupDn($establishmentCode);
            if ($establishmentCode) {
                if ($groupName === MainGroups::ELEVES) {
                    // Élèves : filtre par OU dans le DN
                    $query->whereRaw("(distinguishedName=*,OU={$establishmentCode},*)");
                } elseif (in_array($groupName, [MainGroups::PROFS, MainGroups::ADMINISTRATIFS])) {
                    // Profs/Administratifs : filtre par groupe établissement
                    $query->where('memberof', 'contains', $establishmentGroupDn);
                }
            }

            $ldapUsers = $query->limit($limit)->get();

            // Convertir en DTO et filtrer les comptes système
            return $ldapUsers
                ->map(fn($ldapUser) => $ldapUser->toBusinessObject())
                ->filter(fn(User $user) => !MainGroups::isSystemAccount($user->login));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Erreur lors de la récupération des utilisateurs de type $type", [
                'error' => $e->getMessage()
            ]);
            return collect([]);
        }
    }

    /**
     * Recherche avancée avec filtres LDAP natifs (optimisé pour performance)
     * 
     * Pousse tous les filtres dans la requête LDAP au lieu de filtrer en PHP
     * Implémente pagination et tri côté LDAP
     * 
     * @param UserSearchCriteria $criteria
     * @return UserSearchResult
     */
    public function searchWithFilters(UserSearchCriteria $criteria): UserSearchResult
    {
        Log::debug('[UserRepository] searchWithFilters - Début', ['criteria' => $criteria]);

        $startTime = microtime(true);

        // Construire la requête de base avec les groupes principaux
        $groupsDn = $this->getMainGroupsDn();
        if (empty($groupsDn)) {
            Log::warning('[UserRepository] Aucun groupe principal trouvé');
            return $this->emptyResult($criteria);
        }

        Log::debug('[UserRepository] Groupes principaux DN', ['groupsDn' => $groupsDn]);

        // Construire tous les filtres dans un tableau pour les combiner à la fin
        $filters = [];

        // Filtre de base : membre d'un des groupes principaux
        $groupFilters = [];
        foreach ($groupsDn as $groupDn) {
            $groupFilters[] = "(memberOf={$groupDn})";
        }
        $filters[] = '(|' . implode('', $groupFilters) . ')';

        // Filtre par rôles
        if (!empty($criteria->roles) && !in_array('all', $criteria->roles)) {
            $roleFilters = [];
            foreach ($criteria->roles as $role) {
                $groupName = $this->mapRoleToGroup($role);
                Log::debug('[UserRepository] Mapping rôle -> groupe', [
                    'role' => $role,
                    'groupName' => $groupName
                ]);
                if ($groupName) {
                    $roleDn = SambaEduGroup::getMainGroupDn($groupName);
                    Log::debug('[UserRepository] DN du groupe rôle', [
                        'groupName' => $groupName,
                        'roleDn' => $roleDn
                    ]);
                    if ($roleDn) {
                        $roleFilters[] = "(memberOf={$roleDn})";
                    }
                }
            }
            if (!empty($roleFilters)) {
                $filters[] = '(|' . implode('', $roleFilters) . ')';
            }
        }

        // Filtre par recherche login
        if (!empty($criteria->loginSearch)) {
            $escapedSearch = $this->escapeLdapSearch($criteria->loginSearch);
            $filters[] = "(|(cn=*{$escapedSearch}*)(samaccountname=*{$escapedSearch}*))";
        }

        // Filtre par recherche nom/prénom
        if (!empty($criteria->nameSearch)) {
            $escapedSearch = $this->escapeLdapSearch($criteria->nameSearch);
            $filters[] = "(|(sn=*{$escapedSearch}*)(displayname=*{$escapedSearch}*)(givenname=*{$escapedSearch}*))";
        }

        // Filtre par recherche textuelle générique
        if (!empty($criteria->genericSearch)) {
            $escapedSearch = $this->escapeLdapSearch($criteria->genericSearch);
            $filters[] = "(|(cn=*{$escapedSearch}*)(sn=*{$escapedSearch}*)(displayname=*{$escapedSearch}*)(givenname=*{$escapedSearch}*)(mail=*{$escapedSearch}*)(samaccountname=*{$escapedSearch}*))";
        }

        // Combiner tous les filtres avec AND
        $combinedFilter = '(&' . implode('', $filters) . ')';
        Log::debug('[UserRepository] Filtre LDAP combiné', ['filter' => $combinedFilter]);

        // Calculer le base DN pour la recherche (OU de l'établissement courant)
        $searchBase = null;
        $establishmentCode = SEConfig::getCurrentEstablishmentCode();
        if (!empty($establishmentCode)) {
            $searchBase = "OU={$establishmentCode}," . SEConfig::ldap()->peopleRdn . ',' . SEConfig::ldap()->baseDn;
            Log::debug('[UserRepository] Recherche dans OU établissement', ['searchBase' => $searchBase]);
        }

        try {
            $query = LdapUser::query()
                ->rawFilter($combinedFilter)
                ->select(self::MINIMAL_ATTRIBUTES);

            // Restreindre la recherche à l'OU de l'établissement si spécifié
            if ($searchBase) {
                $query->in($searchBase);
            }

            // Filtre par groupes (classes, équipes, etc.)
            if (!empty($criteria->groups)) {
                $groupFiltersLdap = [];
                foreach ($criteria->groups as $groupCn) {
                    $group = SambaEduGroup::query()->where('cn', '=', $groupCn)->first();
                    if ($group) {
                        $groupFiltersLdap[] = "(memberOf={$group->getDn()})";
                    }
                }
                if (!empty($groupFiltersLdap)) {
                    $query->rawFilter('(|' . implode('', $groupFiltersLdap) . ')');
                }
            }

            // Filtre par statuts
            if (!empty($criteria->statuses) && !in_array('all', $criteria->statuses)) {
                $this->applyStatusFilters($query, $criteria->statuses);
            }

            Log::debug('[UserRepository] Exécution requête LDAP - avant get');

            // Récupérer tous les résultats
            $allLdapUsers = $query->get();

            Log::debug('[UserRepository] Résultats LDAP bruts', ['count' => count($allLdapUsers)]);

            $total = count($allLdapUsers);

            Log::debug('[UserRepository] Total trouvé', ['total' => $total]);

            // Appliquer la pagination manuellement
            $offset = ($criteria->page - 1) * $criteria->perPage;
            $ldapUsers = $allLdapUsers->slice($offset, $criteria->perPage);

            Log::debug('[UserRepository] Résultats LDAP', ['count' => count($ldapUsers)]);

            // Convertir en DTO
            $users = collect([]);
            $seenLogins = [];

            foreach ($ldapUsers as $ldapUser) {
                $user = $ldapUser->toBusinessObject();

                // Exclure les comptes système
                if (MainGroups::isSystemAccount($user->login)) {
                    continue;
                }

                // Éviter les doublons
                if (!isset($seenLogins[$user->login])) {
                    $seenLogins[$user->login] = true;
                    $users->push($user);
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000);

            Log::debug('[UserRepository] searchWithFilters exécuté', [
                'criteria' => json_encode($criteria),
                'total' => $total,
                'returned' => count($users),
                'page' => $criteria->page,
                'duration_ms' => $duration
            ]);

            return new UserSearchResult(
                users: $users,
                total: $total,
                currentPage: $criteria->page,
                perPage: $criteria->perPage,
                lastPage: (int) ceil($total / $criteria->perPage),
                from: $offset + 1,
                to: min($offset + count($users), $total),
                hasMorePages: $criteria->page < ceil($total / $criteria->perPage)
            );

        } catch (\Exception $e) {
            Log::error('[UserRepository] Erreur searchWithFilters', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return $this->emptyResult($criteria);
        }
    }

    /**
     * Mappe un rôle vers le nom du groupe LDAP
     */
    private function mapRoleToGroup(string $role): ?string
    {
        return match (strtolower($role)) {
            'eleves', 'eleve', 'élève', 'élèves' => MainGroups::ELEVES,
            'profs', 'prof', 'professeur', 'professeurs', 'enseignant', 'enseignants' => MainGroups::PROFS,
            'administratifs', 'administratif', 'admin', 'admins' => MainGroups::ADMINISTRATIFS,
            default => null,
        };
    }

    /**
     * Applique les filtres de statut à la requête
     */
    private function applyStatusFilters($query, array $statuses): void
    {
        $statusFilters = [];

        foreach ($statuses as $status) {
            switch (strtolower($status)) {
                case 'active':
                case 'actif':
                    // Compte actif : userAccountControl = 512
                    $statusFilters[] = '(userAccountControl=512)';
                    break;
                case 'inactive':
                case 'inactif':
                case 'disabled':
                    // Compte désactivé : userAccountControl = 514
                    $statusFilters[] = '(userAccountControl=514)';
                    break;
                case 'trash':
                case 'corbeille':
                    // Utilisateurs dans la corbeille (isDeleted=TRUE)
                    $statusFilters[] = '(isDeleted=TRUE)';
                    break;
            }
        }

        if (!empty($statusFilters)) {
            $query->rawFilter('(|' . implode('', $statusFilters) . ')');
        }
    }

    /**
     * Retourne un résultat vide avec structure de pagination
     */
    private function emptyResult(UserSearchCriteria $criteria): UserSearchResult
    {
        return new UserSearchResult(
            users: collect([]),
            total: 0,
            currentPage: $criteria->page,
            perPage: $criteria->perPage,
            lastPage: 1,
            from: 0,
            to: 0,
            hasMorePages: false
        );
    }
}

