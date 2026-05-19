<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.1 — T1.6 / Story 3.2 — D1 / Story 3.2 — Correctif review #B3 / Q4.
 *
 * Type de menu iPXE rendu par {@see \App\Ipxe\Services\IpxeMenuRenderer}.
 *
 * **Valeurs utilisées comme `$kind` dans le logging structuré** (cf.
 * `IpxeService::safeRender()` et `safeActionRender()`). Avant le correctif
 * review #B3, ces valeurs étaient des strings hardcodées éparpillées dans
 * `IpxeService` (`'admin_handshake'`, `'admin_menu'`, etc.) — risque de
 * typo + dead-code enum. Désormais source de vérité unique ici.
 *
 * - `handshake`            : handshake iPXE générique (boot — story 3.1).
 * - `default`              : poste inconnu (résolution Workstation = null).
 *   Menu minimal : boot disk only (D6).
 * - `known`                : poste résolu en base. Menu enrichi : login
 *   (chain vers `/ipxe/admin` natif depuis 3.2), default, action.
 * - `unknown`              : variant log de `default` quand utilisé comme
 *   `$kind` dans `safeRender()`.
 * - `admin`                : menu admin natif rendu (`safeRender`).
 * - `admin_handshake`      : handshake de l'endpoint `/ipxe/admin`.
 * - `admin_menu`           : alias log du rendu menu admin (semantic legacy).
 * - `maintenance`          : menu maintenance natif rendu.
 * - `maintenance_handshake`: handshake de l'endpoint `/ipxe/maintenance`.
 * - `maintenance_menu`     : alias log du rendu menu maintenance.
 * - `action`               : rendu d'un script d'action whitelisté.
 * - `action_handshake`     : handshake de l'endpoint `/ipxe/action/{action}`.
 *
 * Extensible Stories 3.3+ (enrollment, install, clonezilla).
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
}
