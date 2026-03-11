<?php

namespace App\Services;

use App\Types\User;
use App\Types\Etablissement;
use App\Config\SambaEduConfig;

/**
 * Service de transformation des données brutes Active Directory
 * en objets typés et normalisés
 */
class AdDataTransformer
{
    private ?string $rightsBranch = null;

    public function __construct(
        private ?SambaEduConfig $configService = null
    ) {
        $this->initRightsBranch();
    }

    /**
     * Initialise la branche des droits depuis la configuration LDAP
     */
    private function initRightsBranch(): void
    {
        if ($this->configService) {
            try {
                $this->rightsBranch = $this->configService->ldap()->rightsDn();
            } catch (\Exception $e) {
                // Fallback si la config n'est pas disponible
                $this->rightsBranch = null;
            }
        }
    }

    /**
     * Définit manuellement la branche des droits
     */
    public function setRightsBranch(?string $rightsBranch): self
    {
        $this->rightsBranch = $rightsBranch;
        return $this;
    }

    /**
     * Récupère la branche des droits actuelle
     */
    public function getRightsBranch(): ?string
    {
        return $this->rightsBranch;
    }

    /**
     * Transforme les données utilisateur brutes en objet User typé
     */
    public function transformUser(array $rawUserData): User
    {
        return User::fromAdData($this->normalizeUserData($rawUserData), $this->rightsBranch);
    }

    /**
     * Transforme les données établissement brutes en objet Etablissement typé
     */
    public function transformEtablissement(array $rawEtabData): Etablissement
    {
        return Etablissement::fromAdData($this->normalizeEtabData($rawEtabData));
    }

    /**
     * Transforme une liste d'établissements
     */
    public function transformEtablissements(array $rawEtabsList): array
    {
        $etablissements = [];

        foreach ($rawEtabsList as $uai => $name) {
            if (is_string($name)) {
                // Format simple: uai => nom
                $etablissements[] = new Etablissement(
                    uai: (string) $uai,
                    name: $name
                );
            } elseif (is_array($name)) {
                // Format complexe avec données complètes
                $etablissements[] = $this->transformEtablissement($name);
            }
        }

        return $etablissements;
    }

    /**
     * Crée un établissement par défaut depuis la configuration
     */
    public function getDefaultEtablissement(array $config): Etablissement
    {
        return Etablissement::fromConfig($config);
    }

    /**
     * Normalise les données utilisateur pour gérer les incohérences AD
     */
    private function normalizeUserData(array $data): array
    {
        // Gestion des cas où les données sont dans des tableaux
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value[0]) && !in_array($key, ['memberof'])) {
                // Pour la plupart des champs, prendre la première valeur
                $normalized[$key] = $value[0];
            } else {
                $normalized[$key] = $value;
            }
        }

        // Normalisation spécifique des champs
        $normalized['fullname'] = $normalized['fullname']
            ?? $normalized['displayname']
            ?? $normalized['cn']
            ?? '';

        // Gestion de l'établissement
        if (isset($normalized['etab'])) {
            $normalized['etab'] = (string) $normalized['etab'];
        }

        // Gestion du userAccountControl
        if (isset($normalized['useraccountcontrol'])) {
            $normalized['useraccountcontrol'] = (int) $normalized['useraccountcontrol'];
        }

        // Gestion des groupes memberOf
        if (isset($normalized['memberof']) && !is_array($normalized['memberof'])) {
            $normalized['memberof'] = [$normalized['memberof']];
        }

        return $normalized;
    }

    /**
     * Normalise les données établissement
     */
    private function normalizeEtabData(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_array($value) && isset($value[0])) {
                $normalized[$key] = $value[0];
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

}
