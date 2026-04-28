<?php

declare(strict_types=1);

namespace App\Services\Print\Exceptions;

use RuntimeException;

/**
 * Story 6.1 — Exception métier pour les échecs de commandes CUPS.
 *
 * Wrappe les erreurs `lpadmin`/`lpstat`/`cupsenable`/`cupsdisable`/`lpinfo` avec
 * leur contexte exécutionnel (commande exacte, stderr, return code) pour
 * exposition dans `Log::error` côté Service et toast côté Livewire (AC5).
 */
class CupsCommandException extends RuntimeException
{
    /**
     * @param  string[]  $stderr
     */
    public function __construct(
        string $message,
        private readonly string $command,
        private readonly array $stderr,
        private readonly int $returnCode,
    ) {
        parent::__construct($message, $returnCode);
    }

    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * @return string[]
     */
    public function getStderr(): array
    {
        return $this->stderr;
    }

    public function getReturnCode(): int
    {
        return $this->returnCode;
    }

    /**
     * Première ligne stderr ou message exception (pour toast UI).
     */
    public function firstStderrLine(): string
    {
        return $this->stderr[0] ?? $this->getMessage();
    }
}
