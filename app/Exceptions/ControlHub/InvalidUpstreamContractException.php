<?php

declare(strict_types=1);

namespace App\Exceptions\ControlHub;

use RuntimeException;

/**
 * Story 28.2 — Levée lorsqu'un payload de contrat amont (controlHub) est rejeté à l'ingestion.
 *
 * Causes (HANDOFF 28.1 #3 — il n'existe aucun `CHECK` DB pour rattraper l'erreur) :
 * - valeur hors domaine pour `enforcement_state` (≠ locked|permissive|absent),
 *   `target_type` (≠ instance|label) ou `mode` de label (≠ free|reserved) ;
 * - incohérence de cible : `target_type=label` avec `target_label` vide,
 *   ou `target_type=instance` avec `target_label` non vide ;
 * - champ structurant manquant (type/key d'item, name de label/groupe, app_key) ;
 * - intégrité référentielle `imposed_groups.label_name` : un `label_name` non-nul
 *   ne référençant aucun label déclaré dans le même payload (FR9, Story 30.1).
 *
 * La levée survient **avant toute écriture** (validation pure en amont de la transaction),
 * ce qui garantit l'absence d'écriture partielle (rollback total — AC #6).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans le nom de l'exception ni dans ses messages.
 * Vocabulaire imposé : « amont » / `upstream` / `authority`. [Source: prd-contrat-manage-se5.md#R3]
 */
final class InvalidUpstreamContractException extends RuntimeException
{
    /**
     * Construit une exception portant la clé/champ fautif dans le message.
     */
    public static function for(string $field, string $reason): self
    {
        return new self("Contrat amont invalide — {$field} : {$reason}");
    }
}
