<?php

namespace App\Enums;

/**
 * Bitmask des droits legacy SambaEdu (stockés dans l'attribut 'info' des groupes LDAP)
 * 
 * Chaque case représente un bit atomique du bitmask.
 * Les constantes composites (SE_SHARE_ADMIN, SE_ELEVE_ADMIN, etc.) sont des méthodes statiques.
 */
enum LegacyRight: int
{
    // Droits utilisateurs (0x00 - 0xFF)
    case UserPasswordInit = 0x01;
    case UserRead = 0x02;
    case UserModify = 0x04;
    case UserCreateTemp = 0x08;
    case UserAssignRight = 0x10;
    case UserDelegate = 0x20;
    case ShareView = 0x40;
    case ShareRefresh = 0x80;

    // Droits machines (0x100 - 0x7F00)
    case ComputerView = 0x100;
    case ComputerControl = 0x200;
    case ComputerElevate = 0x400;
    case ComputerInstall = 0x800;
    case WpkgAssign = 0x1000;
    case WpkgAdd = 0x2000;
    case WpkgCreate = 0x4000;

    // Droits serveur
    case ServerAdmin = 0x8000;

    // ========================================================================
    // COMPOSITES (combinaisons de bits)
    // ========================================================================

    /** Aucun droit */
    public static function none(): int
    {
        return 0x00;
    }

    /** Admin partages : ShareView | ShareRefresh */
    public static function shareAdmin(): int
    {
        return self::ShareView->value | self::ShareRefresh->value; // 0xC0
    }

    /** Admin élèves : UserPasswordInit | UserRead | UserModify */
    public static function eleveAdmin(): int
    {
        return self::UserPasswordInit->value | self::UserRead->value | self::UserModify->value; // 0x07
    }

    /** Admin utilisateurs : tous les droits utilisateurs + partages (0xFF) */
    public static function userAdmin(): int
    {
        return 0xFF;
    }

    /** Admin machines : tous les droits machines sauf ServerAdmin */
    public static function computerAdmin(): int
    {
        return self::ComputerView->value | self::ComputerControl->value
            | self::ComputerElevate->value | self::ComputerInstall->value
            | self::WpkgAssign->value | self::WpkgAdd->value; // 0xEF00 (sans WpkgCreate)
    }

    /** Super admin : tous les droits */
    public static function admin(): int
    {
        return 0xFFFF;
    }

    // ========================================================================
    // LABELS & DESCRIPTIONS
    // ========================================================================

    public function label(): string
    {
        return match ($this) {
            self::UserPasswordInit => 'Réinitialisation des mots de passe',
            self::UserRead => 'Consultation de l\'annuaire utilisateurs',
            self::UserModify => 'Modification des utilisateurs',
            self::UserCreateTemp => 'Création d\'utilisateurs temporaires',
            self::UserAssignRight => 'Assignation de droits aux utilisateurs',
            self::UserDelegate => 'Délégation de parcs aux utilisateurs',
            self::ShareView => 'Visualisation des partages et quotas',
            self::ShareRefresh => 'Actualisation des partages',
            self::ComputerView => 'Visualisation des machines et parcs',
            self::ComputerControl => 'Contrôle à distance des machines',
            self::ComputerElevate => 'Droits admin locaux (élévation)',
            self::ComputerInstall => 'Droit d\'installation automatisée',
            self::WpkgAssign => 'Affectation d\'applications aux machines',
            self::WpkgAdd => 'Ajout d\'applications depuis le référentiel',
            self::WpkgCreate => 'Création de recettes d\'installation',
            self::ServerAdmin => 'Administration des serveurs',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::UserPasswordInit => 'Permet de réinitialiser le mot de passe des utilisateurs à une valeur par défaut.',
            self::UserRead => 'Permet de consulter les informations des utilisateurs dans l\'annuaire LDAP.',
            self::UserModify => 'Permet de modifier les informations personnelles des utilisateurs (nom, email, etc.).',
            self::UserCreateTemp => 'Permet de créer des comptes utilisateurs temporaires avec une date d\'expiration.',
            self::UserAssignRight => 'Permet d\'ajouter ou retirer des droits d\'administration aux utilisateurs.',
            self::UserDelegate => 'Permet de déléguer la gestion de parcs de machines à d\'autres utilisateurs.',
            self::ShareView => 'Permet de consulter les partages réseau et les quotas disque des utilisateurs.',
            self::ShareRefresh => 'Permet de rafraîchir et recréer les partages réseau.',
            self::ComputerView => 'Permet de voir les machines, leur état, et d\'effectuer des actions d\'allumage/extinction.',
            self::ComputerControl => 'Permet de prendre le contrôle à distance des machines (VNC, RDP).',
            self::ComputerElevate => 'Permet d\'obtenir temporairement les droits administrateur local sur une machine.',
            self::ComputerInstall => 'Permet de lancer des installations automatisées (clonage, déploiement).',
            self::WpkgAssign => 'Permet d\'affecter des applications du catalogue WPKG aux machines.',
            self::WpkgAdd => 'Permet d\'ajouter des applications existantes du référentiel au catalogue local.',
            self::WpkgCreate => 'Permet de créer de nouvelles recettes d\'installation d\'applications (packages WPKG).',
            self::ServerAdmin => 'Permet d\'administrer les serveurs SambaEdu (configuration, services, logs).',
        };
    }

    /** Nom de la constante legacy (ex: SE_USER_PASSWORD_INIT) */
    public function constantName(): string
    {
        return 'SE_' . strtoupper(preg_replace('/([a-z])([A-Z])/', '$1_$2', $this->name));
    }

    /** Catégorie du droit */
    public function category(): string
    {
        return match ($this) {
            self::UserPasswordInit, self::UserRead, self::UserModify,
            self::UserCreateTemp, self::UserAssignRight, self::UserDelegate => 'user',
            self::ShareView, self::ShareRefresh => 'share',
            self::ComputerView, self::ComputerControl,
            self::ComputerElevate, self::ComputerInstall => 'computer',
            self::WpkgAssign, self::WpkgAdd, self::WpkgCreate => 'wpkg',
            self::ServerAdmin => 'server',
        };
    }

    // ========================================================================
    // HELPERS
    // ========================================================================

    /**
     * Vérifie si un bitmask contient ce droit
     */
    public function isPresentIn(int $bitmask): bool
    {
        return ($bitmask & $this->value) !== 0;
    }

    /**
     * Retourne tous les droits atomiques présents dans un bitmask
     * 
     * @return LegacyRight[]
     */
    public static function fromBitmask(int $bitmask): array
    {
        return array_values(array_filter(
            self::cases(),
            fn(self $right) => ($bitmask & $right->value) !== 0
        ));
    }

    /**
     * Retourne la définition complète de tous les droits atomiques
     * 
     * @return array<int, array{name: string, label: string, description: string}>
     */
    public static function definitions(): array
    {
        $defs = [];
        foreach (self::cases() as $right) {
            $defs[$right->value] = [
                'name' => $right->constantName(),
                'label' => $right->label(),
                'description' => $right->description(),
            ];
        }
        return $defs;
    }
}
