<?php

namespace App\Repositories;

use App\LdapModels\LdapRightGroup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Repository pour les groupes de droits SambaEdu
 * 
 * Masque la complexité LDAP et expose des méthodes simples pour récupérer
 * les valeurs des droits depuis la branche Rights de l'Active Directory.
 * 
 * Utilise le même pattern que les autres repositories (UserRepository, etc.)
 */
class RightRepository
{
    /**
     * Cache en mémoire pour les valeurs des droits
     */
    private static ?array $rightsValuesCache = null;

    /**
     * Durée du cache en secondes (5 minutes)
     */
    private const CACHE_TTL = 300;

    /**
     * Clé du cache Laravel
     */
    private const CACHE_KEY = 'sambaedu_rights_groups';

    /**
     * Récupère toutes les valeurs des groupes de droits
     * 
     * @return array<string, int> Mapping nom du groupe => valeur info (bitmask)
     */
    public function getAllRightsValues(): array
    {
        // Cache en mémoire pour éviter les appels multiples dans la même requête
        if (self::$rightsValuesCache !== null) {
            return self::$rightsValuesCache;
        }

        // Essayer le cache Laravel
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            self::$rightsValuesCache = $cached;
            return $cached;
        }

        // Charger depuis LDAP via le modèle
        $rightsValues = $this->fetchFromLdap();

        // Mettre en cache
        if (!empty($rightsValues)) {
            Cache::put(self::CACHE_KEY, $rightsValues, self::CACHE_TTL);
            self::$rightsValuesCache = $rightsValues;
        }

        return $rightsValues;
    }

    /**
     * Récupère la valeur d'un groupe de droits spécifique
     * 
     * @param string $groupName Nom du groupe de droits
     * @return int|null Valeur du droit ou null si non trouvé
     */
    public function getRightValue(string $groupName): ?int
    {
        $allRights = $this->getAllRightsValues();
        return $allRights[$groupName] ?? null;
    }

    /**
     * Vérifie si un groupe de droits existe
     * 
     * @param string $groupName Nom du groupe de droits
     * @return bool
     */
    public function exists(string $groupName): bool
    {
        $allRights = $this->getAllRightsValues();
        return isset($allRights[$groupName]);
    }

    /**
     * Invalide le cache des groupes de droits
     */
    public function invalidateCache(): void
    {
        self::$rightsValuesCache = null;
        Cache::forget(self::CACHE_KEY);

        Log::debug('RightRepository: Cache invalidé');
    }

    /**
     * Récupère les groupes de droits depuis LDAP
     * 
     * @return array<string, int>
     */
    private function fetchFromLdap(): array
    {
        try {
            $rightsValues = LdapRightGroup::getAllRightsValues();

            Log::debug('RightRepository: Groupes de droits chargés depuis LDAP', [
                'count' => count($rightsValues)
            ]);

            return $rightsValues;
        } catch (\Exception $e) {
            Log::warning('RightRepository: Erreur lors du chargement des groupes de droits', [
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Récupère un groupe de droits par son nom
     * 
     * @param string $groupName Nom du groupe de droits
     * @return LdapRightGroup|null
     */
    public function findByName(string $groupName): ?LdapRightGroup
    {
        try {
            return LdapRightGroup::query()
                ->where('cn', '=', $groupName)
                ->first();
        } catch (\Exception $e) {
            Log::warning('RightRepository: Erreur lors de la recherche du groupe de droits', [
                'groupName' => $groupName,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Récupère tous les groupes de droits
     * 
     * @return \Illuminate\Support\Collection<LdapRightGroup>
     */
    public function getAll(): \Illuminate\Support\Collection
    {
        try {
            return LdapRightGroup::query()->get();
        } catch (\Exception $e) {
            Log::warning('RightRepository: Erreur lors de la récupération de tous les groupes de droits', [
                'error' => $e->getMessage()
            ]);
            return collect();
        }
    }
}
