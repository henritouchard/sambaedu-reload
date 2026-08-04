<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Enums\FileBackendOutcome;

/**
 * Story 60.4 — résultat de la traduction d'un SUJET DE PLAN en sujet d'ACL.
 *
 * Trois issues, et pas une de plus :
 *  - RÉSOLU : un type d'entrée (`user` ou `group`) et un nom que l'annuaire
 *    connaît — le seul cas où un octroi s'écrit ;
 *  - REFUSÉ EN ÉCHEC : le sujet existe dans le plan mais rien de ce que le
 *    système connaît ne lui correspond (compte introuvable, nom d'ouverture de
 *    session non sûr, groupe système absent de l'annuaire). L'octroi n'est PAS
 *    écrit et le nœud le DIT — c'est l'incident connu du groupe sans suffixe
 *    d'établissement, où l'ancien code posait un octroi qui échouait en silence ;
 *  - REFUSÉ EN DETTE : le mécanisme existe (des groupes d'annuaire par rôle) mais
 *    SE5 ne le projette pas pour ce type de groupe. Temporaire, propriété de
 *    notre code — surtout pas « non exprimable », qui affirmerait une limite
 *    permanente du modèle POSIX qui n'existe pas.
 *
 * Le refus porte son propre état de rapport : c'est lui qui remonte tel quel dans
 * la réconciliation du nœud, sans qu'aucune couche intermédiaire n'ait à le
 * réinterpréter.
 */
final class PosixSubjectProjection
{
    public const TYPE_USER = 'user';

    public const TYPE_GROUP = 'group';

    private function __construct(
        public readonly ?string $type,
        public readonly ?string $name,
        public readonly ?FileBackendOutcome $refusal,
        public readonly ?string $detail,
        public readonly bool $blocking = false,
    ) {
    }

    public static function user(string $login): self
    {
        return new self(self::TYPE_USER, $login, null, null);
    }

    public static function group(string $name): self
    {
        return new self(self::TYPE_GROUP, $name, null, null);
    }

    /** Rien de connu ne correspond à ce sujet — l'octroi n'est pas écrit. */
    public static function echec(string $detail): self
    {
        return new self(null, null, FileBackendOutcome::Echec, $detail);
    }

    /**
     * Refus qui arrête le NŒUD ENTIER, pas seulement l'octroi.
     *
     * **Réservé au doute, pas au manque.** « Ce groupe n'existe pas » est une
     * information : on peut écrire le reste sans elle. « Je n'ai pas pu savoir si
     * ce groupe existe » n'en est pas une — et la suite de la pose commence par
     * PURGER les droits étendus du répertoire. Écrire le reste reviendrait donc à
     * retirer un accès sur la foi d'une question restée sans réponse : une panne
     * de résolution de noms deviendrait une révocation, sur tous les nœuds
     * réconciliés pendant la panne. Le nœud est laissé INTACT et l'état le dit.
     */
    public static function echecBloquant(string $detail): self
    {
        return new self(null, null, FileBackendOutcome::Echec, $detail, true);
    }

    /** SE5 ne projette pas ce rôle pour ce type — dette datée, pas limite. */
    public static function nonImplemente(string $detail): self
    {
        return new self(null, null, FileBackendOutcome::NonImplemente, $detail);
    }

    public function isResolved(): bool
    {
        return $this->refusal === null;
    }
}
