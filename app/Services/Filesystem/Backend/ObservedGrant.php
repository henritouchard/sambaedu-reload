<?php

declare(strict_types=1);

namespace App\Services\Filesystem\Backend;

use App\Exceptions\Filesystem\InvalidBackendReportException;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanSubject;

/**
 * Story 60.3 — un octroi RELU côté backend, dit en VOCABULAIRE DE PLAN.
 *
 * Un sujet ({@see PlanSubject} : identité interne SE5) et une LISTE DE VERBES
 * (story 62.4). Jamais un nom de groupe système, jamais un login d'exécution, jamais
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
 *
 * ---------------------------------------------------------------------------
 * **Story 60.4 → 62.4 — L'OBSERVATION SAIT DIRE « AUCUN », LE PLAN NON.**
 *
 * Une relecture peut trouver un octroi PRÉSENT et VIDE. C'est la forme
 * matérialisée d'une suspension : l'octroi existe, il ne donne rien, le dossier et
 * les données restent. Tant que ce vocabulaire manquait, l'état relu ne pouvait
 * pas distinguer « octroi vide » de « pas d'octroi » — et la comparaison
 * désiré/observé aurait donc lu une suspension appliquée comme une matérialisation
 * manquante, ou pire, une suspension NON appliquée comme conforme. C'était le legs
 * le plus subtil de la story 60.3 ; il s'est soldé en 60.4 par un troisième
 * niveau d'accès nommé, et il se dit désormais par la LISTE DE VERBES VIDE.
 *
 * **L'asymétrie avec {@see PlanGrant} est VOULUE, et c'est le point.** Le plan dit
 * une INTENTION : un octroi y porte toujours au moins un verbe, jamais « rien » —
 * le constructeur du plan refuse la liste vide, et la suspension y a son propre
 * drapeau. Le disque, lui, dit une FORME, et « une entrée présente à zéro droit »
 * en est une. Autoriser la liste vide côté désir referait au niveau des mots
 * l'erreur que le modèle a démontée au niveau des concepts : suspension, absence et
 * interdiction sont trois choses différentes.
 */
final class ObservedGrant
{
    public readonly PlanSubject $subject;

    /**
     * Les verbes OBSERVÉS, en ordre canonique. La liste VIDE est licite ici, et
     * elle signifie « entrée présente, aucun droit » — jamais « pas d'entrée ».
     *
     * @var list<string>
     */
    public readonly array $verbs;

    /** @param list<string> $verbs liste de verbes du plan, VIDE autorisée */
    public function __construct(PlanSubject $subject, array $verbs)
    {
        $seen = [];
        foreach ($verbs as $verb) {
            if (! is_string($verb) || ! in_array($verb, PlanGrant::VERBS, true)) {
                throw InvalidBackendReportException::make(sprintf(
                    'verbe observé inconnu « %s » (attendu : %s) — une observation se dit dans le vocabulaire '
                    . 'du plan, pas dans celui du backend.',
                    is_scalar($verb) ? (string) $verb : gettype($verb),
                    implode('|', PlanGrant::VERBS),
                ));
            }
            $seen[$verb] = true;
        }

        $this->subject = $subject;
        // Même ordre canonique que le plan : sans lui, l'égalité d'ensembles de la
        // comparaison désiré/observé dépendrait de l'ordre de lecture du disque.
        $this->verbs = array_values(array_filter(
            PlanGrant::VERBS,
            static fn (string $v): bool => isset($seen[$v]),
        ));
    }

    /** L'entrée est PRÉSENTE et ne donne rien — la suspension, matérialisée. */
    public function isEmpty(): bool
    {
        return $this->verbs === [];
    }

    /** Clé de tri STABLE : (sujet, verbes canoniques). */
    public function sortKey(): string
    {
        return $this->subject->sortKey() . "\0" . implode(',', $this->verbs);
    }

    /** @return array{subject:array{type:string,id:int,edge_role:string|null},verbs:list<string>} */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject->toArray(),
            'verbs' => $this->verbs,
        ];
    }
}
