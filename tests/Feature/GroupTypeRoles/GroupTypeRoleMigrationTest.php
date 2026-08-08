<?php

declare(strict_types=1);

namespace Tests\Feature\GroupTypeRoles;

use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use Database\Seeders\GroupTypeRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 62.3 — AC1 : la table d'arête et ses SEPT lignes de reprise.
 *
 * Le test pivot de ce fichier n'est pas le schéma (il se lit) : c'est le
 * **contenu de la reprise**, et surtout ses DEUX écarts avec la constante qui
 * meurt. `projet`×`member` et `equipe`×`member` sont déclarés à `label = null` —
 * si quelqu'un « corrige » la migration en recopiant les cinq surcharges, ce
 * fichier tombe, et il tombe avant que `member` ne devienne inattribuable dans
 * tout projet.
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
     * LES SEPT LIGNES, exhaustivement, avec leurs libellés — et leurs `null`.
     */
    #[Test]
    public function the_migration_lays_the_seven_recovery_declarations(): void
    {
        $rows = DB::table('group_type_roles')
            ->orderBy('group_type_key')
            ->orderBy('group_role_key')
            ->get(['group_type_key', 'group_role_key', 'label'])
            ->map(fn (object $row): array => [
                (string) $row->group_type_key,
                (string) $row->group_role_key,
                $row->label === null ? null : (string) $row->label,
            ])
            ->all();

        $this->assertSame([
            ['classe', 'manager', 'Enseignant'],
            ['classe', 'member', 'Élève'],
            ['classe', 'owner', 'Professeur principal'],
            // DÉCLARÉS SANS SURCHARGE — l'écart assumé avec la constante défunte.
            ['equipe', 'manager', 'Référent'],
            ['equipe', 'member', null],
            ['projet', 'manager', 'Porteur'],
            ['projet', 'member', null],
        ], $rows);
    }

    /**
     * `owner` n'est déclaré QUE sur `classe` : c'est la donnée qui dit ce que la
     * garde D3 disait en littéral.
     */
    #[Test]
    public function owner_is_declared_on_the_class_type_and_nowhere_else(): void
    {
        $types = DB::table('group_type_roles')
            ->where('group_role_key', 'owner')
            ->pluck('group_type_key')
            ->all();

        $this->assertSame(['classe'], $types);
    }

    #[Test]
    public function the_migration_writes_nothing_outside_its_own_table(): void
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

        $this->assertSame(7, DB::table('group_type_roles')->count(), 'la migration rejouée a créé un doublon');

        foreach ($snapshots as $table => $before) {
            $after = DB::table($table)
                ->orderBy($table === 'user_group_user' ? 'user_id' : 'id')
                ->get()
                ->toJson();

            $this->assertSame($before, $after, 'la migration a écrit dans « ' . $table . ' »');
        }
    }

    /**
     * Le seeder ne fait pas de doublon non plus, et il ne réécrit pas un libellé
     * local qu'un administrateur aurait changé… si — et seulement si — il n'est
     * pas rejoué. Le seeder REsynchronise sur la baseline : c'est son contrat, et
     * il est différent de celui de la migration. Ce test épingle la différence.
     */
    #[Test]
    public function the_seeder_is_idempotent_and_resynchronises_the_baseline(): void
    {
        DB::table('group_type_roles')
            ->where('group_type_key', 'classe')
            ->where('group_role_key', 'manager')
            ->update(['label' => 'Professeur']);

        $stats = (new GroupTypeRoleSeeder())->run();

        $this->assertSame(['created' => 0, 'updated' => 7], $stats);
        $this->assertSame(7, DB::table('group_type_roles')->count());
        $this->assertSame(
            'Enseignant',
            DB::table('group_type_roles')
                ->where('group_type_key', 'classe')
                ->where('group_role_key', 'manager')
                ->value('label'),
        );
    }
}
