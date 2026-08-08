<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypeRoles;

use App\Models\GroupRole;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Support\RoleCatalog;
use Database\Seeders\GroupRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.3 — AC1 : la table d'arête, et le fait qu'elle NAÎT VIDE.
 *
 * Ce fichier a changé d'objet. Il épinglait les sept déclarations de reprise que la
 * migration posait ; elles sont parties dans une commande d'administration
 * ({@see \App\Console\Commands\CollegeSeedRoleXTypeCommand}, éprouvée par
 * {@see CollegeSeedRoleXTypeCommandTest}). Ce qu'il prouve désormais est l'inverse,
 * et c'est le point : **la migration n'impose rien**.
 *
 * Pourquoi ça compte. Une déclaration RESTREINT — un type qui en porte n'accepte
 * plus que les rôles déclarés. Poser sept lignes en migration fermait `classe`,
 * `projet` et `equipe` sur toute instance, y compris celles qui n'ont jamais vu une
 * école, et le premier rôle personnalisé créé par un administrateur y aurait été
 * inattribuable. {@see self::a_migrated_database_closes_no_type()} est la preuve
 * exécutable que ce n'est plus le cas.
 */
class GroupTypeRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

    #[Test]
    public function the_table_exists_with_its_composite_unique_index(): void
    {
        $this->assertTrue(Schema::hasTable('group_type_roles'));

        foreach (['group_type_key', 'group_role_key', 'label', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('group_type_roles', $column),
                'colonne manquante : ' . $column,
            );
        }

        // L'unicité de la paire n'est pas décorative : c'est le filet du
        // check-then-act de la modale.
        DB::table('group_type_roles')->insert([
            'group_type_key' => 'cours',
            'group_role_key' => 'member',
            'label' => null,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('group_type_roles')->insert([
            'group_type_key' => 'cours',
            'group_role_key' => 'member',
            'label' => 'Doublon',
        ]);
    }

    /**
     * LA TABLE NAÎT VIDE — zéro ligne, aucune exception.
     *
     * C'est l'assertion qui remplace « les sept lignes de reprise ». Si quelqu'un
     * remet un seed dans la migration, ce test tombe le premier, et il tombe avant
     * que trois types ne se referment sur toutes les instances du produit.
     */
    #[Test]
    public function the_migration_creates_an_empty_table(): void
    {
        $this->assertSame(0, DB::table('group_type_roles')->count());
    }

    /**
     * LA PREUVE que la migration n'impose RIEN.
     *
     * Sur une base migrée mais sans profil installé, aucun type n'est fermé :
     * `assignableKeys()` rend TOUT le catalogue, partout — et un rôle personnalisé
     * créé après coup est attribuable dans `classe`, `projet` et `equipe` comme
     * ailleurs. C'était exactement ce que les sept lignes de la migration
     * interdisaient.
     */
    #[Test]
    public function a_migrated_database_closes_no_type(): void
    {
        $this->seed(GroupRoleSeeder::class);
        GroupRole::create(['key' => 'tuteur', 'label' => 'Tuteur', 'sort_order' => 9]);

        $whole = ['member', 'manager', 'owner', 'tuteur'];
        $this->assertSame($whole, RoleCatalog::keys());

        foreach ([null, 'classe', 'projet', 'equipe', 'cours', 'custom', 'inconnu'] as $type) {
            $this->assertSame(
                $whole,
                RoleCatalog::assignableKeys($type),
                'le type « ' . var_export($type, true) . ' » ne doit être fermé par aucune migration',
            );
            $this->assertSame([], RoleCatalog::declarationsFor((string) $type));

            // Et le rôle personnalisé y est bel et bien ATTRIBUABLE, pas seulement
            // listé : c'est la garde qui le dit.
            RoleCatalog::assertAssignable($type, 'tuteur');
        }

        $this->addToAssertionCount(7);
    }

    /**
     * Les libellés scolaires ne sont posés par PERSONNE tant que la commande n'a
     * pas été lancée — corollaire multi-vertical de la story.
     */
    #[Test]
    public function a_migrated_database_speaks_no_school_vocabulary(): void
    {
        $this->seed(GroupRoleSeeder::class);

        $this->assertSame('Membre', RoleCatalog::label('classe', 'member'));
        $this->assertSame('Gestionnaire', RoleCatalog::label('classe', 'manager'));
        $this->assertSame('Propriétaire', RoleCatalog::label('classe', 'owner'));
        $this->assertSame('Gestionnaire', RoleCatalog::label('projet', 'manager'));
        $this->assertSame('Gestionnaire', RoleCatalog::label('equipe', 'manager'));
    }

    #[Test]
    public function the_migration_writes_nothing_anywhere(): void
    {
        $group = UserGroup::create(['name' => 'Classe_3emeA', 'type' => 'classe']);
        $user = User::create(['login' => 'alecoz', 'role' => 'prof', 'is_active' => true]);
        DB::table('user_group_user')->insert([
            'user_id' => $user->id,
            'user_group_id' => $group->id,
            'role' => 'manager',
        ]);

        $snapshots = [
            'user_groups' => DB::table('user_groups')->orderBy('id')->get()->toJson(),
            'user_group_user' => DB::table('user_group_user')->orderBy('user_id')->get()->toJson(),
            'group_roles' => DB::table('group_roles')->orderBy('id')->get()->toJson(),
            'group_types' => DB::table('group_types')->orderBy('id')->get()->toJson(),
        ];

        // On rejoue la migration : idempotence ET innocuité, d'un seul geste.
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('group_type_roles')->count(), 'la migration rejouée a semé des lignes');

        foreach ($snapshots as $table => $before) {
            $after = DB::table($table)
                ->orderBy($table === 'user_group_user' ? 'user_id' : 'id')
                ->get()
                ->toJson();

            $this->assertSame($before, $after, 'la migration a écrit dans « ' . $table . ' »');
        }
    }
}
