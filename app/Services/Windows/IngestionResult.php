<?php

declare(strict_types=1);

namespace App\Services\Windows;

/**
 * Value-object représentant le résultat d'une ingestion de rapport WPKG.
 */
final class IngestionResult
{
    public const STATUS_PROCESSED   = 'processed';
    public const STATUS_UNCHANGED   = 'unchanged';
    public const STATUS_NOT_FOUND   = 'not_found';
    public const STATUS_PARSE_FAIL  = 'parse_failed';

    private function __construct(
        public readonly string $status,
        public readonly string $hostname,
        public readonly int    $packagesCount = 0,
        public readonly ?string $error        = null,
    ) {}

    public static function processed(string $hostname, int $packagesCount): self
    {
        return new self(self::STATUS_PROCESSED, $hostname, $packagesCount);
    }

    public static function unchanged(string $hostname): self
    {
        return new self(self::STATUS_UNCHANGED, $hostname);
    }

    public static function notFound(string $hostname): self
    {
        return new self(self::STATUS_NOT_FOUND, $hostname, 0, "Workstation '{$hostname}' introuvable.");
    }

    public static function parseFailed(string $hostname): self
    {
        return new self(self::STATUS_PARSE_FAIL, $hostname, 0, 'Impossible de parser le rapport.');
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isUnchanged(): bool
    {
        return $this->status === self::STATUS_UNCHANGED;
    }

    public function isNotFound(): bool
    {
        return $this->status === self::STATUS_NOT_FOUND;
    }

    public function isParseFailed(): bool
    {
        return $this->status === self::STATUS_PARSE_FAIL;
    }
}
