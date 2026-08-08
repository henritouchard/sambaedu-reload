<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Backend\Support;

use App\Enums\PlanNodeNature;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanResolver;
use Tests\Unit\Services\Filesystem\Plan\ClassTreeRecipe;

/**
 * Story 60.3 — LE PLAN CLASSE, RACINE COMPRISE.
 *
 * Le décor de recette des stories 60.1/60.2 est réutilisé TEL QUEL et résolu par
 * le résolveur RÉEL : ce qui est éprouvé ici est donc un plan authentique, pas une
 * maquette. Une seule chose lui est ajoutée : le nœud RACINE.
 *
 * **Pourquoi la racine est construite à la main.** Le vocabulaire de recette
 * n'accepte pas encore « . » comme chemin de nœud écrit — c'est un legs nommé à la
 * story 60.5, celle qui exprimera les droits de la racine du partage classe.
 * Or la racine est indispensable ICI : sans octroi posé plus haut, il n'y a pas
 * d'ancêtre, donc pas de propagation, donc rien à éprouver du mode de rupture
 * mesuré. On ajoute donc le nœud que la recette ne sait pas encore dire, avec les
 * octrois du partage classe historique : l'équipe en écriture, la classe en
 * lecture (la traversée).
 *
 * **La clôture de la racine est DÉRIVÉE**, comme celle de tous les autres nœuds :
 * rôles de la recette moins rôles octroyés ici. La recopier à la main aurait fait
 * du décor une seconde implémentation de la règle — et deux implémentations d'une
 * règle finissent par diverger.
 */
trait RootedClassPlan
{
    use ClassTreeRecipe;

    /**
     * Le plan classe résolu, augmenté de son nœud racine.
     *
     * @param  array<string,bool>  $nodeActivation
     */
    protected function rootedClassPlan(array $nodeActivation = []): FilePlan
    {
        $template = $this->classTreeTemplate();
        $plan = (new PlanResolver())->resolve($template, $this->classTreeContext($nodeActivation));

        return new FilePlan(
            $plan->templateKey,
            $plan->rootPath,
            $plan->roles,
            [...$plan->nodes, $this->rootNodeFor($plan)],
        );
    }

    /**
     * Le nœud racine : l'équipe en écriture, la classe en lecture (traversée).
     * Sa clôture est calculée sur les rôles du plan, jamais saisie.
     */
    private function rootNodeFor(FilePlan $plan): PlanNode
    {
        $grants = [
            new PlanGrant('equipe', $plan->roles['equipe'][0], PlanGrant::VERBS),
            new PlanGrant('classe', $plan->roles['classe'][0], [PlanGrant::VERB_LIRE]),
        ];

        $granted = [];
        foreach ($grants as $grant) {
            $granted[$grant->roleKey] = true;
        }

        return new PlanNode(
            PlanNode::ROOT_PATH,
            'Racine du partage de classe',
            PlanNodeNature::Partagee,
            $grants,
            true,
            null,
            array_values(array_filter(
                $plan->roleKeys(),
                static fn (string $role): bool => ! isset($granted[$role]),
            )),
        );
    }
}
