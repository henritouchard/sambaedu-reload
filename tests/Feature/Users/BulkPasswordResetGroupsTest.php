<?php

declare(strict_types=1);

namespace Tests\Feature\Users;

use App\Models\User as SqlUserModel;
use App\Models\UserGroup;
use App\Repositories\GroupRepository;
use App\Repositories\RightRepository;
use App\Repositories\UserGroupRepository;
use App\Services\UserGroupService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests groupes — résolution non-récursive + dédup (story 2.6, AC 7, 11).
 *
 * Utilise des mocks sur GroupRepository pour simuler l'AD (les tests
 * unitaires ne doivent pas dépendre d'un AD réel).
 */
class BulkPasswordResetGroupsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Créer un schéma minimal pour users + user_groups en SQLite :memory:
        if (!Schema::hasTable('users')) {
            Schema::create('users', function ($table): void {
                $table->id();
                $table->string('login')->unique();
                $table->string('fullname')->nullable();
                $table->string('firstname')->nullable();
                $table->string('lastname')->nullable();
                $table->string('email')->nullable();
                $table->string('role')->default('eleve');
                $table->string('dn')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamp('pwd_reset_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function ($table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->string('type')->default('custom');
                $table->string('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function ($table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('user_group_id');
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function direct_members_dedupe_by_login(): void
    {
        $group1 = UserGroup::create(['name' => 'Classe_6A', 'display_name' => '6A', 'type' => 'classe']);
        $group2 = UserGroup::create(['name' => 'Classe_6B', 'display_name' => '6B', 'type' => 'classe']);

        // Alice est membre des DEUX groupes — la dédup doit ne la garder qu'une fois
        SqlUserModel::create(['login' => 'alice', 'lastname' => 'A', 'firstname' => 'Alice']);
        SqlUserModel::create(['login' => 'bob', 'lastname' => 'B', 'firstname' => 'Bob']);

        // Mock GroupRepository — simule une lecture AD
        $groupRepo = Mockery::mock(GroupRepository::class);
        $groupRepo->shouldReceive('getGroupMembers')
            ->with('Classe_6A')
            ->andReturn(collect([
                ['cn' => 'alice', 'dn' => 'CN=alice,OU=Eleves', 'displayName' => 'Alice A', 'mail' => null],
                ['cn' => 'bob', 'dn' => 'CN=bob,OU=Eleves', 'displayName' => 'Bob B', 'mail' => null],
            ]));
        $groupRepo->shouldReceive('getGroupMembers')
            ->with('Classe_6B')
            ->andReturn(collect([
                ['cn' => 'alice', 'dn' => 'CN=alice,OU=Eleves', 'displayName' => 'Alice A', 'mail' => null],
            ]));

        $service = new UserGroupService(
            Mockery::mock(UserGroupRepository::class),
            $groupRepo,
            Mockery::mock(RightRepository::class),
        );

        $result = $service->getDirectMembersForBulkReset([$group1->id, $group2->id]);

        $logins = $result['users']->pluck('login')->all();
        $this->assertCount(2, $logins);
        $this->assertContains('alice', $logins);
        $this->assertContains('bob', $logins);

        // Alice a été ramenée par 6A (premier groupe dans l'ordre)
        $this->assertSame($group1->id, $result['login_to_source_group']['alice']['id']);
        $this->assertSame($group1->id, $result['login_to_source_group']['bob']['id']);
    }

    #[Test]
    public function direct_members_ignores_users_not_in_sql(): void
    {
        $group = UserGroup::create(['name' => 'Classe_7A', 'display_name' => '7A', 'type' => 'classe']);

        SqlUserModel::create(['login' => 'carol', 'lastname' => 'C', 'firstname' => 'Carol']);
        // 'dave' n'est PAS dans la table SQL users → doit être skippé silencieusement

        $groupRepo = Mockery::mock(GroupRepository::class);
        $groupRepo->shouldReceive('getGroupMembers')
            ->with('Classe_7A')
            ->andReturn(collect([
                ['cn' => 'carol', 'dn' => 'CN=carol,OU=Eleves', 'displayName' => 'Carol C', 'mail' => null],
                ['cn' => 'dave', 'dn' => 'CN=dave,OU=Eleves', 'displayName' => 'Dave D', 'mail' => null],
            ]));

        $service = new UserGroupService(
            Mockery::mock(UserGroupRepository::class),
            $groupRepo,
            Mockery::mock(RightRepository::class),
        );

        $result = $service->getDirectMembersForBulkReset([$group->id]);

        $logins = $result['users']->pluck('login')->all();
        $this->assertCount(1, $logins);
        $this->assertContains('carol', $logins);
        $this->assertNotContains('dave', $logins);
    }

    #[Test]
    public function empty_group_list_returns_empty_collection(): void
    {
        $service = new UserGroupService(
            Mockery::mock(UserGroupRepository::class),
            Mockery::mock(GroupRepository::class),
            Mockery::mock(RightRepository::class),
        );

        $result = $service->getDirectMembersForBulkReset([]);

        $this->assertTrue($result['users']->isEmpty());
        $this->assertSame([], $result['login_to_source_group']);
    }

    #[Test]
    public function missing_ad_group_is_skipped_silently(): void
    {
        $group = UserGroup::create(['name' => 'Classe_Ghost', 'display_name' => 'Ghost', 'type' => 'classe']);

        $groupRepo = Mockery::mock(GroupRepository::class);
        $groupRepo->shouldReceive('getGroupMembers')
            ->with('Classe_Ghost')
            ->andReturn(collect([]));

        $service = new UserGroupService(
            Mockery::mock(UserGroupRepository::class),
            $groupRepo,
            Mockery::mock(RightRepository::class),
        );

        $result = $service->getDirectMembersForBulkReset([$group->id]);

        $this->assertTrue($result['users']->isEmpty());
    }

    #[Test]
    public function ad_exception_is_caught_and_other_groups_continue(): void
    {
        $brokenGroup = UserGroup::create(['name' => 'Broken', 'display_name' => 'Broken', 'type' => 'classe']);
        $okGroup = UserGroup::create(['name' => 'Ok', 'display_name' => 'Ok', 'type' => 'classe']);

        SqlUserModel::create(['login' => 'eve', 'lastname' => 'E', 'firstname' => 'Eve']);

        $groupRepo = Mockery::mock(GroupRepository::class);
        $groupRepo->shouldReceive('getGroupMembers')
            ->with('Broken')
            ->andThrow(new \RuntimeException('AD down'));
        $groupRepo->shouldReceive('getGroupMembers')
            ->with('Ok')
            ->andReturn(collect([
                ['cn' => 'eve', 'dn' => 'CN=eve,OU=Eleves', 'displayName' => 'Eve E', 'mail' => null],
            ]));

        $service = new UserGroupService(
            Mockery::mock(UserGroupRepository::class),
            $groupRepo,
            Mockery::mock(RightRepository::class),
        );

        $result = $service->getDirectMembersForBulkReset([$brokenGroup->id, $okGroup->id]);

        $logins = $result['users']->pluck('login')->all();
        $this->assertContains('eve', $logins);
    }
}
