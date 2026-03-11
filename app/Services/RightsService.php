<?php

namespace App\Services;

use App\Enums\LegacyRight;
use App\Repositories\RightRepository;
use Illuminate\Support\Facades\Log;

/**
 * Service de gestion des droits SambaEdu
 * 
 * Calcule les droits d'un utilisateur à partir de ses groupes de droits LDAP.
 * Les droits sont des bitmasks stockés dans l'attribut 'info' des groupes de la branche rights.
 * 
 * Utilise RightRepository pour accéder aux données LDAP.
 */
class RightsService
{
    private RightRepository $rightRepository;

    public function __construct(?RightRepository $rightRepository = null)
    {
        $this->rightRepository = $rightRepository ?? new RightRepository();
    }

    // ============================================
    // CONSTANTES LEGACY (délèguent vers LegacyRight enum)
    // @deprecated Utiliser LegacyRight enum directement
    // ============================================

    public const SE_NO_RIGHT = 0x00;
    public const SE_USER_PASSWORD_INIT = 0x01;
    public const SE_USER_READ = 0x02;
    public const SE_USER_MODIFY = 0x04;
    public const SE_USER_CREATE_TEMP = 0x08;
    public const SE_USER_ASSIGN_RIGHT = 0x10;
    public const SE_USER_DELEGATE = 0x20;
    public const SE_SHARE_VIEW = 0x40;
    public const SE_SHARE_REFRESH = 0x80;
    public const SE_SHARE_ADMIN = 0xC0;
    public const SE_ELEVE_ADMIN = 0x07;
    public const SE_USER_ADMIN = 0xFF;
    public const SE_COMPUTER_VIEW = 0x100;
    public const SE_COMPUTER_CONTROL = 0x200;
    public const SE_COMPUTER_ELEVATE = 0x400;
    public const SE_COMPUTER_INSTALL = 0x800;
    public const SE_WPKG_ASSIGN = 0x1000;
    public const SE_WPKG_ADD = 0x2000;
    public const SE_WPKG_CREATE = 0x4000;
    public const SE_COMPUTER_ADMIN = 0xEF00;
    public const SE_SERVER_ADMIN = 0x8000;
    public const SE_ADMIN = 0xFFFF;

    /**
     * Calcule le bitmask de droits pour un utilisateur
     * 
     * @param array $rightGroups Liste des noms de groupes de droits (CN)
     * @param string $login Login de l'utilisateur (pour le cas spécial 'admin')
     * @return int Bitmask des droits
     */
    public function calculateRights(array $rightGroups, string $login = ''): int
    {
        // Cas spécial : l'utilisateur 'admin' a tous les droits
        if ($login === 'admin') {
            return LegacyRight::admin();
        }

        if (empty($rightGroups)) {
            return LegacyRight::none();
        }

        // Charger les valeurs info des groupes de droits via le repository
        $rightsValues = $this->rightRepository->getAllRightsValues();

        $rights = 0;
        $negativeRights = 0;

        foreach ($rightGroups as $groupName) {
            // Les groupes préfixés par 'no_' annulent des droits
            if (str_starts_with($groupName, 'no_')) {
                $baseGroupName = substr($groupName, 3);
                if (isset($rightsValues[$baseGroupName])) {
                    $negativeRights |= $rightsValues[$baseGroupName];
                }
            } else {
                if (isset($rightsValues[$groupName])) {
                    $rights |= $rightsValues[$groupName];
                }
            }
        }

        // Appliquer les droits négatifs
        return $rights & ~$negativeRights;
    }

    /**
     * Vérifie si un bitmask de droits contient un droit spécifique
     * 
     * @param int $userRights Bitmask des droits de l'utilisateur
     * @param int $requiredRight Droit requis à vérifier
     * @param bool $or Si true, vérifie si AU MOINS UN des bits est présent
     * @return bool
     */
    public function hasRight(int $userRights, int $requiredRight, bool $or = false): bool
    {
        if ($or) {
            // Au moins un des droits demandés est présent
            return ($requiredRight & $userRights) !== 0;
        } else {
            // Tous les droits demandés sont présents
            return (~(~$requiredRight | $userRights)) === 0;
        }
    }

    /**
     * Invalide le cache des groupes de droits
     */
    public function invalidateCache(): void
    {
        $this->rightRepository->invalidateCache();
    }

    /**
     * Retourne la description d'un droit
     * @deprecated Utiliser LegacyRight::fromBitmask() + ->label()
     */
    public static function getRightDescription(int $right): array
    {
        return collect(LegacyRight::fromBitmask($right))
            ->mapWithKeys(fn(LegacyRight $r) => [$r->value => $r->label()])
            ->toArray();
    }

    /**
     * Retourne les informations détaillées des droits effectifs
     * @deprecated Utiliser LegacyRight::fromBitmask() directement
     */
    public static function getRightDetails(int $right): array
    {
        return collect(LegacyRight::fromBitmask($right))
            ->mapWithKeys(fn(LegacyRight $r) => [$r->value => [
                'name' => $r->constantName(),
                'label' => $r->label(),
                'description' => $r->description(),
            ]])
            ->toArray();
    }

    /**
     * Définitions complètes des droits avec nom, label et description
     * @deprecated Utiliser LegacyRight::definitions()
     */
    public static function getRightsDefinitions(): array
    {
        return LegacyRight::definitions();
    }
}
