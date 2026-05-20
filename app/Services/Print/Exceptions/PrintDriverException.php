<?php

declare(strict_types=1);

namespace App\Services\Print\Exceptions;

use RuntimeException;

/**
 * Story 6.2 — Exception métier pour les échecs de commandes `rpcclient` /
 * `smbclient` lors de la gestion des pilotes Windows.
 *
 * Décalque `CupsCommandException` (Story 6.1) : wrappe l'erreur avec le
 * contexte exécutionnel (commande exacte, stderr, return code) pour
 * exposition dans `Log::error` côté Service et toast court côté Livewire
 * (cf. AC7 — pas de leak de StackTrace).
 *
 * Sous-classes spécialisées :
 *  - {@see WindowsPivotUnreachableException} — pivot W10 injoignable
 *    (poste éteint, réseau, partage non publié). Sémantique métier
 *    distincte — l'admin doit vérifier l'état du poste.
 *
 * Pour les pannes côté daemon Samba (RC != 0 sur `srvinfo`/`enumdrivers`
 * pour le serveur SER), on lève {@see SambaUnavailableException}
 * (RuntimeException distincte) pour permettre à la commande sync de
 * skip orphan-marking sans confusion avec une erreur de commande.
 */
class PrintDriverException extends RuntimeException
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
     * Première ligne stderr ou message exception (pour toast UI court).
     */
    public function firstStderrLine(): string
    {
        return $this->stderr[0] ?? $this->getMessage();
    }
}
