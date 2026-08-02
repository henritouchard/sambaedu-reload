<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 49.1 (AC4) — commande `users:reproject-group-profiles`.
 *
 * Backfill au déploiement + filet des chemins sans events pivot. Re-run = no-op,
 * `--dry-run` n'écrit rien, sortie en FAILURE si des erreurs sont survenues.
 */
class ReprojectGroupProfilesCommandTest extends TestCase
{
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
        Queue::fake();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        UserGroupUserPivotObserver::disableProfileReconcile();
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableProfileReconcile();
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function seedCarrierGroupWithMember(string $login = 'paul'): User
    {
        $prof = Role::firstOrCreate(['name' => 'prof', 'guard_name' => 'web']);
        $group = UserGroup::create([
            'name' => 'Profs',
            'display_name' => 'Profs',
            'type' => 'role',
            'rights_profile_id' => $prof->id,
        ]);

        $user = User::create(['login' => $login, 'role' => 'autre', 'is_active' => true]);
        // Write pivot BRUT : ne passe pas par l'observer — c'est précisément le
        // trou que la commande est là pour rattraper.
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
            'role' => 'member',
            'is_head_teacher' => false,
        ]);

        return $user;
    }

    #[Test]
    public function it_backfills_profiles_from_raw_pivot_writes(): void
    {
        $user = $this->seedCarrierGroupWithMember();

        $this->artisan('users:reproject-group-profiles')
            ->assertSuccessful();

        self::assertSame(
            ['prof'],
            User::find($user->id)->roles()->pluck('name')->all()
        );
    }

    #[Test]
    public function a_second_run_writes_nothing(): void
    {
        $this->seedCarrierGroupWithMember();

        $this->artisan('users:reproject-group-profiles')->assertSuccessful();
        $countAfterFirst = DB::table('model_has_roles')->count();

        $this->artisan('users:reproject-group-profiles')->assertSuccessful();

        self::assertSame($countAfterFirst, DB::table('model_has_roles')->count());
    }

    #[Test]
    public function dry_run_writes_nothing(): void
    {
        $user = $this->seedCarrierGroupWithMember();

        $this->artisan('users:reproject-group-profiles', ['--dry-run' => true])
            ->assertSuccessful();

        self::assertSame([], User::find($user->id)->roles()->pluck('name')->all());
    }

    #[Test]
    public function it_exits_in_failure_when_a_user_errors(): void
    {
        $this->seedCarrierGroupWithMember();

        // Lien vers un rôle inexistant : la réconciliation lève, est comptée,
        // et la boucle continue — mais la commande sort en FAILURE.
        DB::table('user_groups')->update(['rights_profile_id' => 424242]);

        $this->artisan('users:reproject-group-profiles')
            ->assertFailed();
    }
}
