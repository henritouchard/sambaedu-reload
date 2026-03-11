<?php

namespace App\Repositories;

use App\Config\SambaEduConfig;
use App\LdapModels\SambaEduGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use App\Facades\SEConfig;

/**
 * Repository pour la gestion des établissements
 * Remplace les fonctions legacy list_etabs() et etab_to_name()
 */
class EstablishmentRepository
{
    private SambaEduConfig $config;

    public function __construct(
        SambaEduConfig $config
    ) {
        $this->config = $config;
    }

    /**
     * Récupère la liste des établissements disponibles
     * Remplace list_etabs()
     * 
     * @return array Tableau [id => nom]
     */
    public function getAll(): array
    {
        try {
            $etabs = [];

            // Ajouter l'option "Domaine entier"
            $etabs[0] = "Domaine entier";

            // Récupérer les établissements depuis les groupes LDAP
            // Les établissements sont généralement des groupes avec un nom UAI (7 chiffres + lettre)
            $groups = SambaEduGroup::query()
                ->where('cn', 'starts_with', '0')
                ->get();

            foreach ($groups as $group) {
                $uai = $group->getFirstAttribute('cn');
                if ($uai && preg_match('/^[0-9]{7}[a-zA-Z]$/', $uai)) {
                    $description = $group->getFirstAttribute('description') ?? $uai;
                    $etabs[$uai] = $description;
                }
            }

            // Trier par nom
            asort($etabs);

            return $etabs;

        } catch (\Exception $e) {
            Log::error('EstablishmentRepository getAll error: ' . $e->getMessage());
            return [0 => 'Domaine entier'];
        }
    }

    /**
     * Obtient le nom d'un établissement à partir de son UAI
     * Remplace etab_to_name()
     * 
     * @param string $uai Code UAI de l'établissement
     * @return string Nom de l'établissement
     */
    public function getName(string $uai): string
    {
        if ($uai === '0' || empty($uai)) {
            // Retourner le nom de l'établissement par défaut depuis la config
            return SEConfig::get('etab_name', 'Domaine entier');
        }

        try {
            // Chercher le groupe correspondant à l'UAI
            $group = SambaEduGroup::where('cn', '=', $uai)->first();

            if ($group) {
                return $group->getFirstAttribute('description') ?? $uai;
            }

            return $uai;

        } catch (\Exception $e) {
            Log::warning("Erreur lors de la récupération du nom de l'établissement $uai", [
                'error' => $e->getMessage()
            ]);
            return $uai;
        }
    }

    /**
     * Vérifie si un établissement existe
     * 
     * @param string $uai Code UAI de l'établissement
     * @return bool
     */
    public function exists(string $uai): bool
    {
        if ($uai === '0' || empty($uai)) {
            return true; // Domaine entier existe toujours
        }

        try {
            return SambaEduGroup::where('cn', '=', $uai)->exists();
        } catch (\Exception $e) {
            Log::warning("Erreur lors de la vérification de l'existence de l'établissement $uai", [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Obtient l'UAI par défaut depuis la configuration
     * 
     * @return string
     */
    public function getDefaultUai(): string
    {
        return $this->config->get('etab_ou', '0');
    }

    /**
     * Retourne le code établissement courant pour les requêtes LDAP
     * 
     * @return string|null Code établissement ou null si domaine entier
     */
    public function getCurrentEstablishmentCode(): ?string
    {
        return $this->config->get('etab_ou', '0');
    }

    /**
     * Convertit un ID d'établissement en UAI
     * Simule etab_uai() du legacy
     * 
     * @param int|string $etab
     * @return string
     */
    public function toUai($etab): string
    {
        if (empty($etab) || $etab === '0') {
            return $this->getDefaultUai();
        }

        // Si c'est déjà un UAI (format 7 chiffres + lettre), le retourner
        if (is_string($etab) && preg_match('/^[0-9]{7}[a-zA-Z]$/', $etab)) {
            return $etab;
        }

        // Sinon, essayer de convertir depuis l'ID
        // Pour l'instant, on retourne l'UAI par défaut
        // TODO: Implémenter la vraie logique de conversion si nécessaire
        return $this->getDefaultUai();
    }
}
