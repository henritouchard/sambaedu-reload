<?php

namespace App\Enums;

/**
 * Permissions Spatie SambaEdu (noms dot-notation utilisés dans les policies et Blade)
 * 
 * Chaque case correspond à une permission Spatie et est mappée 1:1 vers un LegacyRight.
 */
enum SambaPermission: string
{
    // Utilisateurs
    case UserPasswordInit = 'user.password.init';
    case UserRead = 'user.read';
    case UserModify = 'user.modify';
    case UserCreateTemp = 'user.create.temp';
    case UserAssignRight = 'user.assign.right';
    case UserDelegate = 'user.delegate';

    // Partages
    case ShareView = 'share.view';
    case ShareRefresh = 'share.refresh';
    /**
     * Story 5.2 (D2=A) — Gestion complète des partages de classe : création
     * du dossier `/var/sambaedu/Classes/Classe_<name>/`, application des ACLs
     * canoniques (racine + `_travail`/`_profs`/`_echange` + dossiers élèves),
     * toggle dossier d'échange, archivage. Mappée sur le bit legacy
     * `SE_SHARE_REFRESH` (le legacy `partages/rep_classes.php:58` couvrait
     * déjà l'ensemble du périmètre via ce même bit). Le mapping `legacyRight()`
     * partage donc 0x40 avec `ShareRefresh` (cf. `bitmaskMapping()` qui
     * dédoublonne via `isSecondaryBitPermission()` — `ShareManage` y est
     * déclarée pour éviter qu'un import bitmask la sur-active).
     */
    case ShareManage = 'share.manage';

    // Machines
    case ComputerView = 'computer.view';
    case ComputerControl = 'computer.control';
    case ComputerElevate = 'computer.elevate';
    case ComputerInstall = 'computer.install';
    /**
     * Story 7.3 (décision Henri 2026-04-25 — option C) : permission dédiée
     * pour la migration des délégations RDP legacy (`rdp_<parc>` /
     * `no_rdp_<parc>`). Le legacy stockait le bitmask `SE_COMPUTER_CONTROL`
     * (0x200) pour le profil `rdp` (cf. `OU=rights/rdp`), donc on partage le
     * même bit atomique côté `legacyRight()`. Dans Spatie, la permission est
     * distincte de `computer.control` pour permettre une gouvernance fine
     * RDP (politique de sécurité d'établissement).
     */
    case ComputerRemoteRdp = 'computer.remote.rdp';

    // WPKG
    case WpkgAssign = 'wpkg.assign';
    case WpkgAdd = 'wpkg.add';
    case WpkgCreate = 'wpkg.create';

    // Serveur
    case ServerAdmin = 'server.admin';

    // Wallpapers (story 4.7)
    case WallpaperManage = 'wallpaper.manage';

    // Personnalisation applicative (story 4.8 — Firefox, Thunderbird, …)
    case AppCustomize = 'app.customize';

    // ========================================================================
    // MAPPING VERS LEGACY
    // ========================================================================

