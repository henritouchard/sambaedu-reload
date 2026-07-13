<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Actions\Groups\BackfillUserGroupUserRoles;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 42.1 — Tests du BACKFILL de la colonne d'arête `role`.
 *
 * On teste l'action invocable {@see BackfillUserGroupUserRoles} (que la
 * migration 2026_07_13 appelle exactement) sur un état SQL monté à la main
 * (patron {@see MergeLegacyUserGroupsMigrationTest}). Vérifie la dérivation
 * `owner`/`manager`/`member`, la précédence `owner > manager > member`,
 * l'idempotence, et l'absence de tout appel LDAP (dérivation SQL pure).
 */
class BackfillUserGroupUserRolesTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTestTables();
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('user_group_user');
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    #[Test]
    public function it_derives_owner_manager_member_from_existing_state(): void
    {
        // AC2 — owner (PP), manager (prof non-PP), member (élève).
        $eleve = $this->mkUser('eleve.un', 'eleve');
        $prof = $this->mkUser('prof.un', 'prof');
        $pp = $this->mkUser('prof.pp', 'prof');
        $g = $this->mkGroup('3A');

        $this->link($g, $eleve, false);
        $this->link($g, $prof, false);
        $this->link($g, $pp, true); // is_head_teacher = true

        $report = (new BackfillUserGroupUserRoles())();

        $this->assertSame('member', $this->role($g, $eleve));
        $this->assertSame('manager', $this->role($g, $prof));
        $this->assertSame('owner', $this->role($g, $pp));

        $this->assertSame(['member' => 1, 'manager' => 1, 'owner' => 1, 'total' => 3], $report);
    }

    #[Test]
    public function owner_precedence_wins_over_manager_for_a_pp_prof(): void
    {
        // AC2 — précédence owner > manager : un prof PP est owner, pas manager
        // (même s'il est prof, le flag is_head_teacher a la priorité).
        $pp = $this->mkUser('prof.pp', 'prof');
        $g = $this->mkGroup('3A');
        $this->link($g, $pp, true);

        (new BackfillUserGroupUserRoles())();

        $this->assertSame('owner', $this->role($g, $pp));
    }

    #[Test]
    public function admin_and_autre_global_roles_fall_back_to_member(): void
    {
        // AC2 — seul `prof` dérive manager ; admin/autre/null → member.
        $admin = $this->mkUser('admin.un', 'admin');
        $autre = $this->mkUser('autre.un', 'autre');
        $g = $this->mkGroup('3A');
        $this->link($g, $admin, false);
        $this->link($g, $autre, false);

        (new BackfillUserGroupUserRoles())();

        $this->assertSame('member', $this->role($g, $admin));
        $this->assertSame('member', $this->role($g, $autre));
    }

    #[Test]
    public function it_is_idempotent_across_two_runs(): void
    {
        // AC2 — rejouer l'action = même état final, aucune exception.
        $eleve = $this->mkUser('eleve.un', 'eleve');
        $prof = $this->mkUser('prof.un', 'prof');
        $pp = $this->mkUser('prof.pp', 'prof');
        $g = $this->mkGroup('3A');
        $this->link($g, $eleve, false);
        $this->link($g, $prof, false);
        $this->link($g, $pp, true);

        $first = (new BackfillUserGroupUserRoles())();
        $second = (new BackfillUserGroupUserRoles())();

        $this->assertSame($first, $second, '2 runs = même compte-rendu');
        $this->assertSame('member', $this->role($g, $eleve));
        $this->assertSame('manager', $this->role($g, $prof));
        $this->assertSame('owner', $this->role($g, $pp));
    }

    #[Test]
    public function it_is_a_noop_on_empty_pivot(): void
    {
        // Install fraîche : base vide → no-op, compte-rendu à zéro.
        $report = (new BackfillUserGroupUserRoles())();
        $this->assertSame(['member' => 0, 'manager' => 0, 'owner' => 0, 'total' => 0], $report);
    }

    #[Test]
    public function it_reconverges_a_stale_role_from_current_state(): void
    {
        // Idempotence robuste : une arête au `role` obsolète (owner posé à tort
        // sur un simple prof sans flag) est réalignée sur l'état courant.
        $prof = $this->mkUser('prof.un', 'prof');
        $g = $this->mkGroup('3A');
        DB::table('user_group_user')->insert([
            'user_group_id' => $g,
            'user_id' => $prof,
            'is_head_teacher' => false,
            'role' => 'owner', // état sale
        ]);

        (new BackfillUserGroupUserRoles())();

        $this->assertSame('manager', $this->role($g, $prof), 'owner sans flag → réaligné manager');
    }

    #[Test]
    public function real_migration_up_adds_column_backfills_and_down_drops_it(): void
    {
        // AC1 — exerce la MIGRATION RÉELLE : up() ajoute la colonne `role`
        // (string 20, défaut member, rétro-remplie sur les arêtes existantes)
        // PUIS backfille (owner/manager/member) ; down() la retire.
        $pp = $this->mkUser('prof.pp', 'prof');
        $eleve = $this->mkUser('eleve.un', 'eleve');
        $g = $this->mkGroup('9Z');

        // Repartir d'un pivot SANS la colonne role.
        Schema::table('user_group_user', static function (Blueprint $table): void {
            $table->dropColumn('role');
        });
        DB::table('user_group_user')->insert([
            ['user_group_id' => $g, 'user_id' => $pp, 'is_head_teacher' => true],
            ['user_group_id' => $g, 'user_id' => $eleve, 'is_head_teacher' => false],
        ]);
        $this->assertFalse(Schema::hasColumn('user_group_user', 'role'));

        $migration = require database_path('migrations/2026_07_13_120000_add_role_to_user_group_user.php');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('user_group_user', 'role'), 'up() ajoute la colonne');
        // Backfill appliqué : PP → owner, élève → member.
        $this->assertSame('owner', $this->role($g, $pp));
        $this->assertSame('member', $this->role($g, $eleve));

        // Idempotence de up() (hasColumn court-circuite l'ajout, backfill rejoué).
        $migration->up();
        $this->assertSame('owner', $this->role($g, $pp));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('user_group_user', 'role'), 'down() retire la colonne');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function role(int $groupId, int $userId): string
    {
        return (string) DB::table('user_group_user')
            ->where('user_group_id', $groupId)
            ->where('user_id', $userId)
            ->value('role');
    }

    private function mkUser(string $login, string $globalRole): int
    {
        return (int) DB::table('users')->insertGetId([
            'login' => $login,
            'role' => $globalRole,
            'is_active' => true,
        ]);
    }

    private function mkGroup(string $name): int
    {
        return (int) DB::table('user_groups')->insertGetId([
            'name' => $name,
            'display_name' => $name,
            'type' => 'classe',
        ]);
    }

    private function link(int $groupId, int $userId, bool $isHeadTeacher): void
    {
        DB::table('user_group_user')->insert([
            'user_group_id' => $groupId,
            'user_id' => $userId,
            'is_head_teacher' => $isHeadTeacher,
            // role laissé au défaut DB 'member' (le backfill le recalcule).
        ]);
    }

    private function createTestTables(): void
    {
        if (!Schema::hasTable('users')) {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
                $table->string('login')->unique();
                $table->string('role')->default('autre');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->string('type');
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_group_id');
                $table->unsignedBigInteger('user_id');
                $table->boolean('is_head_teacher')->default(false);
                $table->string('role', 20)->default('member');
                $table->primary(['user_group_id', 'user_id']);
            });
            $this->createdTables = true;
        }
    }
}
