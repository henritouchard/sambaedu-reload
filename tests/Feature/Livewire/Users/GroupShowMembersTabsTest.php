<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\ShareService;
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
 * Front — page d'affichage d'un groupe unique : répartition des membres en deux
 * onglets (Élèves / Profs) avec badge « Professeur principal ».
 *
 * Couvre :
 *  - le computed `members()` enrichi (`role`, `is_head_teacher`)
 *  - les dérivés `students()` / `teachers()` (split par rôle)
 *  - le rendu des deux onglets + du badge PP
 */
class GroupShowMembersTabsTest extends TestCase
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

        // La page parente rend aussi les sous-sections classe (quota, partage).
        // Seule `quota_rules` est lue au render ; on la crée a minima et on
        // neutralise le ShareService (FS absent en test) pour que le
        // full-render aboutisse.
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

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        Mockery::close();

        // Purge le cache statique request-scope de User (LDAP primé null).
        $ref = new ReflectionClass(User::class);
        $prop = $ref->getProperty('ldapCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        $this->dropPermissionSchema();
        parent::tearDown();
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
     * Classe avec un prof PP, un prof simple et un élève.
     *
     * @return array{0:UserGroup,1:User,2:User,3:User}
     */
    private function makeClasse(): array
    {
        $group = UserGroup::create(['name' => '3A', 'type' => 'classe', 'display_name' => 'Classe 3A']);
        $profPp = User::create(['login' => 'prof.pp', 'role' => 'prof', 'fullname' => 'Alice Pp', 'is_active' => true]);
        $prof = User::create(['login' => 'prof.simple', 'role' => 'prof', 'fullname' => 'Bob Simple', 'is_active' => true]);
        $eleve = User::create(['login' => 'eleve.un', 'role' => 'eleve', 'fullname' => 'Chloe Eleve', 'is_active' => true]);
        // Story 42.1 — miroir `role` ⇔ `is_head_teacher` (owner pour le PP).
        $group->users()->sync([
            $profPp->id => ['is_head_teacher' => true, 'role' => UserGroupUserPivot::ROLE_OWNER],
            $prof->id => ['is_head_teacher' => false, 'role' => UserGroupUserPivot::ROLE_MANAGER],
            $eleve->id => ['is_head_teacher' => false, 'role' => UserGroupUserPivot::ROLE_MEMBER],
        ]);
        $this->primeNoLdap('prof.pp', 'prof.simple', 'eleve.un');
        return [$group, $profPp, $prof, $eleve];
    }

    private function componentPath(): string
    {
        return 'pages::users.groups.[id].index';
    }

    #[Test]
    public function it_splits_members_by_role(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, $profPp, $prof, $eleve] = $this->makeClasse();

        $component = Livewire::test($this->componentPath(), ['id' => $group->id]);

        $students = collect($component->instance()->students())->pluck('id')->all();
        $teachers = collect($component->instance()->teachers())->pluck('id')->all();

        $this->assertSame([$eleve->id], $students);
        $this->assertContains($profPp->id, $teachers);
        $this->assertContains($prof->id, $teachers);
        $this->assertNotContains($eleve->id, $teachers);
    }

    #[Test]
    public function it_flags_head_teacher_on_member_row(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, $profPp, $prof] = $this->makeClasse();

        $members = collect(Livewire::test($this->componentPath(), ['id' => $group->id])->instance()->members())
            ->keyBy('id');

        $this->assertTrue($members[$profPp->id]['is_head_teacher']);
        $this->assertFalse($members[$prof->id]['is_head_teacher']);
    }

    #[Test]
    public function it_renders_both_tabs_and_pp_badge(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group] = $this->makeClasse();

        // `title="Professeur principal"` est propre au badge de members-table ;
        // on ne s'appuie PAS sur le littéral « PP » seul, que head-teacher-section
        // rend aussi sur cette page (faux positif).
        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->assertSee('Élèves')
            ->assertSee('Profs')
            ->assertSee('Alice Pp')
            ->assertSeeHtml('title="Professeur principal"');
    }

    #[Test]
    public function it_does_not_badge_a_non_prof_member_as_pp(): void
    {
        $this->actingAs($this->makeAdmin());
        $group = UserGroup::create(['name' => 'Projet', 'type' => 'projet', 'display_name' => 'Projet X']);
        // Donnée limite : un membre non-prof porteur de l'arête `owner` (miroir
        // de `is_head_teacher`). Le badge PP est gaté par le rôle GLOBAL `prof` →
        // pas de badge pour un admin, même porteur de l'arête owner.
        $admin = User::create(['login' => 'perso.un', 'role' => 'admin', 'fullname' => 'Dora Perso', 'is_active' => true]);
        $group->users()->sync([$admin->id => ['is_head_teacher' => true, 'role' => UserGroupUserPivot::ROLE_OWNER]]);
        $this->primeNoLdap('perso.un');

        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->assertSee('Dora Perso')
            ->assertDontSeeHtml('title="Professeur principal"');
    }

    #[Test]
    public function it_forbids_remove_member_without_modify_permission(): void
    {
        $this->actingAs($this->makeReader());
        [$group, , , $eleve] = $this->makeClasse();

        // Double guard serveur : un lecteur (`user.read` seul) ne peut pas
        // déclencher la mutation removeMember malgré l'accès à la page.
        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->call('removeMember', $eleve->id)
            ->assertForbidden();

        $this->assertTrue(
            $group->fresh()->users()->whereKey($eleve->id)->exists(),
            'le membre ne doit pas avoir été retiré'
        );
    }
}
