<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Mode d'un label imposé par le contrat amont (controlHub).
 *
 * Story 28.1 — modèle de persistance du contrat amont.
 *
 * - `Free`     (FR: libre)    : le label peut être utilisé librement par l'admin local pour étiqueter des postes.
 * - `Reserved` (FR: réservé)  : le label est réservé à l'autorité amont ; l'instance locale ne peut pas le créer/modifier librement.
 *                              Typiquement associé à un groupe imposé (`controlhub_contract_imposed_groups.label_name`).
 *
 * [Source: prd-contrat-manage-se5.md#§9 ; epics-contrat-manage-se5.md#Story 28.1 AC#1]
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans cette enum ni dans ses valeurs.
 * Préfixe de classe imposé : `ControlHub*`. [Source: prd-contrat-manage-se5.md#R3]
 */
enum ControlHubLabelMode: string
{
    case Free = 'free';
    case Reserved = 'reserved';
}
