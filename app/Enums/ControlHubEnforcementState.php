<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État d'enforcement d'un item imposé par le contrat amont (controlHub).
 *
 * Story 28.1 — modèle de persistance du contrat amont.
 *
 * - `Locked`     (FR: verrouillé) : l'item est imposé et non surchargeable par l'instance.
 * - `Permissive` (FR: permissif)  : l'item est imposé mais l'instance peut le surcharger localement.
 * - `Absent`     (FR: absent)     : l'item est explicitement retiré / absent (autorité amont indique ne pas gérer ce type).
 *
 * Correspond aux trois positions du modèle contrat amont SE5.
 * [Source: prd-contrat-manage-se5.md#§5 ; handoff-controlhub-contrat-manage.md#§3]
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans cette enum ni dans ses valeurs.
 * Préfixe de classe imposé : `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
enum ControlHubEnforcementState: string
{
    case Locked = 'locked';
    case Permissive = 'permissive';
    case Absent = 'absent';
}
