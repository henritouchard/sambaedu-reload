<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend\Posix;

use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 62.5 — UN COULOIR D'ACCÈS DÉRIVÉ : « ce sujet doit pouvoir PASSER par ce
 * nœud, parce qu'un nœud plus profond lui accorde quelque chose ».
 *
 * Ce n'est PAS un octroi. Le plan n'en sait rien et n'a rien à en savoir : il ne
 * porte que ce que l'administrateur a écrit, en quatre verbes
 * ({@see \App\Services\Filesystem\Plan\PlanGrant}). Le couloir est une conséquence
 * MÉCANIQUE de la façon dont ce serveur de fichiers rend l'atteignabilité, calculée
 * sous la ligne de contrat et recalculée à chaque passage — jamais stockée.
 *
 * Trois champs, et les deux derniers ne servent qu'à PARLER : le sujet est ce qui
 * s'écrit, les rôles et les chemins profonds sont ce qui se dit à l'administrateur
 * quand quelque chose ne va pas (une projection qui refuse, un couloir absent à la
 * relecture). Sans eux, un rapport dirait « il manque quelque chose » sans jamais
 * dire à qui ni pour aller où.
 */
final class PosixTraversal
{
    /**
     * @param  PlanSubject  $subject  le sujet qui doit pouvoir passer
     * @param  list<string>  $roleKeys  rôles de recette qui motivent le couloir, triés
     * @param  list<string>  $nodePaths  chemins RELATIFS des nœuds profonds servis, triés
     */
    public function __construct(
        public readonly PlanSubject $subject,
        public readonly array $roleKeys,
        public readonly array $nodePaths,
    ) {
    }

    /** Clé stable du sujet — l'identité du couloir sur un nœud donné. */
    public function key(): string
    {
        return $this->subject->sortKey();
    }
}
