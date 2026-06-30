<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Data;

/**
 * Story 32.1 — Résultat d'une tentative de rupture du lien amont (controlHub).
 *
 * Porte le verdict (rupture réellement appliquée vs no-op idempotent) et les
 * compteurs récapitulatifs (items levés, apps conservées, valeurs effectives
 * matérialisées). Consommé par la commande artisan et l'endpoint pour leur sortie.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central ». [Source: prd-contrat-manage-se5.md#R3]
 */
final readonly class ContractSeveranceResult
{
    public function __construct(
        /** La rupture a-t-elle été appliquée (true) ou s'agit-il d'un no-op idempotent (false) ? */
        public bool $severed,
        /** Id du contrat rompu (null si no-op : aucun contrat actif). */
        public ?int $contractId,
        /** Nombre d'items imposés levés par la rupture. */
        public int $itemsLifted,
        /** Nombre d'`Application` conservées et tracées (`managed_by_control_hub`). */
        public int $appsPreserved,
        /** Nombre de valeurs de capacité matérialisées (défaut d'instance `capabilities.default_value` posé + overrides `capability_assignments` par parc porteur de label), sans double comptage. */
        public int $valuesMaterialized,
        /** Nombre d'affectations app conservées (défaut d'instance `Application.is_parc_default` posé + affectations app↔parc porteur de label NOUVELLEMENT créées). */
        public int $applicationsAssigned = 0,
    ) {
    }

    /** No-op idempotent : aucun contrat actif à rompre. */
    public static function noop(): self
    {
        return new self(
            severed: false,
            contractId: null,
            itemsLifted: 0,
            appsPreserved: 0,
            valuesMaterialized: 0,
            applicationsAssigned: 0,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'severed' => $this->severed,
            'contract_id' => $this->contractId,
            'items_lifted' => $this->itemsLifted,
            'apps_preserved' => $this->appsPreserved,
            'values_materialized' => $this->valuesMaterialized,
            'applications_assigned' => $this->applicationsAssigned,
        ];
    }
}
