<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Type de menu iPXE rendu par {@see \App\Ipxe\Services\IpxeMenuRenderer}.
 *
 * **Valeurs utilisées comme `$kind` dans le logging structuré** (cf.
 * `IpxeService::safeRender()` et `safeActionRender()`). Cette enum est la
 * source de vérité unique de ces libellés : les avoir en strings dans
 * `IpxeService` exposait aux typos silencieuses dans les logs.
 *
 * - `handshake` : handshake iPXE générique (boot —).
 * - `default`              : poste inconnu (résolution Workstation = null).
 *   Menu minimal : boot disk only.
 * - `known`                : poste résolu en base. Menu enrichi : login
 *  (chain vers `/ipxe/admin` natif depuis), default, action.
 * - `unknown`              : variant log de `default` quand utilisé comme
 *  `$kind` dans `safeRender()`.
 * - `admin`                : menu admin natif rendu (`safeRender`).
 * - `admin_handshake`      : handshake de l'endpoint `/ipxe/admin`.
 * - `admin_menu`           : alias log du rendu menu admin (semantic legacy).
 * - `maintenance`          : menu maintenance natif rendu.
 * - `maintenance_handshake`: handshake de l'endpoint `/ipxe/maintenance`.
 * - `maintenance_menu`     : alias log du rendu menu maintenance.
 * - `action`               : rendu d'un script d'action whitelisté.
 * - `action_handshake`     : handshake de l'endpoint `/ipxe/action/{action}`.
 *
 * Extension +2 cases (`installation_linux_handshake`,
 *   `installation_linux_menu`) pour l'endpoint `/ipxe/installation-linux`.
 *
 * Extension +2 cases (`installation_windows_handshake`,
 *   `installation_windows_menu`) pour l'endpoint `/ipxe/installation-windows`.
 *
 * Extensible (enrollment, install, clonezilla).
 */
enum IpxeMenuKind: string
{
    case Handshake = 'handshake';
    case Default_ = 'default';
    case Known = 'known';
    case Unknown = 'unknown';
    case Admin = 'admin';
    case AdminHandshake = 'admin_handshake';
    case AdminMenu = 'admin_menu';
    case Maintenance = 'maintenance';
    case MaintenanceHandshake = 'maintenance_handshake';
    case MaintenanceMenu = 'maintenance_menu';
    case Action = 'action';
    case ActionHandshake = 'action_handshake';

    case InstallationLinuxHandshake = 'installation_linux_handshake';
    case InstallationLinuxMenu = 'installation_linux_menu';

    case InstallationWindowsHandshake = 'installation_windows_handshake';
    case InstallationWindowsMenu = 'installation_windows_menu';

    case ClonezillaMenu = 'clonezilla_menu';
    case ClonezillaMenuHandshake = 'clonezilla_menu_handshake';

    // Fix install-debian — écran one-shot « installation Linux terminée »
    // (compte à rebours + boot disque local) affiché au 1er boot post-install.
    case LinuxInstallDone = 'linux_install_done';
}
