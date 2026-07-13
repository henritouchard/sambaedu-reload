<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Actions\Groups\MergeLegacyUserGroups;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 4.14 — Tests de la MIGRATION DE DONNÉES (fusion des lignes héritées).
 *
 * On teste la logique de fusion via l'action invocable
 * {@see MergeLegacyUserGroups} (la migration appelle exactement cette action
 * après avoir créé la colonne) sur un état SQL monté à la main — plus fiable et
 * lisible que jouer `migrate:rollback`/`migrate` en SQLite.
 *
 * Les tables sont créées localement (parité avec
 * `UserGroupServiceLegacyCompatibilityTest`) avec la colonne `is_head_teacher`.
 */
class MergeLegacyUserGroupsMigrationTest extends TestCase
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
    public function it_merges_three_legacy_rows_into_one_bare_row(): void
    {
        // AC2 — Classe_3A={alice}, Equipe_3A={bob}, PP_3A={bob} → 1 ligne `3A`
        // type classe, membres {alice,bob} (union dédupliquée), 3 lignes
        // disparues, aucun membre perdu.
        $alice = $this->mkUser('alice');
        $bob = $this->mkUser('bob');

        $classe = $this->mkGroup('Classe_3A', 'classe', 'guid-classe', 'CN=Classe_3A');
        $equipe = $this->mkGroup('Equipe_3A', 'equipe', 'guid-equipe', 'CN=Equipe_3A');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'guid-pp', 'CN=PP_3A');

        $this->link($classe, $alice);
        $this->link($equipe, $bob);
        $this->link($pp, $bob);

        (new MergeLegacyUserGroups())();

        $rows = DB::table('user_groups')->orderBy('name')->pluck('name')->all();
        $this->assertSame(['3A'], $rows, 'une seule ligne nue subsiste');

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        $this->assertSame('classe', $survivor->type);

        $members = DB::table('user_group_user')
            ->where('user_group_id', $survivor->id)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->sort()->values()->all();
        $this->assertSame([(int) $alice, (int) $bob], $members, 'union {alice,bob} sans perte');
    }

    #[Test]
    public function it_keeps_canonical_classe_guid_after_merge(): void
    {
        // AC3 — survivante = Classe_ → porte ses ad_guid/ad_dn.
        $this->mkGroup('Equipe_3A', 'equipe', 'guid-equipe', 'CN=Equipe_3A');
        $this->mkGroup('Classe_3A', 'classe', 'guid-classe', 'CN=Classe_3A');
        $this->mkGroup('PP_3A', 'equipe', 'guid-pp', 'CN=PP_3A');

        (new MergeLegacyUserGroups())();

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        $this->assertSame('guid-classe', $survivor->ad_guid);
        $this->assertSame('CN=Classe_3A', $survivor->ad_dn);
    }

    #[Test]
    public function it_falls_back_to_equipe_guid_when_classe_absent(): void
    {
        // AC3 — Classe_ absent → fallback déterministe Equipe_ (prime sur PP_).
        $this->mkGroup('PP_3A', 'equipe', 'guid-pp', 'CN=PP_3A');
        $this->mkGroup('Equipe_3A', 'equipe', 'guid-equipe', 'CN=Equipe_3A');

        (new MergeLegacyUserGroups())();

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        $this->assertSame('guid-equipe', $survivor->ad_guid);
        $this->assertSame('CN=Equipe_3A', $survivor->ad_dn);
        $this->assertSame('classe', $survivor->type);
    }

    #[Test]
    public function it_flags_head_teacher_from_pp_row(): void
    {
        // AC4 — après fusion, (3A,bob).is_head_teacher=true (bob ∈ PP_3A),
        // (3A,alice)=false.
        $alice = $this->mkUser('alice');
        $bob = $this->mkUser('bob');

        $classe = $this->mkGroup('Classe_3A', 'classe', 'g1', 'CN=Classe_3A');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g2', 'CN=PP_3A');

        $this->link($classe, $alice);
        $this->link($pp, $bob);

        (new MergeLegacyUserGroups())();

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        $this->assertTrue($this->flag($survivor->id, (int) $bob));
        $this->assertFalse($this->flag($survivor->id, (int) $alice));
    }

    #[Test]
    public function it_flags_multiple_head_teachers_from_pp_row(): void
    {
        // AC4 (multi-PP) — PP_3A={bob,carol} → les deux true.
        $alice = $this->mkUser('alice');
        $bob = $this->mkUser('bob');
        $carol = $this->mkUser('carol');

        $classe = $this->mkGroup('Classe_3A', 'classe', 'g1', 'CN=Classe_3A');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g2', 'CN=PP_3A');

        $this->link($classe, $alice);
        $this->link($pp, $bob);
        $this->link($pp, $carol);

        (new MergeLegacyUserGroups())();

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        $this->assertTrue($this->flag($survivor->id, (int) $bob));
        $this->assertTrue($this->flag($survivor->id, (int) $carol));
        $this->assertFalse($this->flag($survivor->id, (int) $alice));
    }

    #[Test]
    public function it_is_idempotent_on_repeated_runs(): void
    {
        // AC5 — un 2e run = no-op (plus de ligne préfixée).
        $alice = $this->mkUser('alice');
        $bob = $this->mkUser('bob');

        $classe = $this->mkGroup('Classe_3A', 'classe', 'g1', 'CN=Classe_3A');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g2', 'CN=PP_3A');
        $this->link($classe, $alice);
        $this->link($pp, $bob);

        (new MergeLegacyUserGroups())();
        $afterFirst = DB::table('user_groups')->orderBy('name')->pluck('name')->all();

        $report = (new MergeLegacyUserGroups())();
        $afterSecond = DB::table('user_groups')->orderBy('name')->pluck('name')->all();

        $this->assertSame($afterFirst, $afterSecond, 'aucune ligne créée/supprimée au 2e run');
        $this->assertSame(0, $report['merged_bases'], 'aucune base fusionnée au 2e run');
        $this->assertSame(0, $report['removed_rows']);

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        $this->assertTrue($this->flag($survivor->id, (int) $bob), 'flag stable au 2e run');
    }

    #[Test]
    public function it_is_noop_on_already_folded_base(): void
    {
        // AC5 — base déjà foldée par 4.13 (seulement la ligne nue, pas de
        // préfixées) → no-op.
        $alice = $this->mkUser('alice');
        $bare = $this->mkGroup('3A', 'classe', 'g1', 'CN=Classe_3A');
        $this->link($bare, $alice);

        $report = (new MergeLegacyUserGroups())();

        $this->assertSame(0, $report['merged_bases']);
        $this->assertSame(0, $report['removed_rows']);
        $this->assertSame(0, $report['renamed_orphans']);
        $this->assertSame(['3A'], DB::table('user_groups')->pluck('name')->all());
    }

    #[Test]
    public function it_merges_into_preexisting_bare_row_without_unique_collision(): void
    {
        // AC6 — ligne nue `3A` (membres {alice}) + reliquat PP_3A (membres {bob}).
        // Après fusion : UNE ligne `3A`, membres {alice,bob}, (3A,bob)=true,
        // (3A,alice)=false. La ligne nue est la survivante (D1) — pas de violation
        // UNIQUE sur `name`.
        $alice = $this->mkUser('alice');
        $bob = $this->mkUser('bob');

        $bare = $this->mkGroup('3A', 'classe', 'g-bare', 'CN=Classe_3A');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g-pp', 'CN=PP_3A');

        $this->link($bare, $alice);
        $this->link($pp, $bob);

        (new MergeLegacyUserGroups())();

        $rows = DB::table('user_groups')->orderBy('name')->pluck('name')->all();
        $this->assertSame(['3A'], $rows);

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        // La survivante est la ligne nue préexistante (D1).
        $this->assertSame((int) $bare, (int) $survivor->id);

        $members = DB::table('user_group_user')
            ->where('user_group_id', $survivor->id)
            ->pluck('user_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame([(int) $alice, (int) $bob], $members);

        $this->assertTrue($this->flag($survivor->id, (int) $bob));
        $this->assertFalse($this->flag($survivor->id, (int) $alice));
    }

    #[Test]
    public function it_renames_orphan_equipe_without_merging_with_cours(): void
    {
        // AC7 — Cours_Maths5A (type cours) + Equipe_Maths5A (type equipe, pas de
        // Classe_/PP_, pas de ligne nue Maths5A). Après migration : Cours_Maths5A
        // inchangée (CN, type cours) ; Equipe_Maths5A renommée `Maths5A` type
        // equipe SANS fusion avec le cours. Aucun flag PP.
        $this->mkGroup('Cours_Maths5A', 'cours', 'g-cours', 'CN=Cours_Maths5A');
        $this->mkGroup('Equipe_Maths5A', 'equipe', 'g-eq', 'CN=Equipe_Maths5A');

        (new MergeLegacyUserGroups())();

        $names = DB::table('user_groups')->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Cours_Maths5A', 'Maths5A'], $names);

        $cours = DB::table('user_groups')->where('name', 'Cours_Maths5A')->first();
        $this->assertSame('cours', $cours->type, 'le cours reste CN + type cours');

        $equipe = DB::table('user_groups')->where('name', 'Maths5A')->first();
        $this->assertSame('equipe', $equipe->type, 'équipe orpheline → nom nu type equipe');
    }

    #[Test]
    public function it_renames_orphan_equipe_idempotently(): void
    {
        // AC5/AC7 — un Equipe_ orphelin renommé reste stable (déjà nu au 2e run).
        $this->mkGroup('Equipe_Solo5A', 'equipe', 'g-eq', 'CN=Equipe_Solo5A');

        (new MergeLegacyUserGroups())();
        $this->assertSame('equipe', DB::table('user_groups')->where('name', 'Solo5A')->first()->type);

        $report = (new MergeLegacyUserGroups())();
        $this->assertSame(0, $report['renamed_orphans'], '2e run = no-op sur l\'orphelin déjà nu');
        $this->assertSame('equipe', DB::table('user_groups')->where('name', 'Solo5A')->first()->type);
    }

    #[Test]
    public function it_flags_member_present_on_survivor_and_in_pp(): void
    {
        // Review #4 — alice est DÉJÀ sur la ligne nue survivante ET dans PP_3A.
        // Le report (insertOrIgnore) l'ignore (déjà présente), mais le marquage
        // PP doit quand même poser is_head_teacher=true sur la survivante.
        $alice = $this->mkUser('alice');

        $nue = $this->mkGroup('3A', 'classe', 'g-nue', 'CN=3A');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g-pp', 'CN=PP_3A');
        $this->link($nue, $alice);   // alice déjà sur la survivante
        $this->link($pp, $alice);    // alice aussi PP

        (new MergeLegacyUserGroups())();

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        $this->assertSame((int) $nue, (int) $survivor->id, 'la ligne nue préexistante reste la survivante');
        $this->assertTrue($this->flag((int) $survivor->id, $alice), 'alice (sur survivante + PP) doit être flaggée');
    }

    #[Test]
    public function it_flags_head_teacher_when_pp_row_is_lonely(): void
    {
        // Review #2 — un PP_3A ISOLÉ (sans Classe_/Equipe_/nue) : renommé en 3A
        // type classe ET ses membres flaggés is_head_teacher=true.
        $bob = $this->mkUser('bob');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g-pp', 'CN=PP_3A');
        $this->link($pp, $bob);

        $report = (new MergeLegacyUserGroups())();

        $survivor = DB::table('user_groups')->where('name', '3A')->first();
        $this->assertNotNull($survivor, 'PP_3A isolé renommé en 3A');
        $this->assertSame('classe', $survivor->type);
        $this->assertTrue($this->flag((int) $survivor->id, $bob), 'membre d\'un PP_ isolé doit être flaggé PP');
        $this->assertSame(1, $report['head_teachers_flagged']);
    }

    #[Test]
    public function it_skips_and_reports_collision(): void
    {
        // Review #7 — une ligne nue homonyme NON regroupée (type cours, hors
        // périmètre de fold) occupe déjà `3A` → la ligne préfixée isolée
        // Classe_3A ne peut pas être renommée : on laisse en l'état ET on trace.
        $this->mkGroup('3A', 'cours', 'g-cours', 'CN=3A');       // non regroupée (type cours)
        $this->mkGroup('Classe_3A', 'classe', 'g-cl', 'CN=Classe_3A'); // isolée → rename impossible

        $report = (new MergeLegacyUserGroups())();

        $this->assertSame(1, $report['skipped_collisions'], 'la collision doit être comptée');
        $this->assertSame(0, $report['renamed_orphans'], 'aucun rename sur collision');
        // La ligne préfixée reste en l'état (pas de violation d'unicité).
        $this->assertTrue(DB::table('user_groups')->where('name', 'Classe_3A')->exists());
        $this->assertTrue(DB::table('user_groups')->where('name', '3A')->where('type', 'cours')->exists());
    }

    #[Test]
    public function it_real_migration_adds_column_retrofills_false_and_down_drops_it(): void
    {
        // Review #3 (AC1) — exerce la MIGRATION RÉELLE : up() ajoute la colonne
        // (rétro-remplie false sur les arêtes existantes) ; down() la retire.
        $zoe = $this->mkUser('zoe');
        $g = $this->mkGroup('9Z', 'classe', 'g-9z', 'CN=9Z');

        // Repartir d'un pivot SANS la colonne is_head_teacher.
        Schema::table('user_group_user', static function (Blueprint $table): void {
            $table->dropColumn('is_head_teacher');
        });
        DB::table('user_group_user')->insert(['user_group_id' => $g, 'user_id' => $zoe]);
        $this->assertFalse(Schema::hasColumn('user_group_user', 'is_head_teacher'));

        $migration = require database_path('migrations/2026_06_25_120000_add_is_head_teacher_to_user_group_user.php');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('user_group_user', 'is_head_teacher'), 'up() ajoute la colonne');
        $this->assertFalse(
            (bool) DB::table('user_group_user')->where('user_group_id', $g)->where('user_id', $zoe)->value('is_head_teacher'),
            'arête préexistante rétro-remplie à false'
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('user_group_user', 'is_head_teacher'), 'down() retire la colonne');
    }

    // ==================================================================
    // Story 42.1 — miroir `role` ⇔ `is_head_teacher` (garde hasColumn)
    // ==================================================================

    #[Test]
    public function it_mirrors_role_owner_on_head_teachers_after_merge(): void
    {
        // 42.1 AC6 — fusion Classe_/Equipe_/PP_ : le PP (bob) hérite `owner`,
        // les autres membres `manager` (prof) / `member` (élève), miroir du flag.
        $alice = $this->mkUserWithRole('alice', 'eleve');
        $bob = $this->mkUserWithRole('bob', 'prof');
        $carol = $this->mkUserWithRole('carol', 'prof');

        $classe = $this->mkGroup('Classe_3A', 'classe', 'g-cl', 'CN=Classe_3A');
        $equipe = $this->mkGroup('Equipe_3A', 'equipe', 'g-eq', 'CN=Equipe_3A');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g-pp', 'CN=PP_3A');

        $this->link($classe, $alice);
        $this->link($equipe, $bob);
        $this->link($equipe, $carol);
        $this->link($pp, $bob);

        (new MergeLegacyUserGroups())();

        $survivor = (int) DB::table('user_groups')->where('name', '3A')->value('id');

        // Invariant miroir : owner ⇔ is_head_teacher.
        $this->assertSame('owner', $this->role($survivor, $bob));
        $this->assertTrue($this->flag($survivor, $bob));
        $this->assertSame('manager', $this->role($survivor, $carol), 'prof non-PP → manager');
        $this->assertFalse($this->flag($survivor, $carol));
        $this->assertSame('member', $this->role($survivor, $alice), 'élève → member');
        $this->assertFalse($this->flag($survivor, $alice));
    }

    #[Test]
    public function it_mirrors_role_owner_on_lonely_pp_row(): void
    {
        // 42.1 AC6 — un PP_ isolé renommé : ses membres passent owner (miroir).
        $bob = $this->mkUserWithRole('bob', 'prof');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g-pp', 'CN=PP_3A');
        $this->link($pp, $bob);

        (new MergeLegacyUserGroups())();

        $survivor = (int) DB::table('user_groups')->where('name', '3A')->value('id');
        $this->assertSame('owner', $this->role($survivor, $bob));
        $this->assertTrue($this->flag($survivor, $bob));
    }

    #[Test]
    public function it_leaves_role_untouched_when_column_absent(): void
    {
        // 42.1 AC6/T7.4 — sur un schéma SANS colonne `role` (base pré-42.1),
        // la garde Schema::hasColumn court-circuite toute écriture de `role` :
        // comportement 4.14 strictement intact, aucune exception.
        Schema::table('user_group_user', static function (Blueprint $table): void {
            $table->dropColumn('role');
        });
        $this->assertFalse(Schema::hasColumn('user_group_user', 'role'));

        $bob = $this->mkUserWithRole('bob', 'prof');
        $classe = $this->mkGroup('Classe_3A', 'classe', 'g-cl', 'CN=Classe_3A');
        $pp = $this->mkGroup('PP_3A', 'equipe', 'g-pp', 'CN=PP_3A');
        $this->link($classe, $bob);
        $this->link($pp, $bob);

        // Ne doit PAS lever malgré l'absence de colonne `role`.
        (new MergeLegacyUserGroups())();

        $survivor = (int) DB::table('user_groups')->where('name', '3A')->value('id');
        // Comportement 4.14 préservé : le flag est bien posé.
        $this->assertTrue($this->flag($survivor, $bob));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function flag(int $groupId, int $userId): bool
    {
        return (bool) DB::table('user_group_user')
            ->where('user_group_id', $groupId)
            ->where('user_id', $userId)
            ->value('is_head_teacher');
    }

    private function mkUser(string $login): int
    {
        return (int) DB::table('users')->insertGetId([
            'login' => $login,
            'role' => 'autre',
            'is_active' => true,
        ]);
    }

    private function mkGroup(string $name, string $type, ?string $guid, ?string $dn): int
    {
        return (int) DB::table('user_groups')->insertGetId([
            'name' => $name,
            'display_name' => $name,
            'type' => $type,
            'ad_guid' => $guid,
            'ad_dn' => $dn,
        ]);
    }

    private function link(int $groupId, int $userId): void
    {
        DB::table('user_group_user')->insert([
            'user_group_id' => $groupId,
            'user_id' => $userId,
            'is_head_teacher' => false,
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
                $table->text('ad_dn')->nullable();
                $table->string('ad_guid')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('user_group_user')) {
            Schema::create('user_group_user', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_group_id');
                $table->unsignedBigInteger('user_id');
                $table->boolean('is_head_teacher')->default(false);
                // Story 42.1 — colonne d'arête `role` (parité migration).
                $table->string('role', 20)->default('member');
                $table->primary(['user_group_id', 'user_id']);
            });
            $this->createdTables = true;
        }
    }

    private function role(int $groupId, int $userId): ?string
    {
        $value = DB::table('user_group_user')
            ->where('user_group_id', $groupId)
            ->where('user_id', $userId)
            ->value('role');

        return $value === null ? null : (string) $value;
    }

    private function mkUserWithRole(string $login, string $globalRole): int
    {
        return (int) DB::table('users')->insertGetId([
            'login' => $login,
            'role' => $globalRole,
            'is_active' => true,
        ]);
    }
}
