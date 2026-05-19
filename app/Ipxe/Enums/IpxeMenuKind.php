<?php

declare(strict_types=1);

namespace App\Ipxe\Enums;

/**
 * Story 3.1 — T1.6.
 *
 * Type de menu iPXE rendu par {@see \App\Ipxe\Services\IpxeMenuRenderer}.
 *
 * - `handshake` : premier appel iPXE sans paramètres → renvoi du préambule
 *   `params + param mac/uuid/product + chain ...##params`. Le firmware
 *   ré-appelle ensuite avec les paramètres posés.
 * - `default`   : poste inconnu (résolution `WorkstationLocator` = null).
 *   Menu minimal : boot disk only (D6).
 * - `known`     : poste résolu en base. Menu enrichi : login (3.2 placeholder),
 *   default, action (3.2+ conditionnel).
 *
 * Extensible Stories 3.2+ (login admin, enrollment, install, clonezilla).
 */
enum IpxeMenuKind: string
{
    case Handshake = 'handshake';
    case Default_ = 'default';
    case Known = 'known';
}
