<?php

declare(strict_types=1);

namespace App\Exceptions\ControlHub;

use App\Models\WorkstationGroup;
use RuntimeException;

/**
 * Story 30.2 — Levée lorsqu'une assignation de label de contrat amont
 * (controlHub) à un WorkstationGroup est refusée par le service de mapping.
 *
 * Quatre causes de refus (matrice de validation 30.2) :
 * - {@see noActiveContract()} : aucun contrat amont actif — rien à assigner.
 * - {@see unknown()}          : le label n'existe pas parmi les labels du
 *                               contrat actif (AC #6).
 * - {@see reserved()}         : le label est en mode `reserved`, réservé à
 *                               l'autorité amont, non attribuable (AC #5).
 * - {@see alreadyLabeled()}   : le groupe porte déjà un label différent —
 *                               invariant « 1 label max » (AC #4). Ré-assigner
 *                               le MÊME label reste idempotent (pas d'exception).
 *
 * Les messages sont en français et **affichables** (repris tels quels en toast
 * via {@see \App\Components\Traits\WithToasts}).
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans le nom de l'exception ni dans ses
 *    messages. Vocabulaire imposé : « amont » / `controlHub` / `label`. [prd#R3]
 *
 * Patron : {@see InvalidUpstreamContractException} (28.2).
 */
final class LabelAssignmentException extends RuntimeException
{
    /** Aucun contrat amont actif : il n'existe aucun label à assigner. */
    public static function noActiveContract(): self
    {
        return new self('Aucun contrat amont actif : aucun label ne peut être assigné.');
    }

    /** Le label n'est pas déclaré par le contrat amont actif (AC #6). */
    public static function unknown(string $name): self
    {
        return new self("Label « {$name} » inconnu : il n'est pas déclaré par le contrat amont actif.");
    }

    /** Le label est réservé à l'autorité amont, non attribuable (AC #5). */
    public static function reserved(string $name): self
    {
        return new self("Label « {$name} » réservé à l'autorité amont, non attribuable.");
    }

    /**
     * Le groupe porte déjà un label différent — invariant « 1 label max » (AC #4).
     */
    public static function alreadyLabeled(WorkstationGroup $group, string $name): self
    {
        return new self(
            "Le groupe « {$group->name} » porte déjà le label « {$group->controlhub_label} » : "
            . "détachez-le avant d'assigner « {$name} » (au plus un label par groupe)."
        );
    }
}
