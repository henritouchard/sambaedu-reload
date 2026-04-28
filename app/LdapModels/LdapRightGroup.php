<?php

namespace App\LdapModels;

use LdapRecord\Models\ActiveDirectory\Group as BaseGroup;
use App\Facades\SEConfig;

/**
 * Modèle LdapRecord pour les groupes de droits SambaEdu
 * 
 * Représente un groupe dans la branche Rights de l'Active Directory
 * Ces groupes ont un attribut 'info' qui contient le bitmask des droits
 */
class LdapRightGroup extends BaseGroup
{
    /**
     * Les attributs à retourner dans les résultats
     */
    protected array $columns = [
        'cn',           // Nom du groupe de droits
        'description',  // Description
        'info',         // Bitmask des droits (valeur numérique)
        'member',       // Membres du groupe
        'memberof',     // Groupes dont ce groupe est membre
    ];

    /**
     * Le DN de base pour les groupes de droits
     * Utilise la branche Rights de la configuration SambaEdu
     * 
     * @return string
     */
    public static function baseDn(): string
    {
        try {
            $ldapConfig = SEConfig::ldap();
            $baseDn = $ldapConfig->baseDn;
            $rightsRdn = $ldapConfig->rightsRdn ?? 'ou=Rights';

            return "{$rightsRdn},{$baseDn}";
        } catch (\Exception $e) {
            // Fallback vers le base_dn général
            return config('sambaedu.ldap.base_dn', '');
        }
    }

    /**
     * Récupère la valeur du droit (attribut info) sous forme d'entier
     *
     * @deprecated since 7.3 — lecture bitmask LDAP remplacée par Spatie via
     * `RightsService::calculateRights()`. Suppression programmée dans PR séparée
     * post-stabilisation prod (≥ 2 semaines).
     * Référence : `_bmad-output/implementation-artifacts/7-3-migration-bitmask-vers-spatie.md` §Sunset, matrice §11.
     *
     * @return int
     */
    public function getRightValue(): int
    {
        $info = $this->getFirstAttribute('info');
        return (int) ($info ?? 0);
    }

    /**
     * Récupère le nom du groupe (CN)
     * 
     * @return string|null
     */
    public function getGroupName(): ?string
    {
        return $this->getFirstAttribute('cn');
    }

    /**
     * Récupère tous les groupes de droits avec leurs valeurs
     *
     * @deprecated since 7.3 — lecture bitmask LDAP remplacée par Spatie via
     * `RightsService::calculateRights()`. Encore consommée par la commande
     * one-shot `sambaedu:migrate-rights-to-spatie` et l'étape 8 de
     * `/admin/sync-from-ad` (`importCustomProfilesFromAd`). Suppression
     * programmée dans PR séparée post-stabilisation prod (≥ 2 semaines).
     * Référence : `_bmad-output/implementation-artifacts/7-3-migration-bitmask-vers-spatie.md` §Sunset, matrice §11.
     *
     * @return array<string, int> Mapping nom du groupe => valeur info
     */
    public static function getAllRightsValues(): array
    {
        $rightsValues = [];

        try {
            // Limiter explicitement la recherche à la branche Rights avec in()
            $baseDn = static::baseDn();
            
            // Ne sélectionner que les attributs nécessaires pour optimiser
            $groups = static::query()
                ->in($baseDn)
                ->select(['cn', 'info'])
                ->get();

            foreach ($groups as $group) {
                $cn = $group->getGroupName();
                if ($cn) {
                    $rightsValues[$cn] = $group->getRightValue();
                }
            }
            
            \Illuminate\Support\Facades\Log::debug('LdapRightGroup: Groupes de droits chargés', [
                'count' => count($rightsValues),
                'base_dn' => $baseDn
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning('LdapRightGroup: Erreur lors de la récupération des groupes de droits', [
                'error' => $e->getMessage()
            ]);
        }

        return $rightsValues;
    }
}
