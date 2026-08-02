<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\ShareService;
use App\Services\UserGroupService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 42.3 — UI rôle d'arête éditable sur la page groupe.
 *
 * Couvre :
 *  - AC2/AC3 : édition UNITAIRE `updateMemberRole` (write pivot direct,
 *    validation/gardes D3/D7, double guard `update-group`)
 *  - AC4 : cohérence colonne ↔ badge PP (D2)
 *  - AC5 : rattachement avec défaut dérivé + surcharge en MASSE (contrat
 *    review 42.2 #4 — disableAdResync/enableAdResync + 1 resync explicite)
 *  - T4.3 : le canal unitaire déclenche EXACTEMENT une reprojection AD via
 *    l'observer pivot (42.2), le contrat masse en déclenche EXACTEMENT une
 *    aussi malgré K surcharges.
 */
class GroupMemberRoleEditTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->withoutVite();
        $this->createPermissionSchema();
        (new PermissionSeeder())->run();

        // Full-render de la page groupe (mêmes prérequis que
        // GroupShowMembersTabsTest) : quota_rules minimal + ShareService neutralisé.
        if (! Schema::hasTable('quota_rules')) {
            Schema::create('quota_rules', function (Blueprint $table): void {
                $table->id();
                $table->string('type', 20);
                $table->string('target', 255)->nullable();
                $table->string('partition', 50);
                $table->unsignedInteger('quota_soft_mb')->default(0);
                $table->unsignedInteger('quota_hard_mb')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        $this->app->bind(ShareService::class, function () {
            $mock = Mockery::mock(ShareService::class);
            $mock->shouldReceive('getStatus')->andReturn(['exists' => false]);
            return $mock;
        });

        // Par défaut : observers désactivés (comme GroupShowMembersTabsTest).
        // Les tests T4.3/T4.4 (resync) les réactivent explicitement.
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        UserGroupUserPivotObserver::enableAdResync();
        Mockery::close();


        $this->dropPermissionSchema();
        parent::tearDown();
    }


    private function makeAdmin(string $login = 'manager'): User
    {
        $u = User::create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        foreach (['user.read', 'user.modify'] as $p) {
            $u->givePermissionTo($p);
        }
        return $u;
    }

    /** Lecteur seul : `user.read` sans `user.modify`. */
    private function makeReader(string $login = 'reader'): User
    {
        $u = User::create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('user.read');
        return $u;
    }

    /**
     * Classe avec un prof PP (owner), un prof simple (manager), un élève (member).
     *
     * @return array{0:UserGroup,1:User,2:User,3:User}
     */
    private function makeClasse(): array
    {
        $group = UserGroup::create(['name' => '3A', 'type' => 'classe', 'display_name' => 'Classe 3A']);
        $profPp = User::create(['login' => 'prof.pp', 'role' => 'prof', 'fullname' => 'Alice Pp', 'is_active' => true]);
        $prof = User::create(['login' => 'prof.simple', 'role' => 'prof', 'fullname' => 'Bob Simple', 'is_active' => true]);
        $eleve = User::create(['login' => 'eleve.un', 'role' => 'eleve', 'fullname' => 'Chloe Eleve', 'is_active' => true]);
        $group->users()->sync([
            $profPp->id => ['is_head_teacher' => true, 'role' => UserGroupUserPivot::ROLE_OWNER],
            $prof->id => ['is_head_teacher' => false, 'role' => UserGroupUserPivot::ROLE_MANAGER],
            $eleve->id => ['is_head_teacher' => false, 'role' => UserGroupUserPivot::ROLE_MEMBER],
        ]);
        return [$group, $profPp, $prof, $eleve];
    }

    private function componentPath(): string
    {
        return 'pages::users.groups.[id].index';
    }

    /**
     * Bind un fake `UserGroupService` complet : `getById`/`getAssignableUsers`
     * passthrough réel (SQL, zéro LDAP), `updateGroup` fake écrivant le pivot
     * au défaut dérivé pour les SEULS ids réellement nouveaux (pattern
     * `HeadTeacherSectionTest::bindFakeUserGroupService`), `resyncGroupAdProjection`
     * avec l'expectation fournie (comptage — preuve du contrat masse 42.2 #4).
     */
    private function bindFakeUserGroupService(callable $configureResyncExpectation): UserGroupService
    {
        $mock = Mockery::mock(UserGroupService::class);

        $mock->shouldReceive('getById')->andReturnUsing(
            fn(int $id): ?UserGroup => UserGroup::query()->with('users')->find($id)
        );

        $mock->shouldReceive('getAssignableUsers')->andReturnUsing(
            fn() => User::query()
                ->select(['id', 'login', 'fullname', 'lastname', 'firstname'])
                ->orderBy('login')
                ->get()
        );

        $mock->shouldReceive('updateGroup')->andReturnUsing(function (int $id, array $data): UserGroup {
            $group = UserGroup::findOrFail($id);
            $existingRoles = [];
            foreach ($group->users as $u) {
                $existingRoles[(int) $u->id] = $u->pivot->role ?? UserGroupUserPivot::ROLE_MEMBER;
            }
            $payload = [];
            foreach (($data['user_ids'] ?? []) as $uid) {
                $uid = (int) $uid;
                $user = User::find($uid);
                $payload[$uid] = [
                    'role' => $existingRoles[$uid]
                        ?? UserGroupUserPivot::defaultRoleForGlobalRole($user?->role),
                ];
            }
            $group->users()->sync($payload);
            return $group->fresh(['users']);
        });

        $configureResyncExpectation($mock);

        $this->app->bind(UserGroupService::class, fn() => $mock);

        return $mock;
    }

    // =========================================================================
    // AC2 — Édition unitaire : write pivot + gardes D3/D7
    // =========================================================================

    #[Test]
    public function it_updates_member_role_and_persists_pivot(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, , , $eleve] = $this->makeClasse();

        // Review 42.3 #1 — vrai canal Livewire (`->call()`) : le défaut de
        // ré-rendu de la page classe (enfants SFC sans balise racine stable)
        // est corrigé — plus de contournement par appel direct d'instance.
        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('updateMemberRole', $eleve->id, UserGroupUserPivot::ROLE_MANAGER)
            ->assertOk();

        $this->assertSame(
            UserGroupUserPivot::ROLE_MANAGER,
            $group->fresh()->users()->whereKey($eleve->id)->first()->pivot->role
        );
    }

    #[Test]
    public function it_rejects_invalid_role_value_without_writing_pivot(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, , , $eleve] = $this->makeClasse();

        // Piège n°8 — valeur reçue NON constante : jamais de 500, y compris
        // au ré-rendu du vrai canal (AC2, review 42.3 #2).
        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('updateMemberRole', $eleve->id, 'superadmin')
            ->assertOk();

        $this->assertSame(
            UserGroupUserPivot::ROLE_MEMBER,
            $group->fresh()->users()->whereKey($eleve->id)->first()->pivot->role
        );
    }

    #[Test]
    public function it_refuses_owner_role_on_non_classe_group(): void
    {
        $this->actingAs($this->makeAdmin());
        $group = UserGroup::create(['name' => 'Projet', 'type' => 'projet', 'display_name' => 'Projet X']);
        $prof = User::create(['login' => 'prof.projet', 'role' => 'prof', 'fullname' => 'Eve Projet', 'is_active' => true]);
        $group->users()->sync([$prof->id => ['role' => UserGroupUserPivot::ROLE_MANAGER]]);

        // D3 — refus serveur même en payload forgé (l'UI ne propose owner que
        // pour les classes).
        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('updateMemberRole', $prof->id, UserGroupUserPivot::ROLE_OWNER)
            ->assertHasNoErrors()
            ->assertDispatched('toastMagic');

        $this->assertSame(
            UserGroupUserPivot::ROLE_MANAGER,
            $group->fresh()->users()->whereKey($prof->id)->first()->pivot->role
        );
    }

    #[Test]
    public function it_allows_owner_role_on_classe_and_reflects_pp_badge(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, , $prof] = $this->makeClasse();

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('updateMemberRole', $prof->id, UserGroupUserPivot::ROLE_OWNER)
            ->assertOk();

        $this->assertSame(
            UserGroupUserPivot::ROLE_OWNER,
            $group->fresh()->users()->whereKey($prof->id)->first()->pivot->role
        );

        // D2/D4 — le badge PP (lu sur `role === 'owner'`) suit immédiatement,
        // aucune écriture PP parallèle (canal modale 4.15 intact).
        $members = collect(
            Livewire::test($this->componentPath(), ['id' => $group->id])->instance()->members()
        )->keyBy('id');
        $this->assertTrue($members[$prof->id]['is_head_teacher']);
    }

    #[Test]
    public function it_forbids_update_member_role_without_modify_permission(): void
    {
        $this->actingAs($this->makeReader());
        [$group, , , $eleve] = $this->makeClasse();

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('updateMemberRole', $eleve->id, UserGroupUserPivot::ROLE_MANAGER)
            ->assertForbidden();

        $this->assertSame(
            UserGroupUserPivot::ROLE_MEMBER,
            $group->fresh()->users()->whereKey($eleve->id)->first()->pivot->role
        );
    }

    #[Test]
    public function it_refuses_role_update_for_non_member(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group] = $this->makeClasse();
        $outsider = User::create(['login' => 'outsider', 'role' => 'eleve', 'is_active' => true]);

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('updateMemberRole', $outsider->id, UserGroupUserPivot::ROLE_MANAGER)
            ->assertOk();

        $this->assertFalse($group->fresh()->users()->whereKey($outsider->id)->exists());
    }

    // =========================================================================
    // T4.3 — Resync unitaire EXACTEMENT une fois via l'observer (42.2 #4)
    // =========================================================================

    #[Test]
    public function it_triggers_exactly_one_ad_resync_on_role_change(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, , , $eleve] = $this->makeClasse();

        // Réactive les observers (par défaut désactivés en setUp) pour ce test.
        UserGroupUserPivotObserver::enableSync();
        UserGroupUserPivotObserver::enableAdResync();

        $mock = $this->bindFakeUserGroupService(function ($mock): void {
            $mock->shouldReceive('resyncGroupAdProjection')->once();
        });

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('updateMemberRole', $eleve->id, UserGroupUserPivot::ROLE_MANAGER)
            ->assertOk();

        // L'assertion `once()` est vérifiée par Mockery::close() en tearDown.
        $this->assertNotNull($mock);
    }

    #[Test]
    public function it_does_not_resync_when_role_reselected_unchanged(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, , , $eleve] = $this->makeClasse();

        UserGroupUserPivotObserver::enableSync();
        UserGroupUserPivotObserver::enableAdResync();

        $this->bindFakeUserGroupService(function ($mock): void {
            // Pivot non dirty (même valeur) : AUCUN event `updated`, donc
            // AUCUN appel resync (preuve du no-op silencieux, AC2).
            $mock->shouldReceive('resyncGroupAdProjection')->never();
        });

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('updateMemberRole', $eleve->id, UserGroupUserPivot::ROLE_MEMBER)
            ->assertOk();

        // Pivot inchangé (déjà `member`) — l'assertion `never()` du mock est
        // vérifiée par Mockery::close() en tearDown.
        $this->assertSame(
            UserGroupUserPivot::ROLE_MEMBER,
            $group->fresh()->users()->whereKey($eleve->id)->first()->pivot->role
        );
    }

    // =========================================================================
    // AC5/T4.4 — Rattachement : défaut dérivé + surcharge + contrat masse
    // =========================================================================

    #[Test]
    public function it_exposes_default_role_for_assignable_users(): void
    {
        $this->actingAs($this->makeAdmin());
        $group = UserGroup::create(['name' => 'Custom', 'type' => 'custom', 'display_name' => 'Groupe libre']);
        $prof = User::create(['login' => 'nouveau.prof', 'role' => 'prof', 'is_active' => true]);
        $eleve = User::create(['login' => 'nouveau.eleve', 'role' => 'eleve', 'is_active' => true]);

        $available = collect(
            Livewire::test($this->componentPath(), ['id' => $group->id])->instance()->availableUsers()
        )->keyBy('value');

        $this->assertSame(UserGroupUserPivot::ROLE_MANAGER, $available[$prof->id]['default_role']);
        $this->assertSame(UserGroupUserPivot::ROLE_MEMBER, $available[$eleve->id]['default_role']);
    }

    #[Test]
    public function it_sets_and_purges_pending_role_on_toggle(): void
    {
        $this->actingAs($this->makeAdmin());
        $group = UserGroup::create(['name' => 'Custom', 'type' => 'custom', 'display_name' => 'Groupe libre']);
        $prof = User::create(['login' => 'nouveau.prof', 'role' => 'prof', 'is_active' => true]);

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('toggleUser', $prof->id)
            ->assertSet('pendingRoles.' . $prof->id, UserGroupUserPivot::ROLE_MANAGER)
            ->call('toggleUser', $prof->id)
            ->assertSet('pendingRoles', []);
    }

    #[Test]
    public function it_applies_role_override_to_new_members_only_with_single_resync(): void
    {
        $this->actingAs($this->makeAdmin());
        $group = UserGroup::create(['name' => 'Custom', 'type' => 'custom', 'display_name' => 'Groupe libre']);
        $existingProf = User::create(['login' => 'prof.existant', 'role' => 'prof', 'is_active' => true]);
        $group->users()->sync([$existingProf->id => ['role' => UserGroupUserPivot::ROLE_MANAGER]]);

        $eleveASurcharger = User::create(['login' => 'eleve.surcharge', 'role' => 'eleve', 'is_active' => true]);
        $profSansSurcharge = User::create(['login' => 'prof.sans.surcharge', 'role' => 'prof', 'is_active' => true]);

        UserGroupUserPivotObserver::enableSync();
        UserGroupUserPivotObserver::enableAdResync();

        $this->bindFakeUserGroupService(function ($mock): void {
            // UN SEUL appel malgré la surcharge — preuve du contrat masse
            // (disableAdResync/enableAdResync autour du write pivot).
            $mock->shouldReceive('resyncGroupAdProjection')->once();
        });

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('startEditing')
            ->call('toggleUser', $eleveASurcharger->id)
            ->call('toggleUser', $profSansSurcharge->id)
            ->call('setPendingRole', $eleveASurcharger->id, UserGroupUserPivot::ROLE_MANAGER)
            ->call('save')
            ->assertHasNoErrors();

        $fresh = $group->fresh()->users()->get()->keyBy('id');
        // Surcharge appliquée au SEUL id nouveau surchargé.
        $this->assertSame(UserGroupUserPivot::ROLE_MANAGER, $fresh[$eleveASurcharger->id]->pivot->role);
        // Défaut dérivé (pas de surcharge) pour l'autre nouveau membre.
        $this->assertSame(UserGroupUserPivot::ROLE_MANAGER, $fresh[$profSansSurcharge->id]->pivot->role);
        // Arête PRÉEXISTANTE jamais réécrite par ce chemin (piège 42.1 #2).
        $this->assertSame(UserGroupUserPivot::ROLE_MANAGER, $fresh[$existingProf->id]->pivot->role);
    }

    #[Test]
    public function it_does_not_resync_when_no_role_override_at_attachment(): void
    {
        $this->actingAs($this->makeAdmin());
        $group = UserGroup::create(['name' => 'Custom', 'type' => 'custom', 'display_name' => 'Groupe libre']);
        $nouveauEleve = User::create(['login' => 'nouveau.simple', 'role' => 'eleve', 'is_active' => true]);

        UserGroupUserPivotObserver::enableSync();
        UserGroupUserPivotObserver::enableAdResync();

        $this->bindFakeUserGroupService(function ($mock): void {
            // Zéro surcharge (rôle choisi == défaut dérivé) → zéro resync explicite.
            $mock->shouldReceive('resyncGroupAdProjection')->never();
        });

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('startEditing')
            ->call('toggleUser', $nouveauEleve->id)
            // Pas de setPendingRole : le pending reste au défaut dérivé (member).
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(
            UserGroupUserPivot::ROLE_MEMBER,
            $group->fresh()->users()->whereKey($nouveauEleve->id)->first()->pivot->role
        );
    }
}
