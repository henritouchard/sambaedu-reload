<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Agent;

use App\Enums\WorkstationEnvironment;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\WorkstationEnvironmentResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 26.1 — AC3 : résolution multi-parcs de l'environnement de poste.
 *
 * Précédence `nomade > personal_local > shared_local`, défaut `shared_local`
 * (poste sans groupe, ou tous les parcs à null). Lecture Postgres only — le
 * service ne touche jamais l'AD/APCu (vérifié par construction : il ne fait
 * que des requêtes Eloquent sur `workstation_groups`).
 */
class WorkstationEnvironmentResolverTest extends TestCase
{
    use RefreshDatabase;

    private WorkstationEnvironmentResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        // Projection Postgres-pure : neutraliser les observers AD (host sans LDAP).
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->resolver = new WorkstationEnvironmentResolver();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    private function group(?WorkstationEnvironment $env, bool $physical = false): WorkstationGroup
    {
        return WorkstationGroup::factory()->create([
            'is_physical' => $physical,
            'environment' => $env,
        ]);
    }

    private function workstationInGroups(WorkstationGroup ...$groups): Workstation
    {
        $ws = Workstation::factory()->create();
        $ws->groups()->attach(collect($groups)->pluck('id')->all());

        return $ws;
    }

    #[Test]
    public function singleton_is_bound_in_the_container(): void
    {
        self::assertInstanceOf(
            WorkstationEnvironmentResolver::class,
            app(WorkstationEnvironmentResolver::class),
        );
        self::assertSame(
            app(WorkstationEnvironmentResolver::class),
            app(WorkstationEnvironmentResolver::class),
        );
    }

    #[Test]
    public function precedence_covers_every_enum_case(): void
    {
        // Garde-fou (review S1) : la précédence DOIT être exhaustive, sinon une
        // case ajoutée plus tard (Epic 27) serait ignorée silencieusement et
        // retomberait sur shared_local même si seule déclarée.
        self::assertEqualsCanonicalizing(
            WorkstationEnvironment::cases(),
            WorkstationEnvironmentResolver::PRECEDENCE,
        );
    }

    #[Test]
    public function workstation_without_any_group_defaults_to_shared_local(): void
    {
        $ws = Workstation::factory()->create();

        self::assertSame(WorkstationEnvironment::SharedLocal, $this->resolver->resolve($ws));
    }

    #[Test]
    public function groups_all_null_default_to_shared_local(): void
    {
        $ws = $this->workstationInGroups(
            $this->group(null, physical: true),
            $this->group(null),
        );

        self::assertSame(WorkstationEnvironment::SharedLocal, $this->resolver->resolve($ws));
    }

    #[Test]
    public function single_shared_local_group_resolves_shared_local(): void
    {
        $ws = $this->workstationInGroups($this->group(WorkstationEnvironment::SharedLocal));

        self::assertSame(WorkstationEnvironment::SharedLocal, $this->resolver->resolve($ws));
    }

    #[Test]
    public function personal_local_wins_over_shared_local(): void
    {
        $ws = $this->workstationInGroups(
            $this->group(WorkstationEnvironment::SharedLocal),
            $this->group(WorkstationEnvironment::PersonalLocal),
        );

        self::assertSame(WorkstationEnvironment::PersonalLocal, $this->resolver->resolve($ws));
    }

    #[Test]
    public function nomade_wins_over_everything(): void
    {
        $ws = $this->workstationInGroups(
            $this->group(WorkstationEnvironment::SharedLocal),
            $this->group(WorkstationEnvironment::PersonalLocal),
            $this->group(WorkstationEnvironment::Nomade),
        );

        self::assertSame(WorkstationEnvironment::Nomade, $this->resolver->resolve($ws));
    }

    #[Test]
    public function nomade_wins_over_shared_local_even_without_personal(): void
    {
        $ws = $this->workstationInGroups(
            $this->group(WorkstationEnvironment::SharedLocal),
            $this->group(WorkstationEnvironment::Nomade),
        );

        self::assertSame(WorkstationEnvironment::Nomade, $this->resolver->resolve($ws));
    }

    #[Test]
    public function mixed_null_and_value_ignores_null_and_keeps_the_declared_one(): void
    {
        $ws = $this->workstationInGroups(
            $this->group(null),
            $this->group(WorkstationEnvironment::PersonalLocal),
            $this->group(null, physical: true),
        );

        self::assertSame(WorkstationEnvironment::PersonalLocal, $this->resolver->resolve($ws));
    }

    #[Test]
    public function resolve_for_group_ids_empty_list_defaults_to_shared_local(): void
    {
        self::assertSame(
            WorkstationEnvironment::SharedLocal,
            $this->resolver->resolveForGroupIds([]),
        );
    }

    #[Test]
    public function resolve_for_group_ids_applies_precedence_on_already_resolved_ids(): void
    {
        $shared = $this->group(WorkstationEnvironment::SharedLocal);
        $nomade = $this->group(WorkstationEnvironment::Nomade);

        self::assertSame(
            WorkstationEnvironment::Nomade,
            $this->resolver->resolveForGroupIds([$shared->id, $nomade->id]),
        );
    }
}
