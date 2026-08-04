<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Models\UserGroup;
use App\Services\Filesystem\Plan\PlanResolver;
use App\Services\Filesystem\ShareService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.1 — LE test de garde de la ligne de coupe.
 *
 * Un plan résolu représentatif est sérialisé, puis on vérifie MÉCANIQUEMENT qu'il
 * ne contient rien du monde d'en dessous : pas de mode de permission, pas de
 * syntaxe de liste d'accès, pas de nom de groupe système dérivé, pas de chemin
 * absolu, pas de nom de commande.
 *
 * Pourquoi ce test est le pivot de la story : le service qui dérive les
 * permissions concrètes existe DÉJÀ, trois fichiers plus loin. Sans garde, il
 * remonterait au-dessus de la ligne à la première commodité, et le plan cesserait
 * d'être portable sans que personne ne s'en aperçoive. Ce test constate la
 * propriété sur la SORTIE ; son jumeau d'architecture la verrouille sur les
 * IMPORTS. Les deux sont nécessaires : l'un attrape la fuite de vocabulaire,
 * l'autre la fuite de dépendance.
 */
class PlanNeutralityGuardTest extends TestCase
{
    use ClassTreeRecipe;
    use PlanNeutralityMarkers;

    #[Test]
    public function a_representative_plan_carries_no_trace_of_the_layer_below(): void
    {
        $plan = (new PlanResolver())->resolve(
            $this->classTreeTemplate(),
            $this->classTreeContext(['_echange' => false]),
        );

        // Le plan doit être représentatif, sinon la garde ne garde rien.
        $this->assertCount(6, $plan->nodes, '4 nœuds fixes + 2 nœuds par membre');
        $this->assertSame(
            ['activable', 'contenu_libre', 'par_membre', 'partagee'],
            $this->sortedNatures($plan->toArray()),
            'les quatre natures doivent être exercées',
        );

        $this->assertPlanIsNeutral($plan);
    }

    #[Test]
    public function no_value_of_the_plan_is_an_absolute_path(): void
    {
        $plan = (new PlanResolver())->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $offenders = [];
        $serializable = $plan->toArray();
        array_walk_recursive($serializable, static function (mixed $value, mixed $key) use (&$offenders): void {
            if (is_string($value) && str_starts_with($value, '/')) {
                $offenders[] = $key . ' => ' . $value;
            }
        });

        $this->assertSame([], $offenders, 'un plan ne porte QUE des chemins relatifs : la racine réelle est un savoir de backend.');
    }

    #[Test]
    public function no_derived_system_group_name_leaks_into_the_plan(): void
    {
        // On calcule ici — dans le TEST, qui a le droit — les noms de groupes
        // système que la couche du dessous dériverait pour ce même groupe, et on
        // exige leur absence. C'est la formulation la plus précise de la garde :
        // ce ne sont pas des mots interdits en général, ce sont EXACTEMENT les
        // artefacts d'implémentation que le plan ne doit pas connaître.
        $group = new UserGroup();
        $group->name = 'Classe_3emeA';
        $group->type = 'classe';

        $localPart = app(ShareService::class)->aclGroupLocalPart($group);
        $this->assertSame('3emea', $localPart, 'le décor doit bien produire une forme minuscule dérivée');

        $serialized = (new PlanResolver())->resolve($this->classTreeTemplate(), $this->classTreeContext())->toJson();

        foreach (["equipe_{$localPart}", "classe_{$localPart}", $localPart] as $derived) {
            $this->assertStringNotContainsString(
                $derived,
                $serialized,
                'nom de groupe système dérivé dans le plan : ' . $derived,
            );
        }
    }

    #[Test]
    public function the_access_vocabulary_of_the_plan_is_exactly_the_two_neutral_levels(): void
    {
        $plan = (new PlanResolver())->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $accesses = [];
        foreach ($plan->nodes as $node) {
            foreach ($node->grants as $grant) {
                $accesses[] = $grant->access;
            }
        }
        $accesses = array_values(array_unique($accesses));
        sort($accesses);

        $this->assertSame(['ro', 'rw'], $accesses);
    }

    /**
     * Méta-test : la garde DÉTECTE réellement, sur une VRAIE sérialisation.
     *
     * Une assertion d'absence ne vaut rien tant qu'on n'a pas prouvé que la
     * présence serait vue. La version naïve de ce méta-test — injecter le marqueur
     * dans une chaîne fabriquée puis vérifier qu'on l'y retrouve — ne prouvait
     * rien : elle testait `assertStringContainsString` contre lui-même, et ne
     * pouvait échouer que sur une faute de frappe. On passe donc le marqueur par
     * le SEUL champ de texte libre d'un plan, le libellé d'un nœud, puis on
     * construit le haystack EXACTEMENT comme le test principal : si la détection
     * le voit là, alors le test principal l'aurait vu aussi.
     *
     * Ce méta-test a déjà servi : sous sa forme d'origine — qui cherchait dans le
     * `toJson()` — il a fait échouer le marqueur `domain\040admins`, révélant que
     * la garde principale était aveugle à lui (le JSON double l'antislash). D'où
     * {@see plainTextOf()}. Un méta-test qui ne peut rien trouver ne sert à rien ;
     * celui-ci a trouvé.
     */
    #[Test]
    public function the_guard_actually_detects_every_marker_it_claims_to_forbid(): void
    {
        foreach (self::forbiddenMarkers() as $label => $marker) {
            $plan = (new PlanResolver())->resolve(
                $this->classTreeTemplate(nodeLabel: 'Dossier ' . $marker),
                $this->classTreeContext(),
            );

            $this->assertStringContainsStringIgnoringCase(
                $marker,
                $this->plainTextOf($plan),
                sprintf(
                    'marqueur « %s » (%s) absent d\'une sérialisation qui le porte : la garde serait aveugle',
                    $marker,
                    $label,
                ),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $planArray
     * @return list<string>
     */
    private function sortedNatures(array $planArray): array
    {
        $natures = array_values(array_unique(array_map(
            static fn (array $n): string => (string) $n['nature'],
            $planArray['nodes'],
        )));
        sort($natures);

        return $natures;
    }
}