    /** Retourne le LegacyRight correspondant */
    public function legacyRight(): LegacyRight
    {
        return match ($this) {
            self::UserPasswordInit => LegacyRight::UserPasswordInit,
            self::UserRead => LegacyRight::UserRead,
            self::UserModify => LegacyRight::UserModify,
            self::UserCreateTemp => LegacyRight::UserCreateTemp,
            self::UserAssignRight => LegacyRight::UserAssignRight,
            self::UserDelegate => LegacyRight::UserDelegate,
            self::ShareView => LegacyRight::ShareView,
            self::ShareRefresh => LegacyRight::ShareRefresh,
            // Story 5.2 (D2=A) — partage le bit legacy `SE_SHARE_REFRESH`
            // avec `ShareRefresh`. Marquée `isSecondaryBitPermission()` pour
            // ne PAS être sur-attribuée par `fromBitmask()` (sinon tout user
            // ayant `share.refresh` recevrait `share.manage` après import
            // bitmask). Attribuée explicitement par seeder/UI rights mgmt.
            self::ShareManage => LegacyRight::ShareRefresh,
            self::ComputerView => LegacyRight::ComputerView,
            self::ComputerControl => LegacyRight::ComputerControl,
            self::ComputerElevate => LegacyRight::ComputerElevate,
            self::ComputerInstall => LegacyRight::ComputerInstall,
            // Story 7.3 — `ComputerRemoteRdp` partage le bit `ComputerControl`
            // (0x200) dans le legacy : le profil `OU=rights/rdp` stockait
            // historiquement `SE_COMPUTER_CONTROL` (0x200) dans son `info`.
            // Convention du mapping `bit représentant` (cf. matrice §11) :
            // tout user avec ce bit obtient la perm Spatie après sync.
            self::ComputerRemoteRdp => LegacyRight::ComputerControl,
            self::WpkgAssign => LegacyRight::WpkgAssign,
            self::WpkgAdd => LegacyRight::WpkgAdd,
            self::WpkgCreate => LegacyRight::WpkgCreate,
            self::ServerAdmin => LegacyRight::ServerAdmin,
            // WallpaperManage hérite du bitmask ServerAdmin (pas de bit legacy
            // dédié — le wallpaper était géré par l'admin serveur dans
            // l'UI legacy `gpo/wallpaper.php`). Story 4.7.
            self::WallpaperManage => LegacyRight::ServerAdmin,
            // AppCustomize : gpo/firefox.php et gpo/gestion_apps.php étaient
            // gardés par SE_COMPUTER_ADMIN (composite 0xEF00). Convention de
            // coexistence : on pointe sur un bit atomique du composite —
            // ComputerInstall (0x800) — qui sert de représentant. Tout user
            // avec SE_COMPUTER_ADMIN a ce bit, donc la perm Spatie est octroyée.
            // Ce mapping disparaît avec Story 7.3 (sunset bitmask legacy).
            self::AppCustomize => LegacyRight::ComputerInstall,
        };
    }

    /** Retourne le bitmask legacy correspondant */
    public function bitmask(): int
    {
        return $this->legacyRight()->value;
    }

    // ========================================================================
    // LABELS
    // ========================================================================

    public function label(): string
    {
        return match ($this) {
            self::UserPasswordInit => 'Réinitialiser les mots de passe',
            self::UserRead => 'Consulter les utilisateurs',
            self::UserModify => 'Modifier les utilisateurs',
            self::UserCreateTemp => 'Créer des utilisateurs temporaires',
            self::UserAssignRight => 'Assigner des droits',
            self::UserDelegate => 'Déléguer des droits',
            self::ShareView => 'Voir les partages',
            self::ShareRefresh => 'Actualiser les partages',
            self::ShareManage => 'Gérer les partages de classe (ACLs POSIX)',
            self::ComputerView => 'Voir les machines',
            self::ComputerControl => 'Contrôle à distance',
            self::ComputerElevate => 'Admin de poste',
            self::ComputerInstall => 'Installer un poste',
            self::ComputerRemoteRdp => 'Bureau à distance (RDP)',
            self::WpkgAssign => 'Affecter des applications',
            self::WpkgAdd => 'Ajouter des applications',
            self::WpkgCreate => 'Créer des recettes WPKG',
            self::ServerAdmin => 'Administration serveur',
            self::WallpaperManage => 'Gérer les fonds d\'écran',
            self::AppCustomize => 'Personnaliser les applications (Firefox, Thunderbird, …)',
        };
    }

