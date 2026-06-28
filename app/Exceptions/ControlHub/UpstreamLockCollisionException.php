<?php

declare(strict_types=1);

namespace App\Exceptions\ControlHub;

use App\Services\ControlHub\Resolution\UpstreamLockCollision;
use RuntimeException;

/**
 * Story 30.5 — Levée lorsqu'une assignation de label à un parc, ou le
 * rattachement d'un poste à un parc labellisé, INTRODUIRAIT une collision
 * **insoluble** : deux items amont (controlHub) VERROUILLÉS (`locked`) imposant
 * des valeurs CONTRADICTOIRES sur la MÊME propriété exclusive (`exclusiveKey`)
 * d'un même poste (FR13). L'opération est alors REFUSÉE avant toute écriture.
 *
 * Le message est en français et **affichable** (repris tel quel en toast via
 * {@see \App\Components\Traits\WithToasts}). Il nomme explicitement, pour chaque
 * collision : la propriété en conflit, les deux labels en cause, les deux valeurs
 * contradictoires, les deux sources amont (`sourceId`) et le périmètre (postes
 * touchés). [Story 30.5 AC #1/#4/#7]
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans le nom de l'exception ni dans ses
 *    messages. Vocabulaire imposé : « amont » / `controlHub` / `label`. [prd#R3]
 *
 * Patron : {@see LabelAssignmentException} (30.2) + `InvalidUpstreamContractException`
 * (28.2).
 */
final class UpstreamLockCollisionException extends RuntimeException
{
    /**
     * @param  list<UpstreamLockCollision>  $collisions
     */
    public static function fromCollisions(array $collisions): self
    {
        $lines = array_map(static function (UpstreamLockCollision $c): string {
            $count = count($c->workstationIds);
            $sample = implode(', ', array_slice($c->workstationIds, 0, 5));
            $perimeter = $count > 5 ? "{$sample}… ({$count} postes)" : "{$count} poste".($count > 1 ? 's' : '')." ({$sample})";

            return sprintf(
                'la propriété « %s » serait imposée à deux valeurs contradictoires : '
                .'« %s » via le label « %s » (source amont #%d) et « %s » via le label « %s » (source amont #%d) — %s concerné%s.',
                $c->exclusiveKey,
                $c->displayValueA(),
                $c->labelA,
                $c->sourceIdA,
                $c->displayValueB(),
                $c->labelB,
                $c->sourceIdB,
                $perimeter,
                $count > 1 ? 's' : '',
            );
        }, $collisions);

        return new self(
            "Assignation refusée : elle créerait une collision insoluble entre deux verrous amont. "
            .implode(' ', $lines)
            ." Détachez l'un des labels en conflit avant de poursuivre."
        );
    }
}
