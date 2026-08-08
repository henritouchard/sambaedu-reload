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
        // Story 62.3 — les libellés de rôle par TYPE de groupe (« Élève »,
        // « Enseignant », « Professeur principal ») ÉTAIENT une constante de code ;
        // ils sont désormais des DÉCLARATIONS en base, installées à la demande par
        // `php artisan college:seed:role-x-type` (la migration, elle, crée la table
        // VIDE). Ce fichier travaille sur un schéma FABRIQUÉ À LA MAIN (patron des
        // tests de groupes), sans migrations ni artisan : il pose donc lui-même la
        // table et les trois lignes de `classe`, comme il pose déjà `quota_rules` —
        // faute de quoi la résolution retombe, correctement, sur les libellés
        // génériques, et les assertions de cette suite mesureraient le repli au lieu
        // du vocabulaire scolaire qu'elles épinglent depuis 42.3.
        $this->createGroupTypeRoleDeclarations();

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


        $this->dropPermissionSchema();
        parent::tearDown();
    }


    /**
     * La table des déclarations et les trois lignes de `classe`, telles que la
     * commande `college:seed:role-x-type` les pose.
     */
    private function createGroupTypeRoleDeclarations(): void
    {
        if (! Schema::hasTable('group_type_roles')) {
            Schema::create('group_type_roles', function (Blueprint $table): void {
                $table->id();
                $table->string('group_type_key', 50);
                $table->string('group_role_key', 20);
                $table->string('label')->nullable();
                $table->timestamps();
                $table->unique(['group_type_key', 'group_role_key']);
            });
        }

        foreach (
            [
                ['classe', 'member', 'Élève'],
                ['classe', 'manager', 'Enseignant'],
                ['classe', 'owner', 'Professeur principal'],
            ] as [$typeKey, $roleKey, $label]
        ) {
            \Illuminate\Support\Facades\DB::table('group_type_roles')->updateOrInsert(
                ['group_type_key' => $typeKey, 'group_role_key' => $roleKey],
                ['label' => $label, 'created_at' => now(), 'updated_at' => now()],
            );
        }

        \App\Support\RoleCatalog::flush();
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

    // =========================================================================
    // Story 42.3 — AC1 : colonne « Rôle » en lecture (view-model + libellés FR)
    // =========================================================================

    #[Test]
    public function it_exposes_edge_role_distinct_from_global_role(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group, $profPp, $prof, $eleve] = $this->makeClasse();

        $members = collect(Livewire::test($this->componentPath(), ['id' => $group->id])->instance()->members())
            ->keyBy('id');

        // Piège 42.1 #5 — collision de clés interdite : `role` (global) reste
        // prof/eleve/autre, `edge_role` (arête) porte member/manager/owner.
        // Story 60.2 — les libellés viennent désormais de la table canonique par
        // TYPE de groupe (ici « classe ») : « Enseignant » et « Professeur
        // principal » remplacent les abréviations écrites en dur.
        $this->assertSame('prof', $members[$profPp->id]['role']);
        $this->assertSame(UserGroupUserPivot::ROLE_OWNER, $members[$profPp->id]['edge_role']);
        $this->assertSame('Professeur principal', $members[$profPp->id]['edge_role_label']);

        $this->assertSame('prof', $members[$prof->id]['role']);
        $this->assertSame(UserGroupUserPivot::ROLE_MANAGER, $members[$prof->id]['edge_role']);
        $this->assertSame('Enseignant', $members[$prof->id]['edge_role_label']);

        $this->assertSame('eleve', $members[$eleve->id]['role']);
        $this->assertSame(UserGroupUserPivot::ROLE_MEMBER, $members[$eleve->id]['edge_role']);
        $this->assertSame('Élève', $members[$eleve->id]['edge_role_label']);
    }

    #[Test]
    public function it_defaults_dirty_edge_role_to_member_label(): void
    {
        $this->actingAs($this->makeAdmin());
        $group = UserGroup::create(['name' => 'Projet', 'type' => 'projet', 'display_name' => 'Projet X']);
        $user = User::create(['login' => 'sale.role', 'role' => 'eleve', 'fullname' => 'Sale Role', 'is_active' => true]);
        // Arête hors vocabulaire (donnée sale) — D1 : ramenée au rôle le moins
        // doté. Story 60.2 : dans un groupe de type « projet », ce rôle se lit
        // « Membre » — écrire « Élève » là était un reste du seul cas scolaire.
        $group->users()->sync([$user->id => ['role' => 'superadmin']]);

        $members = collect(Livewire::test($this->componentPath(), ['id' => $group->id])->instance()->members())
            ->keyBy('id');

        $this->assertSame(UserGroupUserPivot::ROLE_MEMBER, $members[$user->id]['edge_role']);
        $this->assertSame('Membre', $members[$user->id]['edge_role_label']);
    }

    #[Test]
    public function it_renders_role_labels_in_members_table(): void
    {
        $this->actingAs($this->makeAdmin());
        [$group] = $this->makeClasse();

        // Onglet Profs (défaut visible côté Alpine mais le HTML des deux
        // onglets est rendu côté serveur) : libellés FR visibles, aucune
        // valeur technique en texte.
        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->assertSee('Rôle')
            ->assertSee('Élève')
            ->assertSee('Enseignant')
            ->assertSee('Professeur principal');
    }

    #[Test]
    public function it_does_not_render_role_select_for_reader(): void
    {
        $this->actingAs($this->makeReader());
        [$group] = $this->makeClasse();

        // Lecteur (`user.read` sans `user.modify`) : libellé seul, pas de
        // `<select` pour la colonne Rôle (guard UI `@can('update-group')`).
        Livewire::test($this->componentPath(), ['id' => $group->id])
            ->assertDontSeeHtml('wire:change="updateMemberRole');
    }
}
