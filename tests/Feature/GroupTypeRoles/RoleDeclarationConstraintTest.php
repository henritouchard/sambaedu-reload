<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypeRoles;

use App\Models\GroupRole;
use App\Models\GroupTypeRole;
use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Services\Filesystem\ShareService;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.3 — AC4 : LA CONTRAINTE MORD AUX TROIS POINTS HUMAINS, ET NULLE PART
 * AILLEURS.
 *
 * Ce fichier est le cœur de la story, et il prouve DEUX choses opposées, ce qui
 * est tout l'intérêt :
 *
 *  1. sur un type déclaré, un rôle hors déclaration est REFUSÉ aux trois points
 *     où un humain choisit — jamais un 500, toujours un message ou un refus
 *     silencieux selon le point ;
 *  2. le même rôle est ACCEPTÉ par un attach direct. **La liberté des chemins
 *     d'import est un CONTRAT, pas un oubli** : le balayage d'annuaire, l'import
 *     d'utilisateurs, le fold legacy et les reprises écrivent en direct, et
 *     l'annuaire reste autoritaire sur son propre flux. Si un jour quelqu'un
 *     « resserre » la garde en la posant sur le pivot, c'est le test 2 qui tombe,
 *     et il tombera en disant pourquoi.
 */
class RoleDeclarationConstraintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->seed(PermissionSeeder::class);
        $this->seed(GroupRoleSeeder::class);

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
        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::create(['login' => 'declaration-admin', 'role' => 'admin', 'is_active' => true]);
        foreach (['user.read', 'user.modify'] as $permission) {
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** Un projet, un membre `member`, et un rôle `tuteur` au catalogue mais non déclaré. */
    private function projectWithMember(): array
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        $group = UserGroup::create(['name' => 'Projet_Robotique', 'type' => 'projet']);
        $user = User::create(['login' => 'membre.projet', 'role' => 'eleve', 'is_active' => true]);
        $group->users()->sync([$user->id => ['role' => UserGroupUserPivot::ROLE_MEMBER]]);

        return [$group, $user];
    }

    /**
     * Double SQL de `UserGroupService` — recopie du harnais de la story 42.3
     * (`GroupMemberRoleEditTest::bindFakeUserGroupService`).
     *
     * `save()` passe par `updateGroup()`, qui est AD-first : sans annuaire, aucun
     * membre ne serait attaché et le test ne prouverait rien. Le double reproduit
     * exactement ce que fait le read-back — dérivation du rôle par défaut,
     * préservation des arêtes existantes — et rien de plus.
     */
    private function bindFakeUserGroupService(): void
    {
        $mock = Mockery::mock(\App\Services\UserGroupService::class);

        $mock->shouldReceive('getById')->andReturnUsing(
            fn (int $id): ?UserGroup => UserGroup::query()->with('users')->find($id),
        );

        $mock->shouldReceive('getAssignableUsers')->andReturnUsing(
            fn () => User::query()->select(['id', 'login', 'fullname', 'lastname', 'firstname'])
                ->orderBy('login')->get(),
        );

        $mock->shouldReceive('updateGroup')->andReturnUsing(function (int $id, array $data): UserGroup {
            $group = UserGroup::findOrFail($id);

            $existingRoles = [];
            foreach ($group->users as $member) {
                $existingRoles[(int) $member->id] = $member->pivot->role ?? UserGroupUserPivot::ROLE_MEMBER;
            }

            $payload = [];
            foreach (($data['user_ids'] ?? []) as $uid) {
                $uid = (int) $uid;
                $payload[$uid] = [
                    'role' => $existingRoles[$uid]
                        ?? UserGroupUserPivot::defaultRoleForGlobalRole(User::find($uid)?->role),
                ];
            }

            $group->users()->sync($payload);

            return $group->fresh(['users']);
        });

        $mock->shouldReceive('resyncGroupAdProjection')->zeroOrMoreTimes();

        $this->app->bind(\App\Services\UserGroupService::class, fn () => $mock);
    }

    // =========================================================================
    // La garde elle-même, sans UI
    // =========================================================================

    #[Test]
    public function the_guard_names_the_role_the_type_and_the_declared_vocabulary(): void
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        try {
            RoleCatalog::assertAssignable('projet', 'tuteur');
            $this->fail('un rôle non déclaré devait être refusé');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Tuteur', $e->getMessage());
            $this->assertStringContainsString('projet', $e->getMessage());
            // Le message DIT ce qui est possible, il ne se contente pas de refuser.
            $this->assertStringContainsString('Membre', $e->getMessage());
            $this->assertStringContainsString('Porteur', $e->getMessage());
        }
    }

    #[Test]
    public function a_type_without_declaration_never_refuses_anything(): void
    {
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        foreach ([null, 'cours', 'matiere', 'inconnu'] as $type) {
            RoleCatalog::assertAssignable($type, 'tuteur');
            RoleCatalog::assertAssignable($type, 'owner');
        }

        $this->addToAssertionCount(8);
    }

    // =========================================================================
    // Point humain n°1 — `updateMemberRole()`
    // =========================================================================

    #[Test]
    public function updating_a_member_role_refuses_an_undeclared_role_with_a_business_message(): void
    {
        [$group, $user] = $this->projectWithMember();
        $this->actingAs($this->admin());

        Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->call('updateMemberRole', $user->id, 'tuteur')
            ->assertOk();

        // Refus MÉTIER, jamais un 500, et surtout : AUCUNE écriture.
        $this->assertSame(
            UserGroupUserPivot::ROLE_MEMBER,
            DB::table('user_group_user')->where('user_id', $user->id)->value('role'),
        );
    }

    #[Test]
    public function updating_a_member_role_accepts_a_declared_role(): void
    {
        [$group, $user] = $this->projectWithMember();
        $this->actingAs($this->admin());

        Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->call('updateMemberRole', $user->id, UserGroupUserPivot::ROLE_MANAGER)
            ->assertOk();

        $this->assertSame(
            UserGroupUserPivot::ROLE_MANAGER,
            DB::table('user_group_user')->where('user_id', $user->id)->value('role'),
        );
    }

    /**
     * D3 INCHANGÉE : `owner` sur un type non-`classe` reste refusé par la garde
     * LITTÉRALE, avec SON message — pas par la contrainte de déclaration.
     *
     * L'ordre compte : D3 parle en premier. C'est le message que les utilisateurs
     * connaissent depuis 42.3, et la contrainte nouvelle ne doit pas le remplacer
     * par un message plus générique.
     */
    #[Test]
    public function the_head_teacher_guard_still_speaks_first_and_unchanged(): void
    {
        [$group, $user] = $this->projectWithMember();
        $this->actingAs($this->admin());

        Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->call('updateMemberRole', $user->id, UserGroupUserPivot::ROLE_OWNER)
            ->assertOk();

        $this->assertSame(
            UserGroupUserPivot::ROLE_MEMBER,
            DB::table('user_group_user')->where('user_id', $user->id)->value('role'),
        );
    }

    /**
     * Sur un type SANS déclaration, c'est D3 — et elle seule — qui interdit
     * `owner`. C'est exactement pourquoi elle survit à la contrainte : le repli
     * générique autorise TOUT le catalogue, `owner` compris.
     */
    #[Test]
    public function on_an_undeclared_type_only_the_head_teacher_guard_blocks_owner(): void
    {
        $group = UserGroup::create(['name' => 'Cours_Maths', 'type' => 'cours']);
        $user = User::create(['login' => 'eleve.cours', 'role' => 'eleve', 'is_active' => true]);
        $group->users()->sync([$user->id => ['role' => UserGroupUserPivot::ROLE_MEMBER]]);

        // La contrainte, elle, laisserait passer :
        RoleCatalog::assertAssignable('cours', UserGroupUserPivot::ROLE_OWNER);

        $this->actingAs($this->admin());
        Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->call('updateMemberRole', $user->id, UserGroupUserPivot::ROLE_OWNER)
            ->assertOk();

        $this->assertSame(
            UserGroupUserPivot::ROLE_MEMBER,
            DB::table('user_group_user')->where('user_id', $user->id)->value('role'),
            'sans D3, un cours accepterait un « Professeur principal »',
        );
    }

    // =========================================================================
    // Point humain n°2 — `setPendingRole()`
    // =========================================================================

    #[Test]
    public function choosing_a_pending_role_refuses_an_undeclared_one(): void
    {
        [$group] = $this->projectWithMember();
        $candidate = User::create(['login' => 'nouveau.membre', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($this->admin());

        $component = Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->call('startEditing')
            ->call('toggleUser', $candidate->id);

        $before = $component->get('pendingRoles')[$candidate->id] ?? null;
        $this->assertNotNull($before, 'le candidat coché doit avoir un rôle proposé');

        $component->call('setPendingRole', $candidate->id, 'tuteur')->assertOk();

        $this->assertSame(
            $before,
            $component->get('pendingRoles')[$candidate->id],
            'un rôle non déclaré ne doit pas remplacer le rôle proposé',
        );

        // Et un rôle DÉCLARÉ passe.
        $component->call('setPendingRole', $candidate->id, UserGroupUserPivot::ROLE_MEMBER);
        $this->assertSame(
            UserGroupUserPivot::ROLE_MEMBER,
            $component->get('pendingRoles')[$candidate->id],
        );
    }

    /**
     * PIÈGE 42.3 #3 rejoué : le type lu par la garde est celui de la BASE, jamais
     * la propriété Livewire publique — qui est ré-hydratée du client et donc
     * forgeable. Un payload annonçant `type = cours` ne doit pas désarmer la
     * contrainte d'un projet.
     */
    #[Test]
    public function a_forged_type_property_does_not_disarm_the_pending_role_guard(): void
    {
        [$group] = $this->projectWithMember();
        $candidate = User::create(['login' => 'forge.membre', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($this->admin());

        $component = Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->call('startEditing')
            ->call('toggleUser', $candidate->id)
            // Le client prétend que le groupe est d'un type sans déclaration.
            ->set('type', 'cours')
            ->call('setPendingRole', $candidate->id, 'tuteur')
            ->assertOk();

        $this->assertNotSame(
            'tuteur',
            $component->get('pendingRoles')[$candidate->id],
            'la garde a lu le type CLIENT au lieu du type en base',
        );
    }

    // =========================================================================
    // Point humain n°3 — la revalidation de `save()`
    // =========================================================================

    /**
     * `save()` refuse en SILENCE (`continue`), comme les deux autres refus de ce
     * chemin : il traite un état client déjà validé, et ce qui y arrive de non
     * conforme est un payload forgé, pas un choix à commenter.
     */
    #[Test]
    public function saving_silently_drops_an_undeclared_override(): void
    {
        [$group] = $this->projectWithMember();
        $candidate = User::create(['login' => 'candidat.save', 'role' => 'prof', 'is_active' => true]);
        $this->actingAs($this->admin());

        // Le chemin de `save()` passe par `updateGroup()` (AD-first) : on lui
        // substitue le double SQL du harnais de la story 42.3, qui reproduit le
        // read-back sans annuaire.
        $this->bindFakeUserGroupService();

        $component = Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->call('startEditing')
            ->call('toggleUser', $candidate->id);

        // On force l'état client SANS passer par `setPendingRole()` — c'est le
        // scénario que la défense en profondeur existe pour couvrir.
        $component->set('pendingRoles', [$candidate->id => 'tuteur'])
            ->call('save')
            ->assertHasNoErrors();

        $stored = DB::table('user_group_user')->where('user_id', $candidate->id)->value('role');

        $this->assertNotSame('tuteur', $stored, 'la surcharge non déclarée devait être ignorée');
        $this->assertSame(UserGroupUserPivot::ROLE_MANAGER, $stored, 'le rôle DÉRIVÉ du prof doit rester');
    }

    // =========================================================================
    // AC6 — ce que les selects RENDENT
    // =========================================================================

    /**
     * PIÈGE NOMMÉ (Dev Notes #5) — la valeur COURANTE de l'arête reste une option
     * du select, même hors déclaration.
     *
     * Un `owner` hérité sur un projet est de la donnée PRÉ-CONTRAINTE : si le
     * select ne rendait que les rôles déclarés, le premier ré-enregistrement
     * dégraderait l'arête en silence. C'est la généralisation de la clause
     * `|| edge_role === 'owner'` qui existait avant 62.3.
     */
    #[Test]
    public function an_inherited_role_stays_visible_and_selected_in_the_member_select(): void
    {
        [$group] = $this->projectWithMember();
        $heir = User::create(['login' => 'owner.herite', 'role' => 'prof', 'is_active' => true]);
        DB::table('user_group_user')->insert([
            'user_id' => $heir->id,
            'user_group_id' => $group->id,
            'role' => UserGroupUserPivot::ROLE_OWNER,
        ]);

        $this->actingAs($this->admin());

        Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->assertOk()
            // La valeur héritée est rendue, et sélectionnée.
            ->assertSeeHtml('<option value="owner" selected>Propriétaire</option>')
            // Le vocabulaire déclaré du projet est là, lui aussi.
            ->assertSee('Porteur');
    }

    /**
     * Sur une classe, le select rend EXACTEMENT ce qu'il rendait avant la story :
     * trois options, dans l'ordre du catalogue, avec les libellés scolaires.
     */
    #[Test]
    public function a_class_select_renders_the_same_three_options_as_before(): void
    {
        $group = UserGroup::create(['name' => '3A', 'type' => 'classe']);
        $eleve = User::create(['login' => 'eleve.3a', 'role' => 'eleve', 'is_active' => true]);
        $group->users()->sync([$eleve->id => ['role' => UserGroupUserPivot::ROLE_MEMBER]]);

        $this->actingAs($this->admin());

        Livewire::test('pages::users.groups.[id].index', ['id' => $group->id])
            ->assertOk()
            ->assertSee('Élève')
            ->assertSee('Enseignant')
            ->assertSee('Professeur principal');
    }

    // =========================================================================
    // La LIBERTÉ des chemins d'import : un CONTRAT, testé positivement
    // =========================================================================

    /**
     * Un attach DIRECT d'un rôle hors déclaration PASSE — délibérément.
     *
     * C'est le pendant exact des trois tests ci-dessus. Le balayage d'annuaire, le
     * fold, l'import d'utilisateurs, le backfill et les factories écrivent ainsi.
     * Poser la garde sur le pivot ou dans `assertValidRole()` ferait tomber ce
     * test — et casserait le flux dont l'AD est autoritaire.
     */
    #[Test]
    public function a_direct_attach_of_an_undeclared_role_is_deliberately_free(): void
    {
        [$group] = $this->projectWithMember();
        $imported = User::create(['login' => 'importe.ad', 'role' => 'prof', 'is_active' => true]);

        // 1. Le chemin `sync()`/`attach()` d'Eloquent — celui du balayage.
        $group->users()->attach($imported->id, ['role' => 'tuteur']);

        $this->assertSame(
            'tuteur',
            DB::table('user_group_user')->where('user_id', $imported->id)->value('role'),
        );

        // 2. Et `assertValidRole()` n'a PAS été élargie : elle garde le
        //    vocabulaire GLOBAL, elle ignore le type — c'est sa sémantique et la
        //    story interdit d'y toucher.
        UserGroupUserPivot::assertValidRole('tuteur');
        $this->addToAssertionCount(1);
    }

    /**
     * AC4 — « IMPORT ⊆ DÉCLARATIONS SEEDÉES », le jumeau du « balayage ⊆ plancher »
     * de 62.2.
     *
     * La cohérence de l'ensemble ne tient pas par la garde mais par la
     * COMPOSITION : les chemins libres n'écrivent que des constantes du plancher,
     * et les déclarations de reprise les couvrent sur les types que ces chemins
     * produisent. Amputer le seed casse ce test — et c'est bien le but.
     */
    #[Test]
    public function everything_the_import_paths_write_is_covered_by_the_seeded_declarations(): void
    {
        // Ce que les chemins libres écrivent, en tout et pour tout.
        $writtenByImports = [
            UserGroupUserPivot::ROLE_MEMBER,
            UserGroupUserPivot::ROLE_MANAGER,
            UserGroupUserPivot::ROLE_OWNER,
        ];

        // `classe` — le fold legacy y pose `owner` (professeur principal) et le
        // read-back du trio AD y pose les trois.
        $this->assertSame($writtenByImports, RoleCatalog::assignableKeys('classe'));

        // `projet` et `equipe` — la dérivation au rattachement n'y produit que
        // `member` et `manager` (jamais `owner` : ce n'est pas un défaut).
        foreach (['projet', 'equipe'] as $type) {
            $assignable = RoleCatalog::assignableKeys($type);

            $this->assertContains(UserGroupUserPivot::ROLE_MEMBER, $assignable, $type . ' doit déclarer member');
            $this->assertContains(UserGroupUserPivot::ROLE_MANAGER, $assignable, $type . ' doit déclarer manager');
        }

        // Les types que la détection d'annuaire produit sans déclaration restent
        // en régime de repli : rien de ce que l'import écrit n'y est refusé.
        foreach (['cours', 'matiere', 'matiere_classe', 'role', 'function', 'custom'] as $type) {
            $this->assertSame([], GroupTypeRole::declaredFor($type), $type . ' ne doit pas être déclaré');

            foreach ($writtenByImports as $role) {
                RoleCatalog::assertAssignable($type, $role);
            }
        }

        $this->addToAssertionCount(18);
    }
}
