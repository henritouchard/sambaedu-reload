<?php

declare(strict_types=1);

namespace App\Services\ControlHub\Data;

/**
 * Story 28.2 — DTO de résultat de l'ingestion d'un contrat amont (controlHub).
 *
 * Retourné par {@see \App\Services\ControlHub\ControlHubContractIngestionService::ingest()}.
 * Sert aux assertions de test (no-op vs mutation) et au `Log::info` final.
 *
 * Invariant : `$mutated === false` ⇒ no-op fonctionnel (aucune écriture, aucun événement) — NFR4.
 *
 * ⚠️ GARDE-FOU R3 : aucun mot « central » dans ce DTO. [Source: prd-contrat-manage-se5.md#R3]
 */
class ContractIngestionResult
{
    public bool $contractCreated = false;

    /** false ⇒ no-op (aucune écriture fonctionnelle, aucun événement émis). */
    public bool $mutated = false;

    public ?int $contractId = null;

    /** @var array{created: int, updated: int, deleted: int} */
    public array $items = ['created' => 0, 'updated' => 0, 'deleted' => 0];

    /** @var array{created: int, updated: int, deleted: int} */
    public array $labels = ['created' => 0, 'updated' => 0, 'deleted' => 0];

    /** @var array{created: int, updated: int, deleted: int} */
    public array $imposedGroups = ['created' => 0, 'updated' => 0, 'deleted' => 0];

    /** @var array{created: int, updated: int, deleted: int} */
    public array $catalogApps = ['created' => 0, 'updated' => 0, 'deleted' => 0];

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'contract_created' => $this->contractCreated,
            'mutated' => $this->mutated,
            'contract_id' => $this->contractId,
            'items' => $this->items,
            'labels' => $this->labels,
            'imposed_groups' => $this->imposedGroups,
            'catalog_apps' => $this->catalogApps,
        ];
    }
}
