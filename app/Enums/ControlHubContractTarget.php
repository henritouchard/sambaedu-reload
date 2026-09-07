<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Cible d'application d'un item imposé par le contrat amont (controlHub).
 *
 * Modèle de persistance du contrat amont.
 *
 * - `Instance` (FR: instance) : l'item s'applique à toute l'instance SE5 (broadcast machine/session).
 * - `Label`    (FR: label)    : l'item s'applique uniquement aux postes portant le label désigné.
 *                              Dans ce cas, `controlhub_contract_items.target_label` porte le nom du label.
 *
 * ⚠️ Convention de nommage : aucun mot « central » dans cette enum ni dans ses valeurs.
 * Préfixe de classe imposé : `ControlHub*`.
 */
enum ControlHubContractTarget: string
{
    case Instance = 'instance';
    case Label = 'label';
}
