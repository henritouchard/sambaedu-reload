<?php

namespace App\Repositories;

use App\LdapModels\SambaEduGroup;
use App\Config\SambaEduConfig;
use App\Config\LdapDnHelper;
use Illuminate\Support\Facades\Log;

/**
 * Repository pour la gestion des classes
 * Remplace les fonctions legacy list_classes_etab_fast()
 */
class ClassRepository
{
    private EstablishmentRepository $establishmentRepository;
    private SambaEduConfig $configService;

    public function __construct(
        EstablishmentRepository $establishmentRepository,
        SambaEduConfig $configService
    ) {
        $this->establishmentRepository = $establishmentRepository;
        $this->configService = $configService;
    }

    /**
     * Récupère la liste des classes disponibles
     * 
     * @param string $etab Code UAI de l'établissement (0 = tous)
     * @return array
     */
    public function getAll(string $etab = '0'): array
    {
        try {
            $classes = [];

            // Récupérer le DN des classes avec préfixe d'établissement
            // LdapDnHelper inclut automatiquement OU=<etab> comme le legacy
            $classesDn = LdapDnHelper::classesDn();

            // Limiter la recherche à la branche des classes avec in()
            // Utiliser un filtre LDAP natif pour la performance (comme le legacy)
            // Ne sélectionner que l'attribut cn pour optimiser
            $groups = SambaEduGroup::query()
                ->in($classesDn)
                ->rawFilter('(&(objectclass=group)(cn=Classe_*))')
                ->select(['cn'])
                ->get();

            foreach ($groups as $group) {
                $cn = $group->getFirstAttribute('cn');

                if ($cn && strpos($cn, '_') !== false) {
                    // Extraire le nom après l'underscore (ex: "Classe_6A" -> "6A")
                    $parts = explode('_', $cn, 2);
                    $className = $parts[1] ?? null;

                    if ($className) {
                        $classes[] = $className;
                    }
                }
            }

            // Trier par nom et supprimer les doublons
            sort($classes);
            return array_unique($classes);
        } catch (\Exception $e) {
            Log::error('ClassRepository getAll error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Recherche des classes par nom
     * 
     * @param string $search Terme de recherche
     * @param string $etab Code UAI de l'établissement
     * @return array
     */
    public function search(string $search, string $etab = '0'): array
    {
        try {
            $allClasses = $this->getAll($etab);

            // Filtrer par terme de recherche
            $filteredClasses = array_filter($allClasses, function ($class) use ($search) {
                return stripos($class, $search) !== false;
            });

            return array_values($filteredClasses);

        } catch (\Exception $e) {
            Log::error('ClassRepository search error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Vérifie si une classe existe
     * 
     * @param string $className Nom de la classe
     * @return bool
     */
    public function exists(string $className): bool
    {
        try {
            return SambaEduGroup::where('cn', '=', $className)->exists();
        } catch (\Exception $e) {
            Log::warning("Erreur lors de la vérification de l'existence de la classe $className", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Obtient les détails d'une classe
     * 
     * @param string $className Nom de la classe
     * @return array|null
     */
    public function getDetails(string $className): ?array
    {
        try {
            $group = SambaEduGroup::where('cn', '=', $className)->first();

            if (!$group) {
                return null;
            }

            // Compter les membres sans les charger en mémoire
            $members = $group->getAttribute('member', []);
            if (!is_array($members)) {
                $studentCount = $members ? 1 : 0;
            } elseif (isset($members['count'])) {
                $studentCount = (int) $members['count'];
            } else {
                $studentCount = count($members);
            }

            return [
                'cn' => $group->getFirstAttribute('cn'),
                'description' => $group->getFirstAttribute('description'),
                'dn' => $group->getDn(),
                'student_count' => $studentCount,
                'establishment' => $this->extractEstablishmentFromDn($group->getDn())
            ];

        } catch (\Exception $e) {
            Log::error("ClassRepository getDetails error for $className: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Extrait le code établissement depuis le DN d'un groupe
     * 
     * @param string $dn Distinguished Name du groupe
     * @return string|null
     */
    private function extractEstablishmentFromDn(string $dn): ?string
    {
        // Chercher un pattern OU=XXXXXXXXX (7 chiffres + lettre)
        if (preg_match('/OU=([0-9]{7}[a-zA-Z])/', $dn, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Récupère les classes par niveau (6eme, 5eme, etc.)
     * 
     * @param string $level Niveau scolaire
     * @param string $etab Code UAI de l'établissement
     * @return array
     */
    public function getByLevel(string $level, string $etab = '0'): array
    {
        try {
            $allClasses = $this->getAll($etab);

            // Filtrer par niveau
            $filteredClasses = array_filter($allClasses, function ($class) use ($level) {
                return stripos($class, $level) === 0;
            });

            return array_values($filteredClasses);

        } catch (\Exception $e) {
            Log::error('ClassRepository getByLevel error: ' . $e->getMessage());
            return [];
        }
    }
}
