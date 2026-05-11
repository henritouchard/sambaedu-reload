<?php

declare(strict_types=1);

namespace App\Services\Network\Data;

/**
 * Story 8.1 — Ligne du rapport d'import CSV (FR22).
 *
 * Stocké dans le cache Redis (24h) sous `dhcp.import.report.<uuid>` puis
 * affiché dans la page `/app/network/dhcp/import/{uuid}`.
 *
 * `status` ∈ { ok, error, skipped }
 *  - ok      : ligne intégrée (créée ou mise à jour).
 *  - error   : ligne rejetée (validation, doublon avec autre clé, etc.).
 *  - skipped : ligne ignorée (vide, commentée, doublon dans le même fichier).
 *
 * `action` ∈ { created, updated, none } (renseigné uniquement si status=ok).
 */
final class ImportReportRow
{
    public function __construct(
        public readonly int $line,
        public readonly ?string $name,
        public readonly ?string $mac,
        public readonly ?string $ip,
        public readonly string $status,
        public readonly string $action = 'none',
        public readonly ?string $reason = null,
    ) {
    }

    public static function ok(int $line, string $name, string $mac, string $ip, string $action): self
    {
        return new self($line, $name, $mac, $ip, 'ok', $action, null);
    }

    public static function error(int $line, ?string $name, ?string $mac, ?string $ip, string $reason): self
    {
        return new self($line, $name, $mac, $ip, 'error', 'none', $reason);
    }

    public static function skipped(int $line, ?string $reason = null): self
    {
        return new self($line, null, null, null, 'skipped', 'none', $reason);
    }

    /**
     * @return array{line:int,name:?string,mac:?string,ip:?string,status:string,action:string,reason:?string}
     */
    public function toArray(): array
    {
        return [
            'line' => $this->line,
            'name' => $this->name,
            'mac' => $this->mac,
            'ip' => $this->ip,
            'status' => $this->status,
            'action' => $this->action,
            'reason' => $this->reason,
        ];
    }
}
