<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Http;

/**
 * Story 57.1 — L'état par utilisateur, propre à l'extension.
 *
 * ⚠️ Ce magasin est **celui de l'extension**, jamais celui de SE5. L'extension
 * n'a aucun accès à l'état serveur du core — c'est précisément ce que le test
 * d'architecture FR33 verrouille. Ce qu'elle a, c'est le mécanisme NATIF de PHP,
 * qui est de l'infrastructure d'hébergement, pas de la donnée de SE5.
 *
 * Interface plutôt qu'appel direct aux superglobales : c'est ce qui rend les
 * contrôleurs testables sans serveur HTTP ni en-têtes envoyés.
 */
interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function put(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function forget(string $key): void;

    /** Nouvel identifiant, contenu conservé — à la promotion d'anonyme à connecté. */
    public function regenerate(): void;

    /** Vide et clôt l'état courant. */
    public function destroy(): void;
}
