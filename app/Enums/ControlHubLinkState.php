<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État du lien entre cette instance SE5 et l'autorité amont (controlHub).
 *
 * Story 28.1 — modèle de persistance du contrat amont.
 *
 * - `Active`   (FR: actif)   : le lien est opérationnel ; le contrat reçu s'applique.
 * - `Severed`  (FR: coupé)   : le lien est rompu / révoqué ; l'état local prime.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans cette enum ni dans ses valeurs.
 * Préfixe de classe imposé : `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
enum ControlHubLinkState: string
{
    case Active = 'active';
    case Severed = 'severed';
}
