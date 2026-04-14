<?php

namespace App\Enums;

/**
 * Rôles Spatie SambaEdu
 * 
 * Chaque case correspond à un rôle Spatie avec ses permissions associées.
 */
enum SambaRole: string
{
    case Eleve = 'eleve';
    case Prof = 'prof';
    case EleveAdmin = 'eleve-admin';
    case ShareAdmin = 'share-admin';
    case UserAdmin = 'user-admin';
    case Technicien = 'technicien';
    case ReferentNumerique = 'referent-numerique';
    case ComputerAdmin = 'computer-admin';
    case SuperAdmin = 'super-admin';

    public function label(): string
    {
        return match ($this) {
            self::Eleve => 'Élève',
            self::Prof => 'Professeur',
            self::EleveAdmin => 'Admin élèves',
            self::ShareAdmin => 'Admin partages',
            self::UserAdmin => 'Admin utilisateurs',
            self::Technicien => 'Technicien',
            self::ReferentNumerique => 'Référent numérique',
            self::ComputerAdmin => 'Admin machines',
            self::SuperAdmin => 'Super administrateur',
        };
    }

    /**
     * Permissions Spatie associées à ce rôle
     * 
     * @return SambaPermission[]
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Eleve => [],
            self::Prof => [
                SambaPermission::UserRead,
            ],
            self::EleveAdmin => [
                SambaPermission::UserPasswordInit,
                SambaPermission::UserRead,
                SambaPermission::UserModify,
            ],
            self::ShareAdmin => [
                SambaPermission::ShareView,
                SambaPermission::ShareRefresh,
            ],
            self::UserAdmin => [
                SambaPermission::UserPasswordInit,
                SambaPermission::UserRead,
                SambaPermission::UserModify,
                SambaPermission::UserCreateTemp,
                SambaPermission::UserAssignRight,
                SambaPermission::UserDelegate,
                SambaPermission::ShareView,
                SambaPermission::ShareRefresh,
            ],
            self::Technicien => [
                SambaPermission::ComputerView,
                SambaPermission::ComputerControl,
                SambaPermission::WpkgAssign,
            ],
            self::ReferentNumerique => [
                SambaPermission::UserRead,
                SambaPermission::ShareView,
                SambaPermission::ComputerView,
            ],
            self::ComputerAdmin => [
                SambaPermission::ComputerView,
                SambaPermission::ComputerControl,
                SambaPermission::ComputerElevate,
                SambaPermission::ComputerInstall,
                SambaPermission::WpkgAssign,
                SambaPermission::WpkgAdd,
                SambaPermission::WpkgCreate,
            ],
            self::SuperAdmin => SambaPermission::cases(),
        };
    }

    /**
     * Noms des permissions (pour syncPermissions Spatie)
     * 
     * @return string[]
     */
    public function permissionNames(): array
    {
        return array_map(fn(SambaPermission $p) => $p->value, $this->permissions());
    }

    /**
     * Détermine le rôle le plus approprié pour un bitmask legacy
     */
    public static function fromBitmask(int $bitmask): ?self
    {
        return match (true) {
            $bitmask === LegacyRight::admin() => self::SuperAdmin,
            ($bitmask & LegacyRight::computerAdmin()) === LegacyRight::computerAdmin()
                && ($bitmask & LegacyRight::userAdmin()) === LegacyRight::userAdmin() => self::SuperAdmin,
            ($bitmask & LegacyRight::computerAdmin()) === LegacyRight::computerAdmin() => self::ComputerAdmin,
            ($bitmask & LegacyRight::userAdmin()) === LegacyRight::userAdmin() => self::UserAdmin,
            ($bitmask & LegacyRight::shareAdmin()) === LegacyRight::shareAdmin() => self::ShareAdmin,
            ($bitmask & LegacyRight::eleveAdmin()) === LegacyRight::eleveAdmin() => self::EleveAdmin,
            default => null,
        };
    }
}
