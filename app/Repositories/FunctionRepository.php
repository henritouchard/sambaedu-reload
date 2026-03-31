<?php

namespace App\Repositories;

use App\Constants\Ldap\FunctionGroups;
use App\LdapModels\SambaEduGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

/**
 * Repository pour la gestion des fonctions et groupes
 * Remplace les fonctions legacy search_ad() pour les groupes
 */
class FunctionRepository
{
    /**
     * Récupère la liste des fonctions disponibles
     * Remplace search_ad() pour la récupération des fonctions
     * 
     * @param string $categorie 'Administratifs', 'Pedagogiques' ou 'all'
     * @return array
     */
    public function getAll(string $categorie = 'all'): array
    {
        try {
            // Récupérer tous les groupes de type fonction
            $groups = SambaEduGroup::query()
                ->where('objectclass', 'contains', 'group')
                ->get();

            $listFonctions = [];

            foreach ($groups as $group) {
                $cn = $group->getFirstAttribute('cn');

                if (!$cn) {
                    continue;
                }

                // Filtrer selon la catégorie
                if ($this->matchesCategory($cn, $categorie)) {
                    $listFonctions[] = $cn;
                }
            }

            // Trier par nom
            asort($listFonctions);

            return array_unique($listFonctions);

        } catch (\Exception $e) {
            Log::error('FunctionRepository getAll error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Vérifie si une fonction correspond à la catégorie spécifiée
     * 
     * @param string $functionName Nom de la fonction
     * @param string $categorie Catégorie demandée
     * @return bool
     */
    private function matchesCategory(string $functionName, string $categorie): bool
    {
        if ($categorie === 'all') {
            return true;
        }

        $allowed = FunctionGroups::forCategory($categorie);

        if (empty($allowed)) {
            return true;
        }

        return in_array($functionName, $allowed, true);
    }

    /**
     * Recherche des fonctions par nom
     * 
     * @param string $search Terme de recherche
     * @param string $categorie Catégorie de fonctions
     * @return array
     */
    public function search(string $search, string $categorie = 'all'): array
    {
        try {
            $allFunctions = $this->getAll($categorie);

            // Filtrer par terme de recherche
            $filteredFunctions = array_filter($allFunctions, function ($function) use ($search) {
                return stripos($function, $search) !== false;
            });

            return array_values($filteredFunctions);

        } catch (\Exception $e) {
            Log::error('FunctionRepository search error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Vérifie si une fonction existe
     * 
     * @param string $functionName Nom de la fonction
     * @return bool
     */
    public function exists(string $functionName): bool
    {
        try {
            return SambaEduGroup::where('cn', '=', $functionName)->exists();
        } catch (\Exception $e) {
            Log::warning("Erreur lors de la vérification de l'existence de la fonction $functionName", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Obtient les détails d'une fonction
     * 
     * @param string $functionName Nom de la fonction
     * @return array|null
     */
    public function getDetails(string $functionName): ?array
    {
        try {
            $group = SambaEduGroup::where('cn', '=', $functionName)->first();

            if (!$group) {
                return null;
            }

            // Compter les membres sans les charger en mémoire
            $members = $group->getAttribute('member', []);
            if (!is_array($members)) {
                $memberCount = $members ? 1 : 0;
            } elseif (isset($members['count'])) {
                $memberCount = (int) $members['count'];
            } else {
                $memberCount = count($members);
            }

            return [
                'cn' => $group->getFirstAttribute('cn'),
                'description' => $group->getFirstAttribute('description'),
                'dn' => $group->getDn(),
                'member_count' => $memberCount
            ];

        } catch (\Exception $e) {
            Log::error("FunctionRepository getDetails error for $functionName: " . $e->getMessage());
            return null;
        }
    }
}
