<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Configuration des identifiants sensibles
 * 
 * DTO immuable contenant les credentials sensibles.
 * Ce DTO peut être chargé depuis un fichier séparé avec des permissions restreintes.
 * 
 * @security Ne jamais logger ou exposer les valeurs de ce DTO
 */
final readonly class CredentialsConfig
{
    public function __construct(
        public string $ldapAdminPassword,
        public string $se4Key,
        public string $se4PubKey,
        public ?string $apiMasterKey = null,
    ) {
    }

    /**
     * Vérifie si le mot de passe admin LDAP est configuré
     */
    public function hasLdapAdminPassword(): bool
    {
        return !empty($this->ldapAdminPassword);
    }

    /**
     * Vérifie si la clé SE4 est configurée
     */
    public function hasSe4Key(): bool
    {
        return !empty($this->se4Key);
    }

    /**
     * Vérifie si la clé publique SE4 est configurée
     */
    public function hasSe4PubKey(): bool
    {
        return !empty($this->se4PubKey);
    }

    /**
     * Vérifie si la clé API master est configurée
     */
    public function hasApiMasterKey(): bool
    {
        return !empty($this->apiMasterKey);
    }

    /**
     * Vérifie si toutes les credentials essentielles sont configurées
     */
    public function isComplete(): bool
    {
        return $this->hasLdapAdminPassword() && $this->hasSe4Key();
    }

    /**
     * Retourne une représentation sécurisée pour le debug
     * 
     * @return array<string, string>
     */
    public function toSafeArray(): array
    {
        return [
            'ldapAdminPassword' => $this->hasLdapAdminPassword() ? '***' : '(non configuré)',
            'se4Key' => $this->hasSe4Key() ? '***' : '(non configuré)',
            'se4PubKey' => $this->hasSe4PubKey() ? '***' : '(non configuré)',
            'apiMasterKey' => $this->hasApiMasterKey() ? '***' : '(non configuré)',
        ];
    }
}
