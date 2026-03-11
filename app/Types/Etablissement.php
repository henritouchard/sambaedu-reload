<?php

namespace App\Types;

/**
 * Classe de données typée pour un établissement
 */
class Etablissement
{
    public function __construct(
        public readonly string $uai,                   // Code UAI de l'établissement
        public readonly string $name,                  // Nom de l'établissement
        public readonly ?string $description = null,   // Description complète
        public readonly ?string $address = null,       // Adresse
        public readonly ?string $postalCode = null,    // Code postal
        public readonly ?string $city = null,          // Ville
        public readonly ?string $phone = null,         // Téléphone
        public readonly ?string $fax = null,           // Fax
        public readonly ?string $email = null,         // Email
        public readonly ?string $website = null,       // Site web
        public readonly ?string $academie = null,      // Académie
        public readonly ?string $type = null,          // Type d'établissement
        public readonly bool $isActive = true,         // Établissement actif
        public readonly ?string $dn = null,           // Distinguished Name
    ) {
    }

    /**
     * Récupère le nom d'affichage de l'établissement
     */
    public function getDisplayName(): string
    {
        return $this->name ?: $this->uai;
    }

    /**
     * Récupère l'adresse complète formatée
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->address,
            $this->postalCode,
            $this->city
        ]);

        return implode(', ', $parts);
    }

    /**
     * Vérifie si c'est l'établissement par défaut (UAI = 0)
     */
    public function isDefault(): bool
    {
        return $this->uai === '0' || $this->uai === 0;
    }

    /**
     * Crée une instance depuis les données brutes d'Active Directory
     */
    public static function fromAdData(array $adData): self
    {
        return new self(
            uai: $adData['cn'] ?? $adData['uai'] ?? '0',
            name: $adData['description'] ?? $adData['name'] ?? '',
            description: $adData['info'] ?? null,
            address: $adData['streetaddress'] ?? null,
            postalCode: $adData['postalcode'] ?? null,
            city: $adData['l'] ?? null,
            phone: $adData['telephonenumber'] ?? null,
            fax: $adData['facsimiletelephonenumber'] ?? null,
            email: $adData['mail'] ?? null,
            website: $adData['wwwhomepage'] ?? null,
            academie: $adData['st'] ?? null,
            type: $adData['businesscategory'] ?? null,
            isActive: !isset($adData['useraccountcontrol']) || $adData['useraccountcontrol'] !== '514',
            dn: $adData['dn'] ?? null,
        );
    }

    /**
     * Crée une instance pour l'établissement par défaut depuis la config
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            uai: '0',
            name: $config['etab_name'] ?? 'Établissement par défaut',
            description: $config['etab_name'] ?? null,
            dn: null,
        );
    }

    /**
     * Convertit en tableau pour compatibilité legacy
     */
    public function toArray(): array
    {
        return [
            'uai' => $this->uai,
            'name' => $this->name,
            'description' => $this->description,
            'cn' => $this->uai,
            'info' => $this->description,
            'streetaddress' => $this->address,
            'postalcode' => $this->postalCode,
            'l' => $this->city,
            'telephonenumber' => $this->phone,
            'facsimiletelephonenumber' => $this->fax,
            'mail' => $this->email,
            'wwwhomepage' => $this->website,
            'st' => $this->academie,
            'businesscategory' => $this->type,
            'dn' => $this->dn,
        ];
    }
}
