<?php

declare(strict_types=1);

namespace App\Exceptions\ControlHub;

use RuntimeException;

/**
 * Levée lorsqu'un refnum tente d'installer/assigner une application
 * **hors du catalogue applicatif faisant autorité** d'un contrat amont controlHub
 * actif. L'opération est REFUSÉE en couche service ({@see \App\Services\AppProfile\AppProfileService})
 * AVANT toute écriture pivot.
 *
 * Defense-in-depth : la consultation ({@see \App\Models\Application::scopeInUpstreamCatalog})
 * retire déjà les apps hors catalogue des listes proposées ; cette exception est
 * le filet de sécurité contre un payload Livewire forgé visant un `application_id`
 * hors catalogue : les deux couches sont symétriques.
 *
 * Le message est en français et **affichable** (repris tel quel en toast via
 * {@see \App\Components\Traits\WithToasts}). Il nomme explicitement les `app_id`
 * refusés.
 *
 * Patron : {@see UpstreamLockCollisionException} + `InvalidUpstreamContractException`.
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
