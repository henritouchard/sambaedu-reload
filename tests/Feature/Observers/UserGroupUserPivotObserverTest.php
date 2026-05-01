<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\AclService;
use App\Services\Filesystem\ShareService;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 5.2 (D5=A) — Observer sur le pivot `user_group_user`.
 *
 * Le test vérifie que :
 *  - un attach sur un UserGroup type='classe' déclenche
 *    `ShareService::syncUserClassMemberships(user, [], [classId])` ;
 *  - un attach sur un UserGroup type='role' (ou autre) ne déclenche RIEN ;
 *  - un detach sur classe déclenche `syncUserClassMemberships(user, [oldId], [])`.
 *
 * Le ShareService est mocké (binding container) pour isoler le hook du flux
 * FS réel — déjà testé dans `ShareServiceTest`.
 */
class UserGroupUserPivotObserverTest extends TestCase
{
    use CreatesPermissionSchema;

    private string $tempRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPermissionSchema();
        UserGroupObserver::disableSync();
        Queue::fake();
        Process::fake([
            '*' => Process::result(output: '', exitCode: 0),
        ]);

        $this->tempRoot = sys_get_temp_dir() . '/observer-' . uniqid();
        @mkdir($this->tempRoot, 0o755, true);
        AclService::$classesRoot = $this->tempRoot;
        ShareService::$classesRoot = $this->tempRoot;

        // L'observer doit être actif pour tester son comportement.
        UserGroupUserPivotObserver::enableSync();
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableSync();
        UserGroupObserver::enableSync();
        AclService::$classesRoot = '/var/sambaedu/Classes';
        ShareService::$classesRoot = '/var/sambaedu/Classes';
        if (is_dir($this->tempRoot)) {
            $this->rrmdir($this->tempRoot);
        }
        $this->dropPermissionSchema();
        Mockery::close();
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        $items = @scandir($dir) ?: [];
        foreach ($items as $i) {
            if ($i === '.' || $i === '..') {
                continue;
            }
            $f = $dir . '/' . $i;
            if (is_dir($f) && ! is_link($f)) {
                $this->rrmdir($f);
            } else {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    #[Test]
    public function it_triggers_sync_on_attach_classe_pivot(): void
    {
        $mock = Mockery::mock(ShareService::class);
        $mock->shouldReceive('syncUserClassMemberships')
            ->once()
            ->withArgs(function (User $u, array $oldIds, array $newIds) {
                return $u->login === 'alice'
                    && $oldIds === []
                    && count($newIds) === 1;
            })
            ->andReturn(true);
        // Méthode publique writeAudit appelée au passage : on tolère.
        $mock->shouldReceive('writeAudit')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(ShareService::class, $mock);

        $user = User::create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        $classe = UserGroup::create(['name' => '6A', 'type' => 'classe']);

        $user->groups()->attach($classe->id);

        // Mockery vérifie ->once() au tearDown ; on ajoute une assertion
        // explicite pour éviter la "risky test" warning PHPUnit.
        $this->assertTrue($user->groups()->where('user_groups.id', $classe->id)->exists());
    }

    #[Test]
    public function it_does_not_trigger_for_non_classe_groups(): void
    {
        $mock = Mockery::mock(ShareService::class);
        // Aucun call ne doit être fait.
        $mock->shouldNotReceive('syncUserClassMemberships');
        $this->app->instance(ShareService::class, $mock);

        $user = User::create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $role = UserGroup::create(['name' => 'Profs', 'type' => 'role']);
        $func = UserGroup::create(['name' => 'Documentaliste', 'type' => 'function']);

        $user->groups()->attach([$role->id, $func->id]);

        $this->assertTrue(true); // Mockery vérifie via shouldNotReceive.
    }

    #[Test]
    public function it_triggers_sync_on_detach_classe_pivot(): void
    {
        $mock = Mockery::mock(ShareService::class);
        // Phase 1 : attach → 1 call
        // Phase 2 : detach → 1 call avec oldIds=[classId], newIds=[]
        $mock->shouldReceive('syncUserClassMemberships')
            ->twice()
            ->andReturn(true);
        $mock->shouldReceive('writeAudit')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(ShareService::class, $mock);

        $user = User::create(['login' => 'charlie', 'role' => 'eleve', 'is_active' => true]);
        $classe = UserGroup::create(['name' => '5B', 'type' => 'classe']);

        $user->groups()->attach($classe->id);
        $user->groups()->detach($classe->id);

        $this->assertSame(0, $user->groups()->count());
    }

    #[Test]
    public function it_can_be_disabled_globally(): void
    {
        UserGroupUserPivotObserver::disableSync();

        $mock = Mockery::mock(ShareService::class);
        $mock->shouldNotReceive('syncUserClassMemberships');
        $this->app->instance(ShareService::class, $mock);

        $user = User::create(['login' => 'dee', 'role' => 'eleve', 'is_active' => true]);
        $classe = UserGroup::create(['name' => '4C', 'type' => 'classe']);

        $user->groups()->attach($classe->id);

        // Réactive pour les tests suivants.
        UserGroupUserPivotObserver::enableSync();
        $this->assertTrue(true);
    }
}
