<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Enums\WorkstationEnvironment;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 26.1 — AC2 : cast enum `environment` sur `WorkstationGroup`.
 *
 * Round-trip set/get (l'enum est persistée sous sa valeur string et relue en
 * instance d'enum), null → null (distinction « non déclaré » / déclaré, D2).
 */
class WorkstationGroupEnvironmentCastTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Neutraliser les observers AD (host sans LDAP).
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function environment_is_cast_to_enum_on_round_trip(): void
    {
        $group = WorkstationGroup::factory()->create([
            'environment' => WorkstationEnvironment::Nomade,
        ]);

        $fresh = WorkstationGroup::query()->findOrFail($group->id);

        self::assertInstanceOf(WorkstationEnvironment::class, $fresh->environment);
        self::assertSame(WorkstationEnvironment::Nomade, $fresh->environment);
        self::assertSame('nomade', $fresh->getRawOriginal('environment'));
    }

    #[Test]
    public function null_environment_stays_null(): void
    {
        $group = WorkstationGroup::factory()->create(['environment' => null]);

        $fresh = WorkstationGroup::query()->findOrFail($group->id);

        self::assertNull($fresh->environment);
    }

    #[Test]
    public function environment_is_mass_assignable(): void
    {
        $group = WorkstationGroup::factory()->create();
        $group->fill(['environment' => WorkstationEnvironment::PersonalLocal]);
        $group->save();

        self::assertSame(
            WorkstationEnvironment::PersonalLocal,
            WorkstationGroup::query()->findOrFail($group->id)->environment,
        );
    }
}
