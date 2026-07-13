<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\UserGroupService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 4.15 — Tests Feature Livewire SFC `head-teacher-section`.
 *
 * Couvre AC9 / AC10 / AC11 / AC12 :
 *  - rendu conditionnel `type === 'classe'` (visible classe, absent cours)
 *  - abort en mount sur un groupId non-classe (anti-forge payload)
 *  - désignation d'un PP → toggle + save persiste le pivot + toast succès
 *  - toggle limité aux membres `isProf()` (les élèves n'ont pas de contrôle)
 *  - double guard `update-group` (toggle/save sans `user.modify` = readonly/403)
 */
class HeadTeacherSectionTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        (new PermissionSeeder())->run();

        // Désactive les observers AD pour éviter des jobs dispatchés.
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();

        // Le service réel n'est pas exercé (l'écriture AD est validée côté
        // UserGroupServiceLegacyCompatibilityTest) : on mocke updateGroup pour
        // ne tester que la persistance UI du pivot + le câblage Livewire.
        $this->bindFakeUserGroupService();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();

        // Purge le cache statique request-scope de User (LDAP primé ci-dessous).
        $ref = new ReflectionClass(User::class);
        $prop = $ref->getProperty('ldapCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $this->dropPermissionSchema();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Service factice : `updateGroup` écrit le pivot `is_head_teacher` à partir
     * de `head_teacher_ids` (comme le fait le read-back réel) puis retourne le
     * groupe. Permet d'asserter la persistance pivot sans LDAP/AD.
     */
    private function bindFakeUserGroupService(): void
    {
        $this->app->bind(UserGroupService::class, function () {
            $mock = Mockery::mock(UserGroupService::class);
            $mock->shouldReceive('updateGroup')->andReturnUsing(
                function (int $id, array $data): UserGroup {
                    $group = UserGroup::findOrFail($id);
                    $pp = array_flip(array_map('intval', $data['head_teacher_ids'] ?? []));
                    $payload = [];
                    foreach ($group->users as $u) {
                        // Story 42.1 — le read-back réel écrit `role` en MIROIR de
                        // `is_head_teacher` (owner ⇔ PP). Le fake reproduit le miroir.
                        $isPP = isset($pp[(int) $u->id]);
                        $payload[(int) $u->id] = [
                            'is_head_teacher' => $isPP,
                            'role' => $isPP
                                ? UserGroupUserPivot::ROLE_OWNER
                                : UserGroupUserPivot::defaultRoleForGlobalRole($u->role),
                        ];
                    }
                    $group->users()->sync($payload);
                    return $group->fresh(['users']);
                }
            );
            return $mock;
        });
    }

    /** Pré-remplit le cache LDAP à null → isProf()/isEleve() retombent sur role. */
    private function primeNoLdap(string ...$logins): void
    {
        $ref = new ReflectionClass(User::class);
        $prop = $ref->getProperty('ldapCache');
        $prop->setAccessible(true);
        $cache = $prop->getValue();
        foreach ($logins as $login) {
            $cache['ldap:' . $login] = null;
            $cache['bo:' . $login] = null;
        }
        $prop->setValue(null, $cache);
    }

    private function makeAdmin(string $login = 'manager', array $perms = ['user.read', 'user.modify']): User
    {
        $u = User::create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        foreach ($perms as $p) {
            $u->givePermissionTo($p);
        }
        return $u;
    }

    /**
     * Crée une classe avec un prof, un second prof et un élève membres.
     *
     * @return array{0:UserGroup,1:User,2:User,3:User}
     */
    private function makeClasseWithMembers(string $name = '3A'): array
    {
        $group = UserGroup::create(['name' => $name, 'type' => 'classe', 'display_name' => "Classe $name"]);
        $prof1 = User::create(['login' => 'prof.un', 'role' => 'prof', 'is_active' => true]);
        $prof2 = User::create(['login' => 'prof.deux', 'role' => 'prof', 'is_active' => true]);
        $eleve = User::create(['login' => 'eleve.un', 'role' => 'eleve', 'is_active' => true]);
        $group->users()->sync([
            $prof1->id => ['is_head_teacher' => false],
            $prof2->id => ['is_head_teacher' => false],
            $eleve->id => ['is_head_teacher' => false],
        ]);
        $this->primeNoLdap('prof.un', 'prof.deux', 'eleve.un');
        return [$group, $prof1, $prof2, $eleve];
    }

    private function componentPath(): string
    {
        return 'pages::users.groups.[id]._partials.head-teacher-section';
    }

    // =========================================================================
    // AC9 — rendu conditionnel + abort anti-forge
    // =========================================================================

    #[Test]
    public function it_renders_section_for_classe_type(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group] = $this->makeClasseWithMembers('3A');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSet('isClasse', true)
            ->assertSee('Professeur principal');
    }

    #[Test]
    public function it_does_not_render_section_for_non_classe_type(): void
    {
        $this->actingAs($this->makeAdmin());
        $role = UserGroup::create(['name' => 'Profs', 'type' => 'role']);

        Livewire::test($this->componentPath(), ['groupId' => $role->id])
            ->assertSet('isClasse', false)
            ->assertDontSee('Professeur principal');
    }

    #[Test]
    public function it_aborts_404_when_group_id_not_found(): void
    {
        $this->actingAs($this->makeAdmin());

        // withoutExceptionHandling : on assert l'HttpException levée en mount
        // (anti-forge) sans rendre la page d'erreur (Vite non buildé sur l'hôte).
        $this->withoutExceptionHandling();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        Livewire::test($this->componentPath(), ['groupId' => 99999]);
    }

    // =========================================================================
    // AC11 — toggle limité aux profs
    // =========================================================================

    #[Test]
    public function it_only_lists_prof_members_as_toggleable(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, $prof1, $prof2, $eleve] = $this->makeClasseWithMembers('3A');

        $component = Livewire::test($this->componentPath(), ['groupId' => $group->id]);
        $profMembers = collect($component->instance()->profMembers())->pluck('id')->all();

        $this->assertContains($prof1->id, $profMembers);
        $this->assertContains($prof2->id, $profMembers);
        $this->assertNotContains($eleve->id, $profMembers, 'un élève ne doit pas être proposé comme PP');
    }

    // =========================================================================
    // AC10 — désigner / retirer un PP persiste le pivot + toast
    // =========================================================================

    #[Test]
    public function it_designates_a_head_teacher_and_persists_pivot(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, $prof1, $prof2, $eleve] = $this->makeClasseWithMembers('3A');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('toggleHeadTeacher', $prof1->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toastMagic');

        $this->assertTrue((bool) $group->users()->where('users.id', $prof1->id)->first()->pivot->is_head_teacher);
        $this->assertFalse((bool) $group->users()->where('users.id', $prof2->id)->first()->pivot->is_head_teacher);
        $this->assertFalse((bool) $group->users()->where('users.id', $eleve->id)->first()->pivot->is_head_teacher);
    }

    #[Test]
    public function it_removes_a_head_teacher_when_untoggled(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, $prof1] = $this->makeClasseWithMembers('3A');
        // Story 42.1 — état PP pré-existant : miroir `owner` ⇔ `is_head_teacher`.
        $group->users()->updateExistingPivot($prof1->id, [
            'is_head_teacher' => true,
            'role' => UserGroupUserPivot::ROLE_OWNER,
        ]);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSet('headTeacherIds', [$prof1->id])
            ->call('toggleHeadTeacher', $prof1->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse((bool) $group->users()->where('users.id', $prof1->id)->first()->pivot->is_head_teacher);
    }

    // =========================================================================
    // AC12 — double guard update-group
    // =========================================================================

    #[Test]
    public function it_does_not_open_modal_for_viewer_without_modify_permission(): void
    {
        // Refonte UI — la désignation du PP est désormais une MODALE d'édition,
        // gardée par `update-group`. Un viewer `user.read` SEUL ne peut pas
        // l'ouvrir : le bouton déclencheur est masqué côté parent (@can) ET
        // open() refuse un dispatch forgé (isOpen reste false + toast refus).
        // Pendant de `it_blocks_save_without_modify_permission` (guard serveur).
        $viewer = $this->makeAdmin('viewer-only', perms: ['user.read']);
        $this->actingAs($viewer);
        [$group] = $this->makeClasseWithMembers('3A');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSet('isClasse', true)
            ->assertSet('isOpen', false)
            ->call('open')
            ->assertSet('isOpen', false);
    }

    #[Test]
    public function it_opens_modal_for_editor_with_modify_permission(): void
    {
        // L'action « Nommer un professeur principal » (event open-head-teacher-modal)
        // ouvre la modale pour un utilisateur disposant de `update-group`.
        $this->actingAs($this->makeAdmin());
        [$group] = $this->makeClasseWithMembers('3A');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSet('isOpen', false)
            ->call('open')
            ->assertSet('isOpen', true)
            ->call('close')
            ->assertSet('isOpen', false);
    }

    #[Test]
    public function it_blocks_mount_without_read_permission(): void
    {
        // Story 4.15 (Q3) — `mount()` est gardé par `Gate::authorize('view-group')`
        // (== `user.read`). Un utilisateur SANS `user.read` ne peut PAS instancier
        // la section (et donc ne peut pas lire les membres profs via wire:call),
        // même s'il dispose de `user.modify`. En prod, tous les rôles seedés avec
        // `user.modify` ont aussi `user.read` ; ce test isole le guard de lecture.
        $noReader = $this->makeAdmin('modify-only', perms: ['user.modify']);
        $this->actingAs($noReader);
        [$group] = $this->makeClasseWithMembers('3A');

        $this->withoutExceptionHandling();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        Livewire::test($this->componentPath(), ['groupId' => $group->id]);
    }

    #[Test]
    public function it_blocks_save_without_modify_permission(): void
    {
        $viewer = $this->makeAdmin('viewer-only', perms: ['user.read']);
        $this->actingAs($viewer);
        [$group] = $this->makeClasseWithMembers('3A');

        // withoutExceptionHandling : Gate::authorize('update-group') lève une
        // AuthorizationException (403) — on l'assert sans rendre la page erreur.
        $this->withoutExceptionHandling();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('save');
    }
}
