<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Story 49.1 (AC1 / AC6) — le filet DB, sur le schéma RÉEL (migrations jouées).
 *
 * `user_groups.rights_profile_id` est une FK **nullable** vers `roles.id` en
 * `restrictOnDelete` : c'est la défense en profondeur SOUS la garde applicative
 * d'AC6. Un `Role::delete()` hors UI doit échouer plutôt que de retirer
 * silencieusement des droits à tout un parc.
 */
class GroupRightsProfileForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        UserGroupUserPivotObserver::disableProfileReconcile();
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableProfileReconcile();
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        parent::tearDown();
    }

    #[Test]
    public function the_column_exists_and_is_nullable(): void
    {
        self::assertTrue(Schema::hasColumn('user_groups', 'rights_profile_id'));

        $group = UserGroup::create([
            'name' => 'sans-profil',
            'display_name' => 'Sans profil',
            'type' => 'classe',
        ]);

        self::assertNull($group->fresh()->rights_profile_id);
    }

    #[Test]
    public function deleting_a_carried_role_is_refused_by_the_database(): void
    {
        $role = Role::create(['name' => 'porte-par-un-groupe', 'guard_name' => 'web']);

        UserGroup::create([
            'name' => 'porteur',
            'display_name' => 'Porteur',
            'type' => 'role',
            'rights_profile_id' => $role->id,
        ]);

        $this->expectException(QueryException::class);
        $role->delete();
    }

    #[Test]
    public function deleting_an_unattached_role_still_works(): void
    {
        $role = Role::create(['name' => 'non-porte', 'guard_name' => 'web']);

        $role->delete();

        self::assertNull(Role::where('name', 'non-porte')->first());
    }

    #[Test]
    public function several_groups_may_carry_the_same_profile(): void
    {
        $role = Role::create(['name' => 'prof', 'guard_name' => 'web']);

        foreach (['Profs', 'Vacataires', 'Contractuels'] as $name) {
            UserGroup::create([
                'name' => $name,
                'display_name' => $name,
                'type' => 'role',
                'rights_profile_id' => $role->id,
            ]);
        }

        self::assertSame(3, UserGroup::where('rights_profile_id', $role->id)->count());
    }
}
