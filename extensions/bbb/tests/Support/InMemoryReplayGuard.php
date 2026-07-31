<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Tests\Support;

use SambaEdu\ExtBbb\Oidc\IdTokenVerifier;
use SambaEdu\ExtBbb\Oidc\ReplayGuard;

/**
 * Anti-rejeu en mémoire : exerce le VÉRIFICATEUR sans base.
 *
 * Le mécanisme réel — l'atomicité de la clé primaire SQLite — est prouvé à part
 * dans `StoreTest`. Ici, ce qui compte est que le vérificateur consomme le `jti`
 * au bon moment, c'est-à-dire en DERNIER.
 */
final class InMemoryReplayGuard implements ReplayGuard
{
    /** @var array<string, true> */
    private array $seen = [];

    public function __construct(private readonly bool $available = true)
    {
    }

    public function consumeOnce(string $jti, int $expiresAt): bool
    {
        // Même borne que l'implémentation SQLite : `exp + leeway`.
        if (! $this->available || $jti === '' || $expiresAt + IdTokenVerifier::LEEWAY <= time()) {
            return false;
        }

        if (isset($this->seen[$jti])) {
            return false;
        }

        $this->seen[$jti] = true;

        return true;
    }
}
