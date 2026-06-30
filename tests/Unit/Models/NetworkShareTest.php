<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\NetworkShare;
use App\Models\NetworkShareAssignable;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 34.1 — modèle `NetworkShare` + pivot polymorphe + relations inverses.
 * 100 % SQL (zéro AD/LdapRecord).
 */
class NetworkShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function exposes_the_frozen_drives_type_constant(): void
    {
        self::assertSame('drives', NetworkShare::TYPE_DRIVES);
    }

    #[Test]
    public function effective_label_falls_back_to_name_when_blank(): void
    {
        $a = NetworkShare::factory()->create(['name' => 'Direction', 'label' => null]);
        $b = NetworkShare::factory()->create(['name' => 'Direction', 'label' => 'Échanges direction']);
        $c = NetworkShare::factory()->create(['name' => 'Direction', 'label' => '']);

        self::assertSame('Direction', $a->effectiveLabel());
        self::assertSame('Échanges direction', $b->effectiveLabel());
        self::assertSame('Direction', $c->effectiveLabel());
    }

    #[Test]
    public function morphed_relations_return_typed_collections_with_access_pivot(): void
    {
        $share = NetworkShare::factory()->create();
        $user = User::factory()->create();
        $group = UserGroup::create(['name' => 'profs', 'type' => 'equipe']);
        $wg = WorkstationGroup::factory()->logical()->create();

        $share->users()->attach($user->id, ['access' => 'rw']);
        $share->userGroups()->attach($group->id, ['access' => 'ro']);
        $share->workstationGroups()->attach($wg->id, ['access' => 'ro']);

        self::assertTrue($share->users->contains($user));
        self::assertSame('rw', $share->users->first()->pivot->access);
        self::assertTrue($share->userGroups->contains($group));
        self::assertTrue($share->workstationGroups->contains($wg));
        // assignments() = vue brute du pivot (3 lignes).
        self::assertCount(3, $share->assignments);
    }

    #[Test]
    public function inverse_network_shares_relations_resolve_from_each_assignable(): void
    {
        $share = NetworkShare::factory()->create();
        $user = User::factory()->create();
        $group = UserGroup::create(['name' => '6a', 'type' => 'classe']);
        $wg = WorkstationGroup::factory()->physical()->create();

        $share->users()->attach($user->id, ['access' => 'rw']);
        $share->userGroups()->attach($group->id, ['access' => 'ro']);
        $share->workstationGroups()->attach($wg->id, ['access' => 'ro']);

        self::assertTrue($user->networkShares->contains($share));
        self::assertSame('rw', $user->networkShares->first()->pivot->access);
        self::assertTrue($group->networkShares->contains($share));
        self::assertTrue($wg->networkShares->contains($share));
    }

    #[Test]
    public function unique_constraint_blocks_duplicate_assignment(): void
    {
        $share = NetworkShare::factory()->create();
        $user = User::factory()->create();

        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => User::class,
            'assignable_id' => $user->id,
            'access' => 'ro',
        ]);

        $this->expectException(QueryException::class);
        NetworkShareAssignable::create([
            'network_share_id' => $share->id,
            'assignable_type' => User::class,
            'assignable_id' => $user->id,
            'access' => 'rw',
        ]);
    }

    #[Test]
    public function directory_name_is_unique(): void
    {
        NetworkShare::factory()->create(['directory_name' => 'direction']);

        $this->expectException(QueryException::class);
        NetworkShare::factory()->create(['directory_name' => 'direction']);
    }

    #[Test]
    public function deleting_a_share_cascades_its_assignments(): void
    {
        $share = NetworkShare::factory()->create();
        $user = User::factory()->create();
        $share->users()->attach($user->id, ['access' => 'ro']);
        self::assertSame(1, NetworkShareAssignable::count());

        $share->delete();
        self::assertSame(0, NetworkShareAssignable::count());
    }

    #[Test]
    public function created_by_user_id_nulls_on_user_deletion(): void
    {
        $author = User::factory()->create();
        $share = NetworkShare::factory()->create(['created_by_user_id' => $author->id]);

        $author->delete();

        self::assertNull($share->fresh()->created_by_user_id);
    }
}
