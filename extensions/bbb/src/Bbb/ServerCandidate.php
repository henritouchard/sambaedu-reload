<?php

declare(strict_types=1);

namespace SambaEdu\ExtBbb\Bbb;

/**
 * Story 57.4 — Un serveur RETENU pour le démarrage en cours, avec la charge qui
 * lui a valu son rang.
 *
 * Objet volontairement minuscule : il porte ce dont le démarrage a besoin, et
 * rien de plus. Il ne remplace pas la ligne de la table — il la RÉSUME pour un
 * usage précis, celui de l'instant.
 *
 * ⚠️ `$secret` est le secret partagé du serveur : il ne s'affiche pas, ne se
 * journalise pas, et ne traverse jamais une vue. Sa seule sortie est l'appel
 * signé vers BigBlueButton.
 */
final class ServerCandidate
{
    public function __construct(
        public readonly int $id,
        public readonly string $baseUrl,
        public readonly string $secret,
        /**
         * Le nombre de participants MESURÉ (serveur normal), ou le seuil
         * CONFIGURÉ (Scalelite). Les deux se comparent, mais ils ne se
         * racontent pas pareil : voir {@see self::$delegated}.
         */
        public readonly int $load,
        /**
         * `true` = Scalelite. La valeur n'est alors **pas une mesure** mais un
         * **seuil de délégation** saisi par l'administrateur : « tant qu'un de
         * mes serveurs héberge moins de N participants, garde-le ; au-delà,
         * envoie chez Scalelite, qui fait sa propre répartition ». Sémantique
         * du legacy, reprise telle quelle (D5) — et c'est pourquoi un tel
         * serveur n'est **jamais sondé**.
         */
        public readonly bool $delegated = false,
    ) {
    }
}
