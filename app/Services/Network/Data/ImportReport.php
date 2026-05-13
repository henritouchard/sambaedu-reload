<?php

declare(strict_types=1);

namespace App\Services\Network\Data;

/**
 * Story 8.1 — Rapport complet d'un import CSV de réservations DHCP (FR22).
 *
 * Persisté 24h dans le cache Redis sous `dhcp.import.report.<uuid>` puis
 * lu depuis `/app/network/dhcp/import/{uuid}` (pattern Story 2.6
 * `BulkResetListingService`).
 *
 * Compteurs :
 *  - `total`    : nombre de lignes data lues (hors header).
 *  - `ok`       : créées.
 *  - `updated`  : mises à jour (upsert).
 *  - `errors`   : rejetées.
 *  - `skipped`  : ignorées (vides, commentées, doublons file-local).
 */
final class ImportReport
{
    /**
     * @param  ImportReportRow[]  $rows
     */
    public function __construct(
        public readonly string $uuid,
        public readonly int $total,
        public readonly int $ok,
        public readonly int $updated,
        public readonly int $errors,
        public readonly int $skipped,
        public readonly array $rows,
        public readonly string $createdAt,
    ) {
    }

    /**
     * @return array{uuid:string,total:int,ok:int,updated:int,errors:int,skipped:int,created_at:string,rows:array<int,array<string,mixed>>}
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'total' => $this->total,
            'ok' => $this->ok,
            'updated' => $this->updated,
            'errors' => $this->errors,
            'skipped' => $this->skipped,
            'created_at' => $this->createdAt,
            'rows' => array_map(fn (ImportReportRow $r) => $r->toArray(), $this->rows),
        ];
    }

    /**
     * Recharge un rapport depuis sa représentation cache (array).
     *
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rows = [];
        foreach ($data['rows'] ?? [] as $r) {
            $rows[] = new ImportReportRow(
                line: (int) ($r['line'] ?? 0),
                name: $r['name'] ?? null,
                mac: $r['mac'] ?? null,
                ip: $r['ip'] ?? null,
                status: (string) ($r['status'] ?? 'error'),
                action: (string) ($r['action'] ?? 'none'),
                reason: $r['reason'] ?? null,
            );
        }

        return new self(
            uuid: (string) ($data['uuid'] ?? ''),
            total: (int) ($data['total'] ?? 0),
            ok: (int) ($data['ok'] ?? 0),
            updated: (int) ($data['updated'] ?? 0),
            errors: (int) ($data['errors'] ?? 0),
            skipped: (int) ($data['skipped'] ?? 0),
            rows: $rows,
            createdAt: (string) ($data['created_at'] ?? ''),
        );
    }
}