    public function category(): string
    {
        return match ($this) {
            self::UserPasswordInit, self::UserRead, self::UserModify,
            self::UserCreateTemp, self::UserAssignRight, self::UserDelegate => 'user',
            self::ShareView, self::ShareRefresh, self::ShareManage => 'share',
            self::ComputerView, self::ComputerControl,
            self::ComputerElevate, self::ComputerInstall,
            self::ComputerRemoteRdp => 'computer',
            self::WpkgAssign, self::WpkgAdd, self::WpkgCreate => 'wpkg',
            self::ServerAdmin => 'server',
            self::WallpaperManage => 'wallpaper',
            self::AppCustomize => 'app-customization',
        };
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'user' => 'Utilisateurs',
            'share' => 'Partages',
            'computer' => 'Machines',
            'wpkg' => 'Applications WPKG',
            'server' => 'Serveur',
            'wallpaper' => 'Fonds d\'écran',
            'app-customization' => 'Personnalisation applications',
            default => ucfirst($category),
        };
    }

    /** Permissions délégables sur un WorkstationGroup (machines + wpkg) */
    public function isDelegatable(): bool
    {
        return in_array($this->category(), ['computer', 'wpkg']);
    }

    /** Nécessite une synchronisation GPO */
    public function requiresGpoSync(): bool
    {
        return $this === self::ComputerElevate;
    }

    // ========================================================================
    // HELPERS STATIQUES
    // ========================================================================

    /**
     * Indique si la permission est une « secondary bit permission » qui partage
     * son bit legacy avec une permission atomique principale. Story 7.3 :
     * `ComputerRemoteRdp` partage `0x200` avec `ComputerControl`. Ces
     * permissions sont exclues des conversions bitmask ↔ permissions pour
     * éviter de sur-élargir les profils custom rapatriés depuis le LDAP.
     * Elles sont attribuées exclusivement par la migration des délégations
     * legacy (`rdp_<parc>` → `computer.remote.rdp`) et par les rôles seedés
     * éventuels.
     */
    private function isSecondaryBitPermission(): bool
    {
        // Story 5.2 — `ShareManage` partage le bit legacy `ShareRefresh`
        // (cf. mapping ci-dessus). Exclu du bitmask mapping pour ne pas
        // sur-élargir les profils custom rapatriés depuis le LDAP.
        return $this === self::ComputerRemoteRdp || $this === self::ShareManage;
    }

    /**
     * Mapping bitmask → nom de permission Spatie
     *
     * @return array<int, string>
     */
    public static function bitmaskMapping(): array
    {
        $map = [];
        foreach (self::cases() as $perm) {
            if ($perm->isSecondaryBitPermission()) {
                continue;
            }
            $map[$perm->bitmask()] = $perm->value;
        }
        return $map;
    }

    /**
     * Convertit un bitmask legacy en liste de noms de permissions Spatie
     *
     * @return string[]
     */
    public static function fromBitmask(int $bitmask): array
    {
        return array_values(array_map(
            fn(self $p) => $p->value,
            array_filter(
                self::cases(),
                fn(self $p) => ! $p->isSecondaryBitPermission()
                    && ($bitmask & $p->bitmask()) !== 0
            )
        ));
    }

    /**
     * Convertit une liste de noms de permissions en bitmask legacy
     *
     * @param string[] $permissionNames
     */
    public static function toBitmask(array $permissionNames): int
    {
        $bitmask = 0;
        foreach ($permissionNames as $name) {
            $perm = self::tryFrom($name);
            if ($perm !== null) {
                $bitmask |= $perm->bitmask();
            }
        }
        return $bitmask;
    }

    /** Retourne un SambaPermission depuis un bitmask atomique */
    public static function fromSingleBitmask(int $bitmask): ?self
    {
        foreach (self::cases() as $perm) {
            if ($perm->isSecondaryBitPermission()) {
                continue;
            }
            if ($perm->bitmask() === $bitmask) {
                return $perm;
            }
        }
        return null;
    }

    /**
     * Retourne les permissions groupées par catégorie
     * 
     * @return array<string, array{label: string, permissions: SambaPermission[]}>
     */
    public static function groupedByCategory(): array
    {
        $grouped = [];
        foreach (self::cases() as $perm) {
            $cat = $perm->category();
            if (!isset($grouped[$cat])) {
                $grouped[$cat] = [
                    'label' => self::categoryLabel($cat),
                    'permissions' => [],
                ];
            }
            $grouped[$cat]['permissions'][] = $perm;
        }
        return $grouped;
    }

    /** Retourne uniquement les permissions délégables */
    public static function delegatable(): array
    {
        return array_values(array_filter(self::cases(), fn(self $p) => $p->isDelegatable()));
    }
}
