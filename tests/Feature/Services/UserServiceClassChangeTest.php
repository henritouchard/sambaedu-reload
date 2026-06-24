<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\User as SqlUser;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\ShareService;
use App\Services\UserService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 5.2 review #1 (Q1=Option B) — Hook explicit `UserService::persistUserGroupsToSql`
 * → `ShareService::syncUserClassMemberships`.
 *
 * Vérifie que le changement de classe d'un utilisateur SQL passe bien par le
 * call EXPLICIT (avec oldClassIds + newClassIds en main) et que l'Observer
 * pivot est désactivé pendant l'opération atomique pour éviter le doublon
 * (events `created`/`deleted` séparés).
 */
class UserServiceClassChangeTest extends TestCase
{
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
        UserGroupObserver::disableSync();
        Queue::fake();
        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        $this->dropPermissionSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function callPersist(UserService $service, string $login, string $categorie, string $fonction, array $classes): void
    {
        $method = new \ReflectionMethod($service, 'persistUserGroupsToSql');
        $method->setAccessible(true);
        $method->invoke($service, $login, $categorie, $fonction, $classes);
    }

    #[Test]
    public function it_calls_share_service_once_with_old_and_new_class_ids_on_class_change(): void
    {
        // Pré-conditions : user SQL Alice déjà rattaché à Classe_6A.
        $alice = SqlUser::create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $eleves = UserGroup::create(['name' => 'Eleves', 'type' => 'role']);
        $classe6A = UserGroup::create(['name' => 'Classe_6A', 'type' => 'classe']);
        $classe5B = UserGroup::create(['name' => 'Classe_5B', 'type' => 'classe']);

        // Activer l'observer pour vérifier qu'il N'est PAS déclenché pendant
        // l'opération atomique (sinon doublon de syncUserClassMemberships).
        UserGroupUserPivotObserver::enableSync();

        // Pré-attache 6A + Eleves.
        UserGroupUserPivotObserver::disableSync(); // pas de side-effect au pré-remplissage
        $alice->groups()->syncWithoutDetaching([$classe6A->id, $eleves->id]);
        UserGroupUserPivotObserver::enableSync();

        // Mock ShareService — on attend EXACTEMENT 1 call avec old=[6A.id], new=[5B.id].
        $mock = Mockery::mock(ShareService::class);
        $mock->shouldReceive('syncUserClassMemberships')
            ->once()
            ->withArgs(function ($user, array $oldIds, array $newIds) use ($classe6A, $classe5B) {
                return $user->login === 'alice'
                    && $oldIds === [$classe6A->id]
                    && $newIds === [$classe5B->id];
            })
            ->andReturn(true);
        // L'Observer ne doit PAS appeler le mock (sinon échouerait sur ->once()).
        $this->app->instance(ShareService::class, $mock);

        // Appel via UserService — change de 6A à 5B.
        $service = app(UserService::class);
        $this->callPersist($service, 'alice', 'Eleves', '', ['5B']);

        // Sanity check pivot : Alice n'a plus 6A, a bien 5B et Eleves.
        $alice->refresh();
        $classNames = $alice->groups()->where('type', 'classe')->pluck('user_groups.name')->all();
        sort($classNames);
        $this->assertSame(['Classe_5B'], $classNames);

        // Eleves toujours présent (groupe role non touché par sync ciblé classes).
        $this->assertTrue($alice->groups()->where('user_groups.name', 'Eleves')->exists());
    }

    #[Test]
    public function it_does_not_call_share_service_when_class_set_unchanged(): void
    {
        $alice = SqlUser::create(['login' => 'alice2', 'role' => 'eleve', 'is_active' => true]);
        $eleves = UserGroup::create(['name' => 'Eleves', 'type' => 'role']);
        $classe6A = UserGroup::create(['name' => 'Classe_6A', 'type' => 'classe']);

        UserGroupUserPivotObserver::disableSync();
        $alice->groups()->syncWithoutDetaching([$classe6A->id, $eleves->id]);
        UserGroupUserPivotObserver::enableSync();

        // Sync avec exactement le même set classes → 0 call.
        $mock = Mockery::mock(ShareService::class);
        $mock->shouldNotReceive('syncUserClassMemberships');
        $this->app->instance(ShareService::class, $mock);

        $service = app(UserService::class);
        $this->callPersist($service, 'alice2', 'Eleves', '', ['6A']);

        $this->assertTrue(true);
    }

