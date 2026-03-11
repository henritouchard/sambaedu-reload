<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuration de l'établissement
 * 
 * Contient les informations sur l'établissement courant
 * et la gestion multi-établissements.
 */
final readonly class EstablishmentConfig
{
    public function __construct(
        /** Code UAI de l'établissement (ex: 0000000x) */
        public string $uai,

        /** Nom de l'établissement */
        public string $name,

        /** Code établissement courant (depuis session ou config) */
        public string $currentCode,
    ) {
    }


    /**
     * Retourne le code établissement effectif pour les requêtes LDAP
     */
    public function getEffectiveCode(): ?string
    {
        return $this->currentCode;
    }
}
