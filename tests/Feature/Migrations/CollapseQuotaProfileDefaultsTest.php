<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\QuotaRule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 63.4 — **LA BASCULE DES DÉFAUTS PAR PROFIL, ET CE QU'ELLE REFUSE DE FAIRE.**
 *
 * ---------------------------------------------------------------------------
 * Les propriétés épinglées ici correspondent chacune à une façon dont cette migration
 * pouvait mal tourner :
 *
 *  1. **ELLE NE RÉTRÉCIT JAMAIS UN PLAFOND** — la valeur retenue est LA PLUS LARGE
 *     des règles par profil. Un plafond qui rétrécit bloque des gens en écriture, sur
 *     le système de fichiers ET sur le cloud, sans que personne n'ait cliqué ; un
 *     plafond qui s'élargit ne bloque personne et ne consomme aucun disque — un quota
 *     plafonne, il n'alloue pas ;
 *  2. **une règle DÉSACTIVÉE ne ressuscite pas** — elle n'avait aucun effet, et en
 *     faire un défaut ACTIF poserait un plafond sur des comptes illimités ;
 *  3. **la préséance** — la règle qui s'appliquait vraiment prime sur le réglage qui
 *     ne s'appliquait à personne ;
 *  4. **ce qui est perdu est TRACÉ** — chaque défaut abandonné laisse une ligne
 *     d'audit avec ses valeurs, sans quoi personne ne pourrait reconstituer un
 *     budget particulier ;
 *  5. **AUCUNE APPLICATION EN MASSE** — la migration ne met RIEN en file. Mais **le
 *     plan cloud, lui, n'attend aucun clic** : prendre la gouvernance des plafonds
 *     cloud est un effet réel, et il doit être NOMMÉ ;
 *  6. **`down()` est un no-op assumé** — il est épinglé comme tel, parce que `up()`
 *     supprime des lignes réelles et que le seul filet est le journal d'audit.
 * ---------------------------------------------------------------------------
 */
class CollapseQuotaProfileDefaultsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * ⚠️ **LE SEUL ENDROIT DE `tests/` QUI NOMME ENCORE LES QUATRE TYPES MORTS**, et
     * il est irréductible : cette suite doit CONSTRUIRE l'état d'avant pour prouver
     * que la bascule l'emporte correctement. Un test de migration qui ne saurait pas
     * écrire l'état de départ ne testerait rien.
     *
     * Ils sont donc regroupés ici, une fois, plutôt qu'égrenés dans les scénarios.
     * Le code de production, lui, ne les connaît plus (seule la migration les cite,
     * comme trace datée).
     *
     * @var array{socle:string, abandonnes:list<string>}
     */
    private const LEGACY_TYPES = [
        'socle' => 'default_eleve',
        'abandonnes' => ['default_prof', 'default_admin', 'default_itinerant'],
    ];

    private object $migration;

    private bool $createdTables = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        $this->migration = require base_path(
            'database/migrations/2026_08_15_100000_collapse_quota_profile_defaults.php',
        );
    }

    protected function tearDown(): void
    {
        if ($this->createdTables) {
            Schema::dropIfExists('quota_audit_logs');
            Schema::dropIfExists('quota_rules');
            Schema::dropIfExists('system_settings');
        }
        parent::tearDown();
    }

    private function createTables(): void
    {
        if (! Schema::hasTable('quota_rules')) {
            Schema::create('quota_rules', function (Blueprint $table) {
                $table->id();
                $table->string('type', 30);
                $table->string('target')->nullable();
                $table->string('partition');
                $table->integer('quota_soft_mb')->default(0);
                $table->integer('quota_hard_mb')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('quota_audit_logs')) {
            Schema::create('quota_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->string('action');
                $table->string('performed_by');
                $table->string('target_type')->nullable();
                $table->string('target_name')->nullable();
                $table->string('partition')->nullable();
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->boolean('fs_applied')->default(false);
                $table->text('fs_error')->nullable();
                $table->unsignedBigInteger('quota_rule_id')->nullable();
                $table->timestamp('created_at')->nullable();
            });
            $this->createdTables = true;
        }

        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key', 191)->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
            $this->createdTables = true;
        }
    }

    private function legacyRule(
        string $type,
        string $partition,
        int $soft,
        int $hard,
        bool $active = true,
    ): void {
        DB::table('quota_rules')->insert([
            'type' => $type,
            'target' => null,
            'partition' => $partition,
            'quota_soft_mb' => $soft,
            'quota_hard_mb' => $hard,
            'is_active' => $active,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Les huit lignes que l'import legacy crée. */
    private function seedTheEightLegacyRules(): void
    {
        foreach ([QuotaRule::PARTITION_HOME, QuotaRule::PARTITION_SAMBAEDU] as $partition) {
            $this->legacyRule(self::LEGACY_TYPES['socle'], $partition, 500, 600);
            $this->legacyRule(self::LEGACY_TYPES['abandonnes'][0], $partition, 1000, 1200);
            $this->legacyRule(self::LEGACY_TYPES['abandonnes'][1], $partition, 2000, 2400);
            $this->legacyRule(self::LEGACY_TYPES['abandonnes'][2], $partition, 200, 240);
        }
    }

    /** @param array<string, mixed> $grid */
    private function seedTheLegacyGrid(array $grid): void
    {
        DB::table('system_settings')->insert([
            'key' => 'quota.defaults',
            'value' => json_encode($grid),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function defaultRule(string $partition): ?object
    {
        return DB::table('quota_rules')
            ->where('type', QuotaRule::TYPE_DEFAULT)
            ->where('partition', $partition)
            ->first();
    }

    // =========================================================================
    // Cas 1 — la plus large, jamais la plus étroite
    // =========================================================================

    /**
     * ⚠️ **PERSONNE NE PERD DE PLACE.** La valeur retenue est la PLUS LARGE des
     * quatre : ici l'ex-valeur administrative (2000/2400), pas l'ex-valeur élève.
     *
     * Un plafond qui rétrécit bloque des gens en écriture — sur le système de
     * fichiers ET sur le cloud — sans que personne n'ait cliqué ; un plafond qui
     * s'élargit ne bloque personne et n'ALLOUE rien : un quota plafonne. Entre les
     * deux erreurs possibles, une seule se répare sans avoir mis des utilisateurs à
     * l'arrêt. L'administrateur resserre ensuite explicitement, en voyant qui
     * bascule.
     */
    #[Test]
    public function the_widest_rule_becomes_the_instance_default_and_the_others_are_dropped(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();

        $this->migration->up();

        foreach ([QuotaRule::PARTITION_HOME, QuotaRule::PARTITION_SAMBAEDU] as $partition) {
            $rule = $this->defaultRule($partition);

            $this->assertNotNull($rule, 'chaque partition porte son défaut : '.$partition);
            $this->assertSame(2000, (int) $rule->quota_soft_mb, 'la plus large, jamais la plus étroite');
            $this->assertSame(2400, (int) $rule->quota_hard_mb);
            $this->assertNull($rule->target);
        }

        // Plus une seule ligne de l'ancien vocabulaire.
        $this->assertSame(
            0,
            DB::table('quota_rules')
                ->whereIn('type', array_merge([self::LEGACY_TYPES['socle']], self::LEGACY_TYPES['abandonnes']))
                ->count(),
        );
        $this->assertSame(2, DB::table('quota_rules')->count());
    }

    /** Chaque règle abandonnée laisse une ligne d'audit AVEC SES VALEURS. */
    #[Test]
    public function every_dropped_rule_leaves_an_audit_line_carrying_its_lost_values(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();

        $this->migration->up();

        $audit = DB::table('quota_audit_logs')->where('performed_by', 'migration:63.4')->get();

        // 8 suppressions tracées + 2 créations de défaut.
        $this->assertSame(10, $audit->count());

        $profDropped = $audit->first(fn ($row) => $row->target_type === self::LEGACY_TYPES['abandonnes'][0]
            && $row->partition === QuotaRule::PARTITION_HOME);

        $this->assertNotNull($profDropped, 'le défaut enseignant abandonné doit être tracé');

        $old = json_decode((string) $profDropped->old_values, true);
        $this->assertSame(1000, $old['quota_soft_mb']);
        $this->assertSame(1200, $old['quota_hard_mb']);

        $new = json_decode((string) $profDropped->new_values, true);
        $this->assertStringContainsString('règle de groupe', $new['reconstitution']);
    }

    // =========================================================================
    // Cas 2 — la seule intention jamais exprimée
    // =========================================================================

    /**
     * **La grille saisie mais jamais appliquée est REPRISE**, et le cas est NOMMÉ.
     * La jeter laisserait l'instance « illimitée pour tout le monde » — l'état que
     * cette story existe pour fermer. Là aussi, c'est la cellule LA PLUS LARGE qui
     * est reprise : la règle « ne rétrécir aucun plafond » ne dépend pas de l'endroit
     * d'où vient la valeur.
     */
    #[Test]
    public function a_grid_that_was_never_applied_to_anyone_is_taken_over_and_named(): void
    {
        Bus::fake();
        $this->seedTheLegacyGrid([
            'eleve' => [
                'home' => ['soft_mb' => 250, 'overage_percent' => 20],
                'sambaedu' => ['soft_mb' => 800, 'overage_percent' => 10],
            ],
            'prof' => [
                'home' => ['soft_mb' => 5000, 'overage_percent' => 20],
                'sambaedu' => ['soft_mb' => 5000, 'overage_percent' => 20],
            ],
        ]);

        $this->migration->up();

        $home = $this->defaultRule(QuotaRule::PARTITION_HOME);
        $this->assertSame(5000, (int) $home->quota_soft_mb, 'la cellule la plus large de la grille');
        $this->assertSame(6000, (int) $home->quota_hard_mb, 'le plafond dur est recalculé par le dépassement');

        $sambaedu = $this->defaultRule(QuotaRule::PARTITION_SAMBAEDU);
        $this->assertSame(5000, (int) $sambaedu->quota_soft_mb);
        $this->assertSame(6000, (int) $sambaedu->quota_hard_mb);

        // LE CAS EST NOMMÉ — c'est un changement de comportement, il doit se voir.
        $audit = DB::table('quota_audit_logs')
            ->where('target_type', QuotaRule::TYPE_DEFAULT)
            ->where('partition', QuotaRule::PARTITION_HOME)
            ->first();

        $new = json_decode((string) $audit->new_values, true);
        $this->assertSame(
            'valeur reprise d\'un réglage qui n\'était appliqué à personne',
            $new['origine'],
        );
    }

    /** **La préséance** : la règle qui s'appliquait vraiment bat la grille. */
    #[Test]
    public function an_existing_rule_takes_precedence_over_the_never_applied_grid(): void
    {
        Bus::fake();
        $this->legacyRule(self::LEGACY_TYPES['socle'], QuotaRule::PARTITION_HOME, 500, 600);
        $this->seedTheLegacyGrid([
            'eleve' => ['home' => ['soft_mb' => 250, 'overage_percent' => 20]],
        ]);

        $this->migration->up();

        $this->assertSame(500, (int) $this->defaultRule(QuotaRule::PARTITION_HOME)->quota_soft_mb);
    }

    /**
     * ⚠️ **UNE RÈGLE DÉSACTIVÉE NE RESSUSCITE PAS.** Elle n'avait AUCUN effet ; en
     * faire un défaut d'instance ACTIF poserait un plafond sur des comptes jusque-là
     * illimités — l'exact contraire de la règle de cette migration.
     */
    #[Test]
    public function a_deactivated_rule_is_never_taken_over_as_the_instance_default(): void
    {
        Bus::fake();

        // La plus large est DÉSACTIVÉE : elle ne compte pas.
        $this->legacyRule(self::LEGACY_TYPES['socle'], QuotaRule::PARTITION_HOME, 500, 600);
        $this->legacyRule(self::LEGACY_TYPES['abandonnes'][1], QuotaRule::PARTITION_HOME, 9000, 10000, false);

        $this->migration->up();

        $rule = $this->defaultRule(QuotaRule::PARTITION_HOME);

        $this->assertSame(500, (int) $rule->quota_soft_mb, 'une règle sans effet ne devient pas un plafond');
        $this->assertSame(600, (int) $rule->quota_hard_mb);

        // Elle est tout de même RETIRÉE et TRACÉE : le vocabulaire mort ne survit pas.
        $this->assertSame(
            0,
            DB::table('quota_rules')
                ->whereIn('type', array_merge([self::LEGACY_TYPES['socle']], self::LEGACY_TYPES['abandonnes']))
                ->count(),
        );
    }

    /** Toutes les règles désactivées ⇒ aucune valeur à retenir, et rien n'est créé. */
    #[Test]
    public function only_deactivated_rules_produce_no_instance_default(): void
    {
        Bus::fake();

        $this->legacyRule(self::LEGACY_TYPES['socle'], QuotaRule::PARTITION_HOME, 500, 600, false);

        $this->migration->up();

        $this->assertNull($this->defaultRule(QuotaRule::PARTITION_HOME));
    }

    /** Le plafond ILLIMITÉ (`0`) est le plus large de tous, et c'est lui qui gagne. */
    #[Test]
    public function an_unlimited_profile_rule_is_the_widest_of_all(): void
    {
        Bus::fake();

        $this->legacyRule(self::LEGACY_TYPES['socle'], QuotaRule::PARTITION_HOME, 500, 600);
        $this->legacyRule(self::LEGACY_TYPES['abandonnes'][1], QuotaRule::PARTITION_HOME, 0, 0);

        $this->migration->up();

        $rule = $this->defaultRule(QuotaRule::PARTITION_HOME);

        $this->assertSame(0, (int) $rule->quota_hard_mb, 'illimité est le plus large');
    }

    // =========================================================================
    // Cas 3 — rien à retenir
    // =========================================================================

    #[Test]
    public function an_empty_instance_gets_no_default_at_all(): void
    {
        Bus::fake();

        $this->migration->up();

        $this->assertSame(0, DB::table('quota_rules')->count());
        $this->assertSame(0, DB::table('quota_audit_logs')->count());
    }

    /** Une grille à zéro n'est pas une intention : elle ne fabrique pas de plafond. */
    #[Test]
    public function a_grid_left_at_zero_produces_no_ceiling(): void
    {
        Bus::fake();
        $this->seedTheLegacyGrid([
            'eleve' => [
                'home' => ['soft_mb' => 0, 'overage_percent' => 20],
                'sambaedu' => ['soft_mb' => 0, 'overage_percent' => 20],
            ],
        ]);

        $this->migration->up();

        $this->assertSame(0, DB::table('quota_rules')->count());
    }

    // =========================================================================
    // Les garanties transverses
    // =========================================================================

    /**
     * **AUCUNE APPLICATION EN MASSE.** Une migration qui appliquerait un plafond qui
     * n'avait jamais rien appliqué mettrait d'un coup des comptes en dépassement,
     * sans que personne n'ait cliqué.
     */
    #[Test]
    public function the_migration_dispatches_absolutely_nothing(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();
        $this->seedTheLegacyGrid(['eleve' => ['home' => ['soft_mb' => 250, 'overage_percent' => 20]]]);

        $this->migration->up();

        Bus::assertNothingDispatched();
    }

    #[Test]
    public function it_is_idempotent_and_never_overwrites_a_default_set_since(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();

        $this->migration->up();

        // L'administrateur change le plafond après la migration.
        DB::table('quota_rules')
            ->where('type', QuotaRule::TYPE_DEFAULT)
            ->where('partition', QuotaRule::PARTITION_HOME)
            ->update(['quota_soft_mb' => 42, 'quota_hard_mb' => 50]);

        $auditBefore = DB::table('quota_audit_logs')->count();

        $this->migration->up();

        $this->assertSame(2, DB::table('quota_rules')->count(), 'rejouée, elle ne duplique rien');
        $this->assertSame(42, (int) $this->defaultRule(QuotaRule::PARTITION_HOME)->quota_soft_mb);
        $this->assertSame($auditBefore, DB::table('quota_audit_logs')->count(), 'rien de neuf à tracer');
    }

    /** Elle ne touche à AUCUNE règle par utilisateur ni par groupe. */
    #[Test]
    public function it_never_touches_a_user_or_group_rule(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();

        DB::table('quota_rules')->insert([
            [
                'type' => QuotaRule::TYPE_USER,
                'target' => 'alice',
                'partition' => QuotaRule::PARTITION_HOME,
                'quota_soft_mb' => 900,
                'quota_hard_mb' => 1000,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'type' => QuotaRule::TYPE_GROUP,
                'target' => 'documentalistes',
                'partition' => QuotaRule::PARTITION_HOME,
                'quota_soft_mb' => 8000,
                'quota_hard_mb' => 9000,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->migration->up();

        $this->assertDatabaseHas('quota_rules', ['target' => 'alice', 'quota_soft_mb' => 900]);
        $this->assertDatabaseHas('quota_rules', ['target' => 'documentalistes', 'quota_soft_mb' => 8000]);
    }

    /** La clé de réglage n'est PAS effacée : c'est la trace de ce qui a été repris. */
    #[Test]
    public function the_legacy_setting_row_survives_as_inert_data(): void
    {
        Bus::fake();
        $this->seedTheLegacyGrid(['eleve' => ['home' => ['soft_mb' => 250, 'overage_percent' => 20]]]);

        $this->migration->up();

        $this->assertDatabaseHas('system_settings', ['key' => 'quota.defaults']);
    }

    // =========================================================================
    // Le PLAN CLOUD — il n'attend aucun clic, et il doit être NOMMÉ
    // =========================================================================

    /**
     * ⚠️ **PRENDRE LA GOUVERNANCE DES PLAFONDS CLOUD EST UN EFFET RÉEL.** SE5 ne
     * gouverne le plafond cloud d'un compte que s'il existe au moins une règle sur la
     * partition des répertoires personnels. Passer de « aucune règle » à « une
     * règle » — ce que fait le cas 2 — fait basculer cette gouvernance : le prochain
     * balayage de provisionnement RÉÉCRIRA le plafond de chaque compte, y compris
     * ceux réglés à la main dans l'instance. La ligne d'audit du défaut créé le porte,
     * parce que le résumé au journal, lui, se perd.
     */
    #[Test]
    public function taking_over_the_cloud_ceilings_is_carried_by_the_audit_line(): void
    {
        Bus::fake();
        $this->seedTheLegacyGrid(['eleve' => ['home' => ['soft_mb' => 250, 'overage_percent' => 20]]]);

        $this->migration->up();

        $audit = DB::table('quota_audit_logs')
            ->where('target_type', QuotaRule::TYPE_DEFAULT)
            ->where('partition', QuotaRule::PARTITION_HOME)
            ->first();

        $new = json_decode((string) $audit->new_values, true);

        $this->assertArrayHasKey('plan_cloud', $new);
        $this->assertStringContainsString('réécrira le plafond', $new['plan_cloud']);
    }

    /**
     * …et sur une instance qui portait DÉJÀ des règles, la gouvernance ne bascule
     * pas : elle était déjà prise. Annoncer un basculement qui n'a pas lieu serait
     * du bruit, et du bruit répété finit par ne plus être lu.
     */
    #[Test]
    public function an_instance_that_already_had_rules_is_not_told_about_a_takeover(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();

        $this->migration->up();

        $audit = DB::table('quota_audit_logs')
            ->where('target_type', QuotaRule::TYPE_DEFAULT)
            ->where('partition', QuotaRule::PARTITION_HOME)
            ->first();

        $this->assertArrayNotHasKey('plan_cloud', json_decode((string) $audit->new_values, true));
    }

    // =========================================================================
    // Le REGROUPEMENT, déposé pour l'écran
    // =========================================================================

    /**
     * **ÉLARGIR N'EST PAS ANODIN NON PLUS.** Le regroupement est déposé, avec ses
     * valeurs LITTÉRALES, pour que la carte l'affiche tant que l'administrateur n'a
     * pas enregistré une valeur lui-même.
     */
    #[Test]
    public function the_collapse_is_published_with_its_literal_values(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();

        $this->migration->up();

        $raw = DB::table('system_settings')->where('key', 'quota.profils_regroupes')->value('value');
        $notice = json_decode((string) $raw, true);

        $this->assertIsArray($notice);
        $this->assertContains('500/600 Mo', $notice['fondus']);
        $this->assertContains('2000/2400 Mo', $notice['fondus']);
        $this->assertContains('1000/1200 Mo', $notice['fondus']);
        $this->assertContains('200/240 Mo', $notice['fondus']);
    }

    /** Rien à regrouper ⇒ aucun avertissement : un bandeau sans objet est du bruit. */
    #[Test]
    public function nothing_is_published_when_there_was_nothing_to_collapse(): void
    {
        Bus::fake();

        $this->migration->up();

        $this->assertDatabaseMissing('system_settings', ['key' => 'quota.profils_regroupes']);
    }

    // =========================================================================
    // `down()` — un no-op ASSUMÉ, et épinglé comme tel
    // =========================================================================

    /**
     * ⚠️ **`up()` SUPPRIME des règles réelles, et `down()` n'en rend AUCUNE.** C'est
     * un choix, pas un oubli : recréer les défauts par profil supposerait de rejouer
     * une classification que le produit ne porte plus.
     *
     * Ce test épingle les deux moitiés du choix : redescendre ne LÈVE pas, et ne
     * MODIFIE rien. Le filet de sécurité n'est pas ici — il est dans le journal
     * d'audit (`performed_by = 'migration:63.4'`, `old_values`), et le chemin de
     * restauration manuelle est écrit dans le runbook QA.
     */
    #[Test]
    public function down_neither_throws_nor_changes_anything(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();

        $this->migration->up();

        $rulesBefore = DB::table('quota_rules')->get()->toJson();
        $auditBefore = DB::table('quota_audit_logs')->count();

        $this->migration->down();

        $this->assertSame($rulesBefore, DB::table('quota_rules')->get()->toJson());
        $this->assertSame($auditBefore, DB::table('quota_audit_logs')->count());
    }

    /**
     * **LE FILET EST LE JOURNAL D'AUDIT.** Chaque règle supprimée y a laissé de quoi
     * la reconstruire : son type, sa partition et ses deux plafonds. Sans cette
     * garantie, `down()` vide serait une perte sèche.
     */
    #[Test]
    public function the_audit_line_carries_enough_to_rebuild_a_dropped_rule_by_hand(): void
    {
        Bus::fake();
        $this->seedTheEightLegacyRules();

        $this->migration->up();

        $rows = DB::table('quota_audit_logs')
            ->where('performed_by', 'migration:63.4')
            ->where('action', 'delete')
            ->get();

        $this->assertSame(8, $rows->count());

        foreach ($rows as $row) {
            $old = json_decode((string) $row->old_values, true);

            $this->assertArrayHasKey('type', $old);
            $this->assertArrayHasKey('quota_soft_mb', $old);
            $this->assertArrayHasKey('quota_hard_mb', $old);
            $this->assertArrayHasKey('is_active', $old);
            $this->assertNotNull($row->partition);
        }
    }
}