    #[Test]
    public function it_disables_observer_during_atomic_sync_to_avoid_duplicate_calls(): void
    {
        $alice = SqlUser::create(['login' => 'alice3', 'role' => 'eleve', 'is_active' => true]);
        $eleves = UserGroup::create(['name' => 'Eleves', 'type' => 'role']);
        $classe6A = UserGroup::create(['name' => 'Classe_6A', 'type' => 'classe']);
        $classe5B = UserGroup::create(['name' => 'Classe_5B', 'type' => 'classe']);

        UserGroupUserPivotObserver::disableSync();
        $alice->groups()->syncWithoutDetaching([$classe6A->id]);
        UserGroupUserPivotObserver::enableSync();

        // Si l'Observer N'EST PAS désactivé pendant le sync, on aurait :
        //   - 1 call observer pour `deleted(6A)`
        //   - 1 call observer pour `created(5B)`
        //   - 1 call explicit final
        // = 3 calls. Avec le fix, on attend exactement 1 call (le call explicit).
        $callCount = 0;
        $mock = Mockery::mock(ShareService::class);
        $mock->shouldReceive('syncUserClassMemberships')
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                return true;
            });
        $this->app->instance(ShareService::class, $mock);

        $service = app(UserService::class);
        $this->callPersist($service, 'alice3', 'Eleves', '', ['5B']);

        $this->assertSame(1, $callCount, "ShareService::syncUserClassMemberships doit être appelé exactement 1 fois (call explicit), pas par l'Observer pendant le sync atomique.");
    }

    #[Test]
    public function it_attaches_student_to_folded_bare_name_class(): void
    {
        // Story 4.13 (review #8) — l'import AD→SQL replie les classes en UNE
        // ligne au NOM NU (`6A`, type='classe'). Le lookup `'Classe_'.$c` ne
        // matchait plus cette ligne → l'élève n'était plus rattaché à sa classe.
        // persistUserGroupsToSql doit désormais résoudre par NOM NU.
        $bob = SqlUser::create(['login' => 'bob-fold', 'role' => 'eleve', 'is_active' => true]);
        $eleves = UserGroup::create(['name' => 'Eleves', 'type' => 'role']);
        // Classe FOLDÉE : nom nu, type classe (pas de préfixe Classe_).
        $classe6A = UserGroup::create(['name' => '6A', 'type' => 'classe']);

        // ShareService mocké (on ne teste ici que le rattachement SQL).
        $mock = Mockery::mock(ShareService::class);
        $mock->shouldReceive('syncUserClassMemberships')->andReturn(true);
        $this->app->instance(ShareService::class, $mock);

        $service = app(UserService::class);
        $this->callPersist($service, 'bob-fold', 'Eleves', '', ['6A']);

        $bob->refresh();
        $classIds = $bob->groups()->where('type', 'classe')->pluck('user_groups.id')->all();

        $this->assertContains(
            $classe6A->id,
            $classIds,
            "L'élève doit être rattaché à la classe FOLDÉE au nom nu `6A` (review #8)."
        );
        // Et toujours rattaché à son groupe de rôle (non touché par le sync classes).
        $this->assertTrue($bob->groups()->where('user_groups.id', $eleves->id)->exists());
    }

    /**
     * @return array<string, bool>
     */
    private function groupNamesAsMap(SqlUser $user): array
    {
        $map = [];
        foreach ($user->groups()->get(['user_groups.name']) as $g) {
            $map[$g->name] = true;
        }
        ksort($map);
        return $map;
    }
}
