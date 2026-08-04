<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem\Plan;

use App\Enums\PlanNodeNature;
use App\Exceptions\Filesystem\PlanResolutionException;
use App\Services\Filesystem\Plan\FilePlan;
use App\Services\Filesystem\Plan\PlanGrant;
use App\Services\Filesystem\Plan\PlanNode;
use App\Services\Filesystem\Plan\PlanResolver;
use App\Services\Filesystem\Plan\PlanSubject;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 60.1 — le plan est SÉRIALISABLE et COMPARABLE.
 *
 * Sans sérialisation identique octet pour octet à état identique, la détection
 * d'écart par comparaison (story 60.4) serait mort-née : un plan qui change de
 * forme sans changer de sens produirait un écart fantôme à chaque passage.
 */
class FilePlanSerializationTest extends TestCase
{
    use ClassTreeRecipe;

    private PlanResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new PlanResolver();
    }

    #[Test]
    public function two_resolutions_of_the_same_state_serialize_identically(): void
    {
        $first = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());
        $second = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $this->assertSame($first->toJson(), $second->toJson());
    }

    #[Test]
    public function the_serialization_does_not_depend_on_the_input_ordering(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        // Mêmes nœuds, mêmes octrois, ordre d'entrée inversé : même sortie.
        $shuffled = new FilePlan(
            $plan->templateKey,
            $plan->rootPath,
            array_reverse($plan->roles, preserve_keys: true),
            array_reverse($plan->nodes),
        );

        $this->assertSame($plan->toJson(), $shuffled->toJson());
    }

    #[Test]
    public function grants_are_ordered_by_subject_then_access(): void
    {
        $node = new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee, [
            new PlanGrant('b', PlanSubject::group(9), 'rw'),
            new PlanGrant('a', PlanSubject::user(4), 'ro'),
            new PlanGrant('c', PlanSubject::group(2, 'owner'), 'ro'),
        ]);

        $this->assertSame(
            [
                [PlanSubject::TYPE_USER, 4],
                [PlanSubject::TYPE_USER_GROUP, 2],
                [PlanSubject::TYPE_USER_GROUP, 9],
            ],
            array_map(static fn (PlanGrant $g): array => [$g->subject->type, $g->subject->id], $node->grants),
        );
    }

    #[Test]
    public function the_round_trip_loses_nothing(): void
    {
        $plan = $this->resolver->resolve(
            $this->classTreeTemplate(),
            $this->classTreeContext(['_echange' => false]),
        );

        $revived = FilePlan::fromJson($plan->toJson());

        $this->assertSame($plan->toJson(), $revived->toJson());
        $this->assertEquals($plan->toArray(), $revived->toArray());
    }

    #[Test]
    public function nature_state_quota_authority_and_closure_all_survive_the_round_trip(): void
    {
        $plan = $this->resolver->resolve(
            $this->classTreeTemplate(),
            $this->classTreeContext(['_echange' => false]),
        );

        $revived = FilePlan::fromJson($plan->toJson());

        // Nature + autorité sur les enfants.
        $this->assertSame(PlanNodeNature::ContenuLibre, $revived->node('_travail/devoirs')->nature);
        $this->assertFalse($revived->node('_travail/devoirs')->governsChildren());
        $this->assertTrue($revived->node('_travail')->governsChildren());

        // Plafond.
        $this->assertSame(2147483648, $revived->node('bmartin')->plafond);

        // État actif / suspendu — et le suspendu n'est PAS devenu une absence.
        $echange = $revived->node('_echange');
        $this->assertFalse($echange->active);
        $this->assertCount(1, $echange->suspendedGrants());
        $this->assertCount(1, $echange->activeGrants());

        // Clôture.
        $this->assertSame(['classe'], $revived->node('_profs')->closure);
        $this->assertSame(['classe', 'referents'], $revived->node('bmartin')->closure);

        // Rôles et leurs sujets (ce qui rend la clôture actionnable).
        $this->assertSame(['classe', 'equipe', 'referents'], $revived->roleKeys());
        $this->assertSame('owner', $revived->roles['referents'][0]->edgeRole);
    }

    #[Test]
    public function the_serialized_form_carries_its_format_version(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());

        $this->assertSame(FilePlan::VERSION, $plan->toArray()['version']);

        $this->expectException(PlanResolutionException::class);
        FilePlan::fromArray(['version' => 99] + $plan->toArray());
    }

    #[Test]
    public function a_plan_cannot_describe_the_same_folder_twice(): void
    {
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/même chemin/u');

        new FilePlan('probe', 'Classes/Classe_3emeA', [], [
            new PlanNode('_travail', 'Travail', PlanNodeNature::Partagee),
            new PlanNode('_travail', 'Doublon', PlanNodeNature::Partagee),
        ]);
    }

    #[Test]
    public function a_plan_root_is_never_absolute(): void
    {
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/chemins relatifs/u');

        new FilePlan('probe', '/var/sambaedu/Classes/Classe_3emeA');
    }

    #[Test]
    public function an_unknown_nature_cannot_be_revived_from_a_serialized_plan(): void
    {
        $plan = $this->resolver->resolve($this->classTreeTemplate(), $this->classTreeContext());
        $array = $plan->toArray();
        $array['nodes'][0]['nature'] = 'optionnelle';

        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/nature de nœud inconnue/u');

        FilePlan::fromArray($array);
    }

    #[Test]
    public function an_inactive_node_of_a_nature_that_cannot_be_suspended_is_refused(): void
    {
        // Garde-fou de forme : « inactif » n'a de sens que sur un nœud activable.
        $this->expectException(PlanResolutionException::class);
        $this->expectExceptionMessageMatches('/peut être inactif/u');

        new PlanNode('_profs', 'Profs', PlanNodeNature::Partagee, [], active: false);
    }
}
