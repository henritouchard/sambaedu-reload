<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypeRoles;

use App\Models\GroupRole;
use App\Models\GroupType;
use App\Models\GroupTypeRole;
use App\Models\Pivot\UserGroupUserPivot;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.3 — AC2/AC5/AC9 : les gardes du modèle, les refus NOMMÉS, et ce que
 * la suppression d'un type emporte.
 */
class GroupTypeRoleGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(GroupRoleSeeder::class);

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        parent::tearDown();
    }

    /** Une arête réelle : c'est ce qui donne du poids au refus de retrait. */
    private function edge(string $groupType, string $role, string $login = 'membre'): UserGroup
    {
        $group = UserGroup::create(['name' => 'G_' . $login, 'type' => $groupType]);
        $user = User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);

        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
            'role' => $role,
        ]);

        return $group;
    }

    // =========================================================================
    // AC2 — les gardes du modèle
    // =========================================================================

    #[Test]
    public function a_role_outside_the_catalog_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rôle inconnu du catalogue');

        GroupTypeRole::create(['group_type_key' => 'classe', 'group_role_key' => 'inexistant']);
    }

    #[Test]
    public function a_type_absent_from_the_catalog_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type de groupe inconnu du catalogue');

        GroupTypeRole::create(['group_type_key' => 'jamais_vu', 'group_role_key' => 'member']);
    }

    #[Test]
    public function an_empty_type_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type de groupe manquant');

        GroupTypeRole::create(['group_type_key' => '', 'group_role_key' => 'member']);
    }

    #[Test]
    public function the_pair_is_immutable(): void
    {
        $declaration = GroupTypeRole::where('group_type_key', 'projet')
            ->where('group_role_key', 'manager')
            ->firstOrFail();

        // Le libellé, lui, se modifie sans rien réveiller.
        $declaration->label = 'Chef de projet';
        $declaration->save();
        $this->assertSame('Chef de projet', $declaration->fresh()->label);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('immuable');

        $declaration->group_type_key = 'equipe';
        $declaration->save();
    }

    /**
     * PIÈGE NOMMÉ (review 62.2 #1) — une clé de type HÉRITÉE n'est pas un slug, et
     * la garde ne doit vérifier que son EXISTENCE.
     */
    #[Test]
    public function an_inherited_non_slug_type_key_is_fully_usable(): void
    {
        DB::table('group_types')->insert([
            'key' => 'Custom',
            'label' => 'Custom hérité',
            'icon' => null,
            'sort_order' => 42,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Écriture.
        $declaration = GroupTypeRole::create([
            'group_type_key' => 'Custom',
            'group_role_key' => 'manager',
            'label' => 'Animateur',
        ]);
        $this->assertTrue($declaration->exists);

        // Résolution.
        $this->assertSame('Animateur', RoleCatalog::label('Custom', 'manager'));
        $this->assertSame(['manager'], RoleCatalog::assignableKeys('Custom'));

        // Refus de retrait — la comparaison de type est insensible à la casse
        // parce que c'est ainsi que la RÉSOLUTION apparie : un groupe stocké
        // `custom` lit bien les déclarations de `Custom` faute d'exact, donc son
        // arête compte.
        $this->edge('custom', 'manager', 'anim.custom');
        $this->assertNotNull($declaration->removalRefusal());
    }

    // =========================================================================
    // AC5 — le refus de retrait, nommé et chiffré
    // =========================================================================

    #[Test]
    public function removing_a_declaration_carried_by_edges_is_refused_with_the_count(): void
    {
        $group = $this->edge('classe', UserGroupUserPivot::ROLE_MANAGER, 'prof.un');
        $second = User::create(['login' => 'prof.deux', 'role' => 'prof', 'is_active' => true]);
        DB::table('user_group_user')->insert([
            'user_id' => $second->id,
            'user_group_id' => $group->id,
            'role' => UserGroupUserPivot::ROLE_MANAGER,
        ]);

        $declaration = GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', UserGroupUserPivot::ROLE_MANAGER)
            ->firstOrFail();

        $refusal = $declaration->removalRefusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('2 appartenances', $refusal);
        $this->assertStringContainsString('Enseignant', $refusal);
        $this->assertStringContainsString('classe', $refusal);
        $this->assertStringContainsString('Aucune donnée n\'a été modifiée', $refusal);

        // AUCUNE écriture n'a eu lieu : ni la déclaration, ni les arêtes.
        $this->assertTrue(GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', UserGroupUserPivot::ROLE_MANAGER)->exists());
        $this->assertSame(2, DB::table('user_group_user')->where('role', 'manager')->count());
    }

    #[Test]
    public function the_singular_form_is_used_for_a_single_edge(): void
    {
        $this->edge('classe', UserGroupUserPivot::ROLE_OWNER, 'pp.unique');

        $refusal = GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', UserGroupUserPivot::ROLE_OWNER)
            ->firstOrFail()
            ->removalRefusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('1 appartenance porte', $refusal);
    }

    #[Test]
    public function removing_a_declaration_no_edge_carries_is_accepted(): void
    {
        $this->edge('classe', UserGroupUserPivot::ROLE_MEMBER, 'eleve.un');

        $declaration = GroupTypeRole::where('group_type_key', 'projet')
            ->where('group_role_key', UserGroupUserPivot::ROLE_MANAGER)
            ->firstOrFail();

        $this->assertNull($declaration->removalRefusal());

        $declaration->delete();

        $this->assertSame(['member'], RoleCatalog::assignableKeys('projet'));
    }

    /**
     * L'arête est comptée sur le type NORMALISÉ, parce que c'est ainsi que la
     * résolution apparie — un groupe stocké `Classe` lit les libellés de `classe`.
     * Compter en exact laisserait passer un retrait qui casse un affichage réel.
     */
    #[Test]
    public function edges_are_counted_the_way_the_resolution_matches(): void
    {
        $this->edge('Classe', UserGroupUserPivot::ROLE_MANAGER, 'prof.casse');

        $refusal = GroupTypeRole::where('group_type_key', 'classe')
            ->where('group_role_key', UserGroupUserPivot::ROLE_MANAGER)
            ->firstOrFail()
            ->removalRefusal();

        $this->assertNotNull($refusal, 'une arête sur « Classe » doit compter pour « classe »');
    }

    // =========================================================================
    // AC9 — suppressions croisées
    // =========================================================================

    /**
     * Un rôle DÉCLARÉ n'est pas supprimable du catalogue : il faut d'abord le
     * retirer des types qui le déclarent, et le message dit où aller.
     */
    #[Test]
    public function a_declared_role_is_refused_at_the_role_catalog(): void
    {
        $role = GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);
        GroupTypeRole::create(['group_type_key' => 'projet', 'group_role_key' => 'tuteur']);
        GroupTypeRole::create(['group_type_key' => 'equipe', 'group_role_key' => 'tuteur']);

        $refusal = $role->deletionRefusal();

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('2 types de groupes déclarent', $refusal);
        $this->assertStringContainsString('Types de groupes', $refusal);
        $this->assertSame(2, $role->usage()['group_types']);

        // Retirer les déclarations lève le refus.
        GroupTypeRole::where('group_role_key', 'tuteur')->get()->each->delete();
        $this->assertNull($role->fresh()->deletionRefusal());
    }

    #[Test]
    public function a_single_declaring_type_is_named_in_the_singular(): void
    {
        $role = GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);
        GroupTypeRole::create(['group_type_key' => 'projet', 'group_role_key' => 'tuteur']);

        $this->assertStringContainsString('1 type de groupes déclare', (string) $role->deletionRefusal());
    }

    /**
     * La suppression LÉGITIME d'un type EMPORTE ses déclarations — ce ne sont pas
     * des données métier, ce sont des attributs du type, comme son icône.
     */
    #[Test]
    public function deleting_a_type_carries_away_its_declarations(): void
    {
        $type = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);
        GroupTypeRole::create(['group_type_key' => 'club', 'group_role_key' => 'member', 'label' => 'Adhérent']);
        GroupTypeRole::create(['group_type_key' => 'club', 'group_role_key' => 'manager', 'label' => 'Animateur']);

        $this->assertSame('Adhérent', RoleCatalog::label('club', 'member'));
        $this->assertNull($type->deletionRefusal(), 'le décor doit être supprimable');

        $type->delete();

        $this->assertSame(0, DB::table('group_type_roles')->where('group_type_key', 'club')->count());
        // Et la mémo a suivi : la lecture retombe sur le catalogue.
        $this->assertSame('Membre', RoleCatalog::label('club', 'member'));

        // Les AUTRES déclarations, elles, sont intactes : rien n'a cascadé
        // au-delà du type supprimé.
        $this->assertSame(7, DB::table('group_type_roles')->count());
    }

    /**
     * Contre-épreuve : un type dont la suppression est REFUSÉE garde tout — le
     * refus n'écrit rien, jamais.
     */
    #[Test]
    public function a_refused_type_deletion_leaves_its_declarations_untouched(): void
    {
        $type = GroupType::create(['key' => 'club', 'label' => 'Club', 'sort_order' => 20]);
        GroupTypeRole::create(['group_type_key' => 'club', 'group_role_key' => 'member']);
        UserGroup::create(['name' => 'Club_Echecs', 'type' => 'club']);

        $this->assertNotNull($type->deletionRefusal());
        $this->assertSame(1, DB::table('group_type_roles')->where('group_type_key', 'club')->count());
    }
}
