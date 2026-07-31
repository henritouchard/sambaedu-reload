<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Oidc;

use SambaEdu\ExtBbb\Store;
use Throwable;

/**
 * Story 57.1 — L'anti-rejeu adossé à la table `oidc_replay` de l'extension.
 *
 * Le témoin SSO de SE5 s'appuyait sur le cache fichier de l'hôte, avec une
 * limite écrite noir sur blanc : « cela ne suffirait pas à une vraie extension,
 * laquelle aura SON stockage ». C'est ce stockage-là. La propriété qui compte
 * — l'atomicité du premier usage — vient de la clé primaire SQLite, pas d'un
 * `SELECT` suivi d'un `INSERT`.
 */
final class SqliteReplayGuard implements ReplayGuard
{
    public function __construct(private readonly Store $store)
    {
    }

    public function consumeOnce(string $jti, int $expiresAt): bool
    {
        try {
            // La mémorisation court jusqu'à `exp + leeway`, pas jusqu'à `exp` :
            // un jeton dont l'expiration est PASSÉE de moins que la tolérance
            // d'horloge est encore accepté par le vérificateur. L'oublier plus
            // tôt rouvrirait une fenêtre de rejeu large comme la tolérance.
            return $this->store->consumeJti($jti, $expiresAt + IdTokenVerifier::LEEWAY);
        } catch (Throwable) {
            // Fail-closed jusqu'au bout : même une base illisible ne fait pas
            // accepter un jeton.
            return false;
        }
    }
}
