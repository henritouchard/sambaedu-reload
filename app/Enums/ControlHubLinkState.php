<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * État du lien entre cette instance SE5 et l'autorité amont (controlHub).
 *
 * Modèle de persistance du contrat amont.
 *
 * - `Active`   (FR: actif)   : le lien est opérationnel ; le contrat reçu s'applique.
 * - `Severed`  (FR: coupé)   : le lien est rompu / révoqué ; l'état local prime.
 *
 * ⚠️ Convention de nommage : aucun mot « central » dans cette enum ni dans ses valeurs.
 * Préfixe de classe imposé : `ControlHub*`.
 */
enum ControlHubLinkState: string
{
    case Active = 'active';
    case Severed = 'severed';
}
