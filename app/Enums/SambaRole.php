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
                SambaPermission::UserPasswordInit,
            ],
            self::EleveAdmin => [
                SambaPermission::UserPasswordInit,
                SambaPermission::UserRead,
                SambaPermission::UserModify,
            ],
            self::ShareAdmin => [
                SambaPermission::ShareView,
                SambaPermission::ShareRefresh,
                // Story 5.2 (D2=A) — ShareAdmin gère les partages classes
                // (création, ACLs, toggle échange). Le bit legacy
                // `SE_SHARE_REFRESH` couvrait l'ensemble du périmètre dans
                // `partages/rep_classes.php`, donc ShareAdmin reçoit la
                // permission Spatie `share.manage` par défaut.
                SambaPermission::ShareManage,
                // Story 34.2 (Q5) — l'admin partages gère aussi les lecteurs
                // réseau gérés (module SE5-natif).
                SambaPermission::NetworkShareView,
                SambaPermission::NetworkShareManage,
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
                // Story 5.2 (D2=A) — UserAdmin gère aussi les partages
                // classes (cohérent : un changement de classe d'élève via
                // la page utilisateur peut nécessiter un sync ACLs partage).
                SambaPermission::ShareManage,
                // Story 34.2 (Q5) — l'admin utilisateurs gère aussi les lecteurs
                // réseau gérés (module SE5-natif).
                SambaPermission::NetworkShareView,
                SambaPermission::NetworkShareManage,
            ],
            self::Technicien => [
                SambaPermission::ComputerView,
                SambaPermission::ComputerControl,
                SambaPermission::WpkgAssign,
            ],
            self::ReferentNumerique => [
                SambaPermission::UserPasswordInit,
                SambaPermission::UserRead,
                SambaPermission::UserCreateTemp,
                SambaPermission::ComputerView,
                SambaPermission::ComputerInstall,
                // Story 34.2 (Q5) — le Référent Numérique pilote les lecteurs
                // réseau gérés de son établissement (cœur de la story 34.2). Il
                // n'a AUCUNE permission `share.*` (partages de classe) : d'où la
                // permission DÉDIÉE `networkshare.*`.
                SambaPermission::NetworkShareView,
                SambaPermission::NetworkShareManage,
                // Story 36.4 (D6) — le Référent Numérique crée les règles d'accès
                // aux dossiers de son établissement (formulaire fs_acl). Contrôle
                // PAR PARC dans le service (délégation scopée).
                SambaPermission::FolderRuleView,
                SambaPermission::FolderRuleManage,
            ],
            self::ComputerAdmin => [
                SambaPermission::ComputerView,
                SambaPermission::ComputerControl,
                SambaPermission::ComputerElevate,
                SambaPermission::ComputerInstall,
                SambaPermission::WpkgAssign,
                SambaPermission::WpkgAdd,
                SambaPermission::WpkgCreate,
                SambaPermission::AppCustomize,
                // Story 36.4 (D6) — l'admin machines gère aussi les règles d'accès
                // aux dossiers (mécanisme fs_acl de portée machine).
                SambaPermission::FolderRuleView,
                SambaPermission::FolderRuleManage,
                // Story 7.3 (décision Henri 2026-04-25 — option C) : RDP est
                // une élévation de `ComputerControl`. Le ComputerAdmin doit
                // l'avoir par défaut pour préserver la couverture fonctionnelle
                // de la migration legacy `rdp_<parc>`.
                SambaPermission::ComputerRemoteRdp,
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
     * Indique si un nom de rôle fait partie des rôles "seedés" par le socle
     * d'application (profils livrés par défaut, source = cet enum).
     *
     * Story 7.2 : utilisé par :
     *  - `PermissionSeeder` pour distinguer les rôles à re-synchroniser
     *    (seulement ceux-là) des profils custom créés à l'UI ou rapatriés
     *    depuis la branche LDAP `rights_rdn`.
     *  - L'onglet "Profils" (/app/rights-management) pour afficher le badge
     *    `seeded` vs `custom` et désactiver renommage / suppression sur les
     *    rôles seedés.
     *
     * Cette méthode est la source de vérité : pas de colonne DB `origin` ajoutée
     * (décision produit 0.9 du 2026-04-23).
     */
    public static function isSeeded(string $roleName): bool
    {
        foreach (self::cases() as $case) {
            if ($case->value === $roleName) {
                return true;
            }
        }
        return false;
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
