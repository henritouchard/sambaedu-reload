<?php

declare(strict_types=1);

namespace App\Services\Network\Exceptions;

use RuntimeException;

/**
 * Story 8.1 — Exception métier pour les échecs de commandes DHCP.
 *
 * Wrappe les erreurs `make_dhcpd_conf.sh` / `systemctl` avec leur contexte
 * exécutionnel (commande exacte, stderr, return code).
 *
 * Pattern aligné `App\Services\Print\Exceptions\CupsCommandException`
 * (Story 6.1). Réservé aux échecs commande individuelle ; pour daemon
 * injoignable (= `systemctl is-active` retourne non-zero sans stderr),
 * utiliser `DhcpDaemonDownException`.
 */
class DhcpCommandException extends RuntimeException
{
    /**
     * @param  string[]  $stderr
     */
    public function __construct(
        string $message,
        private readonly string $command,
        private readonly array $stderr,
        private readonly int $returnCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $returnCode, $previous);
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
