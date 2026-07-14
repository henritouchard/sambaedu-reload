<?php

declare(strict_types=1);

namespace Tests\Feature\Observers;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\AclService;
use App\Services\Filesystem\ShareService;
use App\Services\UserGroupService;
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
 * Story 42.2 (AC4) — s'y ajoute l'ancrage `updated()` : un changement de RÔLE
 * d'arête reprojette le groupe vers l'AD via
 * `UserGroupService::resyncGroupAdProjection()` (mocké ici — le routage AD réel
 * est couvert par `UserGroupServiceLegacyCompatibilityTest`), suspendu par le
 * flag dédié `$adResyncEnabled` (posé par `syncFromAd`) ET par `$syncEnabled`,
 * filtré type classe/equipe, fail-soft.
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
        UserGroupUserPivotObserver::enableAdResync();
    }

    protected function tearDown(): void
    {
        UserGroupUserPivotObserver::enableSync();
        UserGroupUserPivotObserver::enableAdResync();
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

    // =========================================================================
    // Story 42.2 (AC4) — resync AD sur changement de rôle d'arête
    // =========================================================================

    /** ShareService tolérant : absorbe les events created des fixtures attach. */
    private function tolerantShareService(): void
    {
        $mock = Mockery::mock(ShareService::class);
        $mock->shouldReceive('syncUserClassMemberships')->zeroOrMoreTimes()->andReturn(true);
        $mock->shouldReceive('writeAudit')->zeroOrMoreTimes()->andReturnNull();
        $this->app->instance(ShareService::class, $mock);
    }

    #[Test]
    public function it_resyncs_ad_projection_when_edge_role_changes(): void
    {
        // AC4(a) — un UPDATE de rôle d'arête (updateExistingPivot → event
        // `updated` du pivot custom, wasChanged('role')) reprojette LE groupe
        // concerné via le point d'entrée public resyncGroupAdProjection.
        $this->tolerantShareService();

        $classe = UserGroup::create(['name' => '6A', 'type' => 'classe']);

        $svc = Mockery::mock(UserGroupService::class);
        $svc->shouldReceive('resyncGroupAdProjection')
            ->once()
            ->withArgs(fn (UserGroup $g): bool => (int) $g->id === (int) $classe->id);
        $this->app->instance(UserGroupService::class, $svc);

        $user = User::create(['login' => 'alice', 'role' => 'prof', 'is_active' => true]);
        $user->groups()->attach($classe->id, ['role' => 'member']);

        $user->groups()->updateExistingPivot($classe->id, ['role' => 'manager']);

        $this->assertSame(
            'manager',
            (string) $user->groups()->first()->pivot->role,
            'l\'écriture pivot aboutit et le resync est déclenché'
        );
    }

    #[Test]
    public function it_does_not_resync_when_update_does_not_touch_role(): void
    {
        // AC4 — l'ancrage est filtré wasChanged('role') : un update d'un autre
        // attribut d'arête ne déclenche AUCUNE reprojection.
        $this->tolerantShareService();

        $svc = Mockery::mock(UserGroupService::class);
        $svc->shouldNotReceive('resyncGroupAdProjection');
        $this->app->instance(UserGroupService::class, $svc);

        $user = User::create(['login' => 'bob', 'role' => 'prof', 'is_active' => true]);
        $classe = UserGroup::create(['name' => '6B', 'type' => 'classe']);
        $user->groups()->attach($classe->id, ['role' => 'manager']);

        // Update d'un attribut ≠ role (colonne legacy stale, D5).
        $user->groups()->updateExistingPivot($classe->id, ['is_head_teacher' => true]);

        $this->assertTrue(true); // Mockery vérifie via shouldNotReceive.
    }

    #[Test]
    public function it_does_not_resync_while_ad_resync_is_suspended(): void
    {
        // AC4(b) — pendant le read-back `syncFromAd`, le flag DÉDIÉ
        // $adResyncEnabled suspend le resync (le flip de rôle en masse ne doit
        // pas déclencher d'écriture LDAP). Le guard commun $syncEnabled
        // (imports users) suspend AUSSI. La scène complète syncFromAd est
        // couverte par UserGroupServiceLegacyCompatibilityTest
        // (it_suspends_ad_resync_observer_during_syncFromAd).
        $this->tolerantShareService();

        $svc = Mockery::mock(UserGroupService::class);
        $svc->shouldNotReceive('resyncGroupAdProjection');
        $this->app->instance(UserGroupService::class, $svc);

        $user = User::create(['login' => 'carl', 'role' => 'prof', 'is_active' => true]);
        $classe = UserGroup::create(['name' => '6C', 'type' => 'classe']);
        $user->groups()->attach($classe->id, ['role' => 'member']);

        // Suspension read-back (flag dédié).
        UserGroupUserPivotObserver::disableAdResync();
        $user->groups()->updateExistingPivot($classe->id, ['role' => 'manager']);
        UserGroupUserPivotObserver::enableAdResync();

        // Guard commun $syncEnabled (imports users).
        UserGroupUserPivotObserver::disableSync();
        $user->groups()->updateExistingPivot($classe->id, ['role' => 'owner']);
        UserGroupUserPivotObserver::enableSync();

        $this->assertSame('owner', (string) $user->groups()->first()->pivot->role);
    }

    #[Test]
    public function it_ignores_role_changes_on_non_classe_like_groups(): void
    {
        // AC4(c) — le rôle d'arête ne route rien hors classe/equipe : aucun
        // resync pour un groupe `cours` (ou role/function/custom).
        $this->tolerantShareService();

        $svc = Mockery::mock(UserGroupService::class);
        $svc->shouldNotReceive('resyncGroupAdProjection');
        $this->app->instance(UserGroupService::class, $svc);

        $user = User::create(['login' => 'dan', 'role' => 'prof', 'is_active' => true]);
        $cours = UserGroup::create(['name' => 'Cours_Maths', 'type' => 'cours']);
        $user->groups()->attach($cours->id, ['role' => 'member']);

        $user->groups()->updateExistingPivot($cours->id, ['role' => 'manager']);

        $this->assertSame('manager', (string) $user->groups()->first()->pivot->role);
    }

    #[Test]
    public function it_resyncs_for_equipe_type_groups(): void
    {
        // AC4 — le filtre type couvre classe ET equipe (Equipe_ orpheline
        // projetée en ligne nue type equipe — 4.13 D1).
        $this->tolerantShareService();

        $equipe = UserGroup::create(['name' => 'MathsTeam', 'type' => 'equipe']);

        $svc = Mockery::mock(UserGroupService::class);
        $svc->shouldReceive('resyncGroupAdProjection')
            ->once()
            ->withArgs(fn (UserGroup $g): bool => (int) $g->id === (int) $equipe->id);
        $this->app->instance(UserGroupService::class, $svc);

        $user = User::create(['login' => 'eva', 'role' => 'prof', 'is_active' => true]);
        $user->groups()->attach($equipe->id, ['role' => 'member']);

        $user->groups()->updateExistingPivot($equipe->id, ['role' => 'manager']);

        $this->assertSame('manager', (string) $user->groups()->first()->pivot->role);
    }

    #[Test]
    public function it_keeps_pivot_write_valid_when_resync_fails(): void
    {
        // AC4(d) — fail-soft : un échec de la projection AD (Throwable) est
        // loggé et ne casse JAMAIS l'écriture pivot qui vient d'aboutir.
        $this->tolerantShareService();

        $svc = Mockery::mock(UserGroupService::class);
        $svc->shouldReceive('resyncGroupAdProjection')
            ->once()
            ->andThrow(new \RuntimeException('LDAP down'));
        $this->app->instance(UserGroupService::class, $svc);

        $user = User::create(['login' => 'fred', 'role' => 'prof', 'is_active' => true]);
        $classe = UserGroup::create(['name' => '6D', 'type' => 'classe']);
        $user->groups()->attach($classe->id, ['role' => 'member']);

        // Aucune exception ne doit remonter.
        $user->groups()->updateExistingPivot($classe->id, ['role' => 'manager']);

        $this->assertSame(
            'manager',
            (string) $user->groups()->first()->pivot->role,
            'fail-soft : le rôle est persisté malgré l\'échec de projection'
        );
    }
}
