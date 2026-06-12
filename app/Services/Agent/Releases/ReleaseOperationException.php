<?php

declare(strict_types=1);

namespace App\Services\Agent\Releases;

use RuntimeException;

/**
 * Story 25.1 — Refus métier d'une opération sur les releases agent (AC1).
 *
 * Levée par {@see ReleaseCreationService} : création refusée (fichier
 * absent/illisible, hash divergent, version dupliquée, formats invalides —
 * AUCUNE ligne écrite) ou version inconnue (promote/target). Les commandes
 * artisan la traduisent en exit ≠ 0 ; `$reason` est le code machine tracé
 * dans le log `agent.release.rejected`.
 */
final class ReleaseOperationException extends RuntimeException
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

    public static function unknownVersion(string $version): self
    {
        return new self('unknown_version', sprintf('Release inconnue : version "%s" absente de agent_releases.', $version));
    }
}
