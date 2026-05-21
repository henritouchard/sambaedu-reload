<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.2 — D9 / AC1.1.
 * Story 3.4 — AC1.1 — extension +9 cases install_*.
 *
 * Whitelist stricte des actions iPXE admin disponibles via la route native
 * `GET|POST /ipxe/action/{action}`.
 *
 * **Sécurité critique** — l'enum est l'UNIQUE source de vérité des actions
 * autorisées. Toute valeur reçue côté `IpxeActionController` est validée via
 * `IpxeAdminAction::tryFrom()` :
 *
 *  - `null` retourné  → 404 + log warning `ipxe.action.unknown_action`.
 *  - case retourné    → dispatch vers le template Blade correspondant.
 *
 * **12 cases stricts post-3.4** (3 historiques + 9 nouveaux Linux install) :
 *
 *  - 3 cases 3.2 (maintenance) :
 *     - `Rescuecd`     — boot SystemRescueCD (réparation/diagnostic).
 *     - `Winpe`        — boot Windows PE (réparation Windows).
 *     - `FactoryReset` — restauration clonezilla sda2 → sda1.
 *
 *  - 9 cases 3.4 (installation Linux) :
 *     - `InstallDebBase`     — Debian sans desktop (serveur léger).
 *     - `InstallDebCinnamon` — Debian + Cinnamon.
 *     - `InstallDebGnome`    — Debian + GNOME (défaut menu).
 *     - `InstallDebKde`      — Debian + KDE.
 *     - `InstallDebLxde`     — Debian + LXDE.
 *     - `InstallDebMate`     — Debian + MATE.
 *     - `InstallDebXfce`     — Debian + XFCE.
 *     - `InstallNird`        — NIRD (Debian dérivée écoles primaires, perso=1).
 *     - `InstallUbuntu64`    — Ubuntu Focal 20.04 (hors domaine, perso=1).
 *
 * **Anti-pattern** : pas de méthode `execute()` ni `dispatch()` sur l'enum —
 * la résolution + exécution sont portées par
 * {@see \App\Ipxe\Services\IpxeActionResolver}.
 *
 * **HORS-SCOPE 3.4 (déférés Phase 3)** : `se4ad`, `se4fs`, `deb_serv`,
 * `deb_kiosk`, `deb_nextcloud`, `deb_gnome_perso`, `primtux`.
 */
enum IpxeAdminAction: string
{
    case Rescuecd = 'rescuecd';
    case Winpe = 'winpe';
    case FactoryReset = 'factory_reset';

    // Story 3.4 — D1 / AC1.1 — 9 cases install_*.
    case InstallDebBase = 'install_deb_base';
    case InstallDebCinnamon = 'install_deb_cinnamon';
    case InstallDebGnome = 'install_deb_gnome';
    case InstallDebKde = 'install_deb_kde';
    case InstallDebLxde = 'install_deb_lxde';
    case InstallDebMate = 'install_deb_mate';
    case InstallDebXfce = 'install_deb_xfce';
    case InstallNird = 'install_nird';
    case InstallUbuntu64 = 'install_ubuntu64';

    /**
     * Retourne le chemin du template Blade rendu par
     * {@see \App\Ipxe\Services\IpxeActionResolver::resolve()}.
     */
    public function template(): string
    {
        return match ($this) {
            self::Rescuecd => 'ipxe.actions.rescuecd',
            self::Winpe => 'ipxe.actions.winpe',
            self::FactoryReset => 'ipxe.actions.factory_reset',
            self::InstallDebBase => 'ipxe.actions.install_deb_base',
            self::InstallDebCinnamon => 'ipxe.actions.install_deb_cinnamon',
            self::InstallDebGnome => 'ipxe.actions.install_deb_gnome',
            self::InstallDebKde => 'ipxe.actions.install_deb_kde',
            self::InstallDebLxde => 'ipxe.actions.install_deb_lxde',
            self::InstallDebMate => 'ipxe.actions.install_deb_mate',
            self::InstallDebXfce => 'ipxe.actions.install_deb_xfce',
            self::InstallNird => 'ipxe.actions.install_nird',
            self::InstallUbuntu64 => 'ipxe.actions.install_ubuntu64',
        };
    }

    /**
     * Retourne la string snake_case utilisée pour le logging structuré
     * (`ipxe.action.dispatched` context `action` + `MachineBootLog.initiated_by`
     * `ipxe:<value>`).
     */
    public function logName(): string
    {
        return $this->value;
    }

    /**
     * Story 3.4 — AC1.1 — retourne les metadata Linux d'un case install_*.
     *
     * Pour les 9 cases install_* : retourne `['distribution' => 'debian|ubuntu|nird',
     * 'variant' => 'base|gnome|lxde|kde|mate|xfce|cinnamon']`.
     *
     * Pour les 3 cases existants (rescuecd, winpe, factory_reset) : retourne `null`.
     *
     * Utilisé par {@see \App\Ipxe\Services\IpxeActionResolver::resolve()} pour
     * injecter les variables Blade `$osVersion`, `$installType`, `$preseedUrl`
     * dans les templates `ipxe.actions.install_*`.
     *
     * @return array{distribution:string, variant:string}|null
     */
    public function linuxMeta(): ?array
    {
        return match ($this) {
            self::InstallDebBase => ['distribution' => 'debian', 'variant' => 'base'],
            self::InstallDebCinnamon => ['distribution' => 'debian', 'variant' => 'cinnamon'],
            self::InstallDebGnome => ['distribution' => 'debian', 'variant' => 'gnome'],
            self::InstallDebKde => ['distribution' => 'debian', 'variant' => 'kde'],
            self::InstallDebLxde => ['distribution' => 'debian', 'variant' => 'lxde'],
            self::InstallDebMate => ['distribution' => 'debian', 'variant' => 'mate'],
            self::InstallDebXfce => ['distribution' => 'debian', 'variant' => 'xfce'],
            self::InstallNird => ['distribution' => 'nird', 'variant' => 'base'],
            self::InstallUbuntu64 => ['distribution' => 'ubuntu', 'variant' => 'base'],
            default => null,
        };
    }
}
