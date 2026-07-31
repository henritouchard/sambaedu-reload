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

    /**
     * **Rend la main sur le verrou d'état, sans rien perdre** — à appeler AVANT
     * tout appel réseau sortant (review 57.2 #1).
     *
     * Le mécanisme natif de PHP verrouille le fichier d'état pendant TOUTE la
     * requête : deux requêtes portant le même cookie se sérialisent. Tenir ce
     * verrou pendant un appel BigBlueButton borné à 8 s, c'est bloquer les
     * AUTRES onglets de la même personne pendant tout ce temps — un prof dont le
     * démarrage traîne ne peut même plus recharger sa liste de salons. Et sur un
     * serveur intégré à 4 workers, c'est autant de workers immobilisés.
     *
     * Ce n'est pas une fermeture : une écriture ultérieure ({@see self::put()})
     * rouvre l'état et reprend le verrou, le temps de cette écriture seulement.
     * Idempotente, et sans effet si rien n'est ouvert.
     */
    public function close(): void;

    /** Vide et clôt l'état courant. */
    public function destroy(): void;
}
