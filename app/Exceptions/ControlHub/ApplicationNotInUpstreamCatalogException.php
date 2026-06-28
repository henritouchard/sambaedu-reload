<?php

declare(strict_types=1);

namespace App\Exceptions\ControlHub;

use RuntimeException;

/**
 * Story 31.1 — Levée lorsqu'un refnum tente d'installer/assigner une application
 * **hors du catalogue applicatif faisant autorité** d'un contrat amont controlHub
 * actif (FR5). L'opération est REFUSÉE en couche service ({@see \App\Services\AppProfile\AppProfileService})
 * AVANT toute écriture pivot.
 *
 * Defense-in-depth : la consultation ({@see \App\Models\Application::scopeInUpstreamCatalog})
 * retire déjà les apps hors catalogue des listes proposées ; cette exception est
 * le filet de sécurité contre un payload Livewire forgé visant un `application_id`
 * hors catalogue (D3 — deux couches symétriques à 29.1).
 *
 * Le message est en français et **affichable** (repris tel quel en toast via
 * {@see \App\Components\Traits\WithToasts}). Il nomme explicitement les `app_id`
 * refusés. [Story 31.1 AC #2 / FR8]
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans le nom de l'exception ni dans ses
 *    messages. Vocabulaire imposé : « amont » / `Upstream` / `ControlHub*`.
 *    [Source: prd-contrat-manage-se5.md#R3]
 *
 * Patron : {@see UpstreamLockCollisionException} (30.5) + `InvalidUpstreamContractException` (28.2).
 */
final class ApplicationNotInUpstreamCatalogException extends RuntimeException
{
    /**
     * @param  list<string>  $appIds  Les `app_id` refusés (hors catalogue amont).
     */
    public static function fromAppIds(array $appIds): self
    {
        $count = count($appIds);
        $sample = implode(', ', array_slice($appIds, 0, 10));
        $liste = $count > 10 ? "{$sample}…" : $sample;

        return new self(sprintf(
            'Installation refusée : %s « %s » hors catalogue amont. '
            ."Le contrat reçu de l'autorité amont restreint les applications "
            .'installables au catalogue applicatif faisant autorité.',
            $count > 1 ? 'les applications' : "l'application",
            $liste,
        ));
    }
}
