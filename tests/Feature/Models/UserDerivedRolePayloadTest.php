<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 42.1 (review #1) — Tests du payload de sync pivot à rôle dérivé
 * {@see User::userGroupSyncPayloadWithDerivedRole()}, consommé par les
 * écrivains pivot UI hors import (fiche user `syncGroupsFromAd`, drawer
 * « Gestion des groupes »).
 *
 * Vérifie : dérivation `prof`→manager / autre→member sur les arêtes NOUVELLES,
 * et non-réécriture des arêtes EXISTANTES (un `owner` promu survit à un
 * `sync()` / `syncWithoutDetaching()` passant par le payload).
 */
class UserDerivedRolePayloadTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTables();
        // Le sync Eloquent dispatche les events pivot → ShareService, et
        // UserGroup::create → job AD (FS/AD absents en test) : on coupe les
        // deux observers + Queue::fake, patron GroupShowMembersTabsTest.
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        if ($this->createdTables) {
            Schema::dropIfExists('user_group_user');
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    #[Test]
    public function a_prof_gets_manager_on_new_edges_only(): void
    {
        $prof = User::create(['login' => 'prof.un', 'role' => 'prof', 'is_active' => true]);
        $g = UserGroup::create(['name' => '3A', 'display_name' => '3A', 'type' => 'classe']);

        $payload = $prof->userGroupSyncPayloadWithDerivedRole([$g->id]);
        $this->assertSame([$g->id => ['role' => UserGroupUserPivot::ROLE_MANAGER]], $payload);

        $prof->userGroups()->sync($payload);
        $this->assertSame(UserGroupUserPivot::ROLE_MANAGER, $this->role($g->id, $prof->id));
    }

    #[Test]
    public function an_eleve_gets_member_on_new_edges(): void
    {
        $eleve = User::create(['login' => 'eleve.un', 'role' => 'eleve', 'is_active' => true]);
        $g = UserGroup::create(['name' => '3A', 'display_name' => '3A', 'type' => 'classe']);

        $eleve->userGroups()->sync($eleve->userGroupSyncPayloadWithDerivedRole([$g->id]));

        $this->assertSame(UserGroupUserPivot::ROLE_MEMBER, $this->role($g->id, $eleve->id));
    }

    #[Test]
    public function an_existing_owner_edge_survives_resync_through_the_payload(): void
    {
        // Un PP (`owner`, miroir is_head_teacher) déjà rattaché ne doit JAMAIS
        // être rétrogradé par un resync UI (sync() complet ou
        // syncWithoutDetaching) passant par le payload dérivé.
        $pp = User::create(['login' => 'prof.pp', 'role' => 'prof', 'is_active' => true]);
        $g = UserGroup::create(['name' => '3A', 'display_name' => '3A', 'type' => 'classe']);
        $g2 = UserGroup::create(['name' => 'projet', 'display_name' => 'Projet', 'type' => 'projet']);
        $pp->userGroups()->sync([
            $g->id => ['is_head_teacher' => true, 'role' => UserGroupUserPivot::ROLE_OWNER],
        ]);

        // L'arête existante ($g) est renvoyée SANS attribut, la nouvelle ($g2)
        // avec le rôle dérivé.
        $payload = $pp->userGroupSyncPayloadWithDerivedRole([$g->id, $g2->id]);
        $this->assertSame([], $payload[$g->id]);
        $this->assertSame(['role' => UserGroupUserPivot::ROLE_MANAGER], $payload[$g2->id]);

        $pp->userGroups()->sync($payload);
        $this->assertSame(UserGroupUserPivot::ROLE_OWNER, $this->role($g->id, $pp->id), 'owner non rétrogradé');
        $this->assertSame(UserGroupUserPivot::ROLE_MANAGER, $this->role($g2->id, $pp->id));

        $pp->userGroups()->syncWithoutDetaching($pp->userGroupSyncPayloadWithDerivedRole([$g->id]));
        $this->assertSame(UserGroupUserPivot::ROLE_OWNER, $this->role($g->id, $pp->id), 'owner non rétrogradé (syncWithoutDetaching)');
    }

    // ------------------------------------------------------------------
    // Helpers (patron BackfillUserGroupUserRolesTest)
    // ------------------------------------------------------------------

    private function role(int $groupId, int $userId): string
    {
        return (string) DB::table('user_group_user')
            ->where('user_group_id', $groupId)
            ->where('user_id', $userId)
            ->value('role');
    }

    private function createTestTables(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('login')->unique();
                $table->string('role')->default('autre');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->string('type');
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_group_id');
                $table->unsignedBigInteger('user_id');
                $table->boolean('is_head_teacher')->default(false);
                $table->string('role', 20)->default('member');
                $table->primary(['user_group_id', 'user_id']);
            });
            $this->createdTables = true;
        }
    }
}
