<?php

namespace App\Constants\Ldap;

/**
 * Constantes pour les groupes de fonction SambaEdu
 *
 * Ces groupes correspondent aux fonctions du personnel dans la nomenclature AAF
 * (Annuaire Académique Fédérateur) de l'Éducation Nationale.
 * Ils déterminent le placement dans l'arbre AD (sous-OU) et l'appartenance au groupe AD correspondant.
 */
class FunctionGroups
{
    /**
     * Fonctions du personnel administratif
     */
    public const ADMINISTRATIFS = [
        'Direction', 'Secretariat', 'Gestionnaire', 'Medical',
        'VieScol', 'Agent', 'AED', 'Tech', 'Autres',
    ];

    /**
     * Fonctions pédagogiques (sous-OU de Profs, pas d'Administratifs)
     */
    public const PEDAGOGIQUES = ['Documentaliste', 'AESH'];

    /**
     * Toutes les fonctions connues
     */
    public static function all(): array
    {
        return array_merge(self::ADMINISTRATIFS, self::PEDAGOGIQUES);
    }

    /**
     * Vérifie si un nom de groupe est un groupe de fonction
     */
    public static function isFunctionGroup(string $groupName): bool
    {
        return in_array($groupName, self::all(), true);
    }

    /**
     * Retourne les fonctions pour une catégorie donnée
     */
    public static function forCategory(string $categorie): array
    {
        return match ($categorie) {
            'Administratifs' => self::ADMINISTRATIFS,
            'Profs', 'Pedagogiques' => self::PEDAGOGIQUES,
            default => self::all(),
        };
    }
}
