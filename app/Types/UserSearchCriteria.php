<?php

namespace App\Types;

/**
 * Critères de recherche d'utilisateurs typés
 * Permet de définir explicitement ce que l'on cherche, indépendamment de l'implémentation LDAP
 */
class UserSearchCriteria
{
    public function __construct(
        public readonly ?string $genericSearch = null,
        public readonly ?string $loginSearch = null,
        public readonly ?string $nameSearch = null,

        /** @var string[] Liste des rôles (ex: ['profs', 'eleves']) */
        public readonly array $roles = [],

        /** @var string[] Liste des statuts (ex: ['active', 'inactive']) */
        public readonly array $statuses = [],

        /** @var string[] Liste des classes (ex: ['3A', '6B']) */
        public readonly array $classes = [],

        /** @var string[] Liste des groupes (ex: ['Profs', 'Equipe_3A']) */
        public readonly array $groups = [],

        public readonly int $perPage = 20,
        public readonly int $page = 1
    ) {
    }

    /**
     * Vérifie si au moins un critère de recherche est défini (hors pagination)
     */
    public function hasCriteria(): bool
    {
        return !empty($this->genericSearch)
            || !empty($this->loginSearch)
            || !empty($this->nameSearch)
            || !empty($this->roles)
            || !empty($this->statuses)
            || !empty($this->classes)
            || !empty($this->groups)
            || !empty($this->establishmentCode);
    }
}
