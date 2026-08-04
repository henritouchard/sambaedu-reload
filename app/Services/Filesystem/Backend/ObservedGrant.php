<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 60.3 — un octroi RELU côté backend, dit en VOCABULAIRE DE PLAN.
 *
 * Un sujet ({@see PlanSubject} : identité interne SE5) et un niveau d'accès
 * (`ro|rw`). Jamais un nom de groupe système, jamais un login d'exécution, jamais
 * un identifiant produit par le backend distant. La reprojection de ce que le
 * backend connaît vers l'identité interne est un savoir d'IMPLÉMENTATION : le
 * backend POSIX la fera par son index de projection (60.4), un backend distant par
 * son cache d'identifiants (61.3). Si un nom système remontait ici, la ligne de
 * coupe serait franchie par la relecture — porte dérobée exacte de celle que les
 * gardes ferment côté écriture.
 *
 * **Pas de clé de rôle, et c'est délibéré.** L'état distant ne connaît pas les
 * rôles de la recette : il connaît des sujets et des accès. La comparaison
 * désiré/observé — qui, elle, raisonne en rôles et en clôtures — s'implémentera UNE
 * fois au-dessus de la ligne, en 60.4. Faire porter un rôle à une observation
 * obligerait chaque backend à le deviner, et deux backends le devineraient
 * différemment.
 */
final class ObservedGrant
{
    public readonly PlanSubject $subject;

    /** `ro` ou `rw` — le vocabulaire d'accès du plan, sans traduction. */
    public readonly string $access;

    public function __construct(PlanSubject $subject, string $access)
    {
        if (! in_array($access, PlanGrant::ACCESSES, true)) {
            throw InvalidBackendReportException::make(sprintf(
                'accès observé inconnu « %s » (attendu : %s) — une observation se dit dans le vocabulaire '
                . 'du plan, pas dans celui du backend.',
                $access,
                implode('|', PlanGrant::ACCESSES),
            ));
        }

        $this->subject = $subject;
        $this->access = $access;
    }

    /** Clé de tri STABLE : (sujet, accès). */
    public function sortKey(): string
    {
        return $this->subject->sortKey() . "\0" . $this->access;
    }

    /** @return array{subject:array{type:string,id:int,edge_role:string|null},access:string} */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject->toArray(),
            'access' => $this->access,
        ];
    }
}
