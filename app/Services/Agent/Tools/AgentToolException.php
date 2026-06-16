<?php

declare(strict_types=1);

namespace App\Services\Agent\Tools;

use RuntimeException;

/**
 * Story 25.6 — Refus métier d'une opération sur le catalogue d'outils agent
 * (AC2, pattern {@see \App\Services\Agent\Releases\ReleaseOperationException}).
 *
 * Levée par {@see AgentToolService} : upload refusé (extension/MIME non
 * conforme, taille hors borne, version/filename malformés, structure ZIP
 * invalide — `Rainmeter.exe` + `Skins/` attendus à la racine, hachage
 * impossible — AUCUNE ligne écrite, aucun fichier orphelin laissé). `$reason`
 * est le code machine tracé dans le log `agent.tool.rejected` ; `$message` est
 * un texte lisible affiché en `toastError` côté UI (jamais une 500).
 */
final class AgentToolException extends RuntimeException
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function rejected(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
