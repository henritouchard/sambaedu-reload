<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Http;

/**
 * Story 57.1 — Magasin en mémoire : la doublure de test du magasin natif.
 *
 * Elle existe pour que TOUT le parcours de connexion et toute la page
 * d'administration soient exerçables sans serveur HTTP, sans en-têtes envoyés et
 * sans fichier d'état — donc testables sur l'hôte de développement.
 */
final class ArraySessionStore implements SessionStore
{
    /** @param array<string, mixed> $data */
    public function __construct(private array $data = [])
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function regenerate(): void
    {
        // Rien à faire : il n'y a pas d'identifiant à renouveler en mémoire.
    }

    public function destroy(): void
    {
        $this->data = [];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->data;
    }
}
