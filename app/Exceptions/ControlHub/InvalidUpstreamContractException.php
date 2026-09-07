<?php

declare(strict_types=1);

namespace App\Exceptions\ControlHub;

use RuntimeException;

/**
 * Levée lorsqu'un payload de contrat amont (controlHub) est rejeté à l'ingestion.
 *
 * Causes — aucune contrainte `CHECK` en base ne rattrape ces erreurs :
 * - valeur hors domaine pour `enforcement_state` (≠ locked|permissive|absent),
 *   `target_type` (≠ instance|label) ou `mode` de label (≠ free|reserved) ;
 * - incohérence de cible : `target_type=label` avec `target_label` vide,
 *   ou `target_type=instance` avec `target_label` non vide ;
 * - champ structurant manquant (type/key d'item, name de label/groupe, app_key) ;
 * - intégrité référentielle `imposed_groups.label_name` : un `label_name` non-nul
 *   ne référençant aucun label déclaré dans le même payload.
 *
 * La levée survient **avant toute écriture** (validation pure en amont de la transaction),
 * ce qui garantit l'absence d'écriture partielle (rollback total).
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
