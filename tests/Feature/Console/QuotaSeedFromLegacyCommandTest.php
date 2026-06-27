<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\QuotaAuditLog;
use App\Models\QuotaRule;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 5.1d — Tests Feature de la commande `quota:seed-from-legacy`.
 *
 * Stratégie : la connexion `legacy_mysql` est reconfigurée au runtime pour
 * pointer sur une 2e BDD sqlite in-memory. Le schéma `quotas` legacy est créé
 * manuellement, des fixtures sont insérées, puis la commande tourne en mode
 * test (mêmes API publiques qu'en prod).
 *
 * Couvre AC 11-15 + idempotence/force :
 *  - it_imports_user_rules_from_legacy_table (AC 11)
 *  - it_discriminates_user_vs_group_via_eloquent_lookup (AC 12)
 *  - it_supports_dry_run_without_modifying_db (AC 13)
 *  - it_returns_failure_when_legacy_connection_missing (AC 14)
 *  - it_initializes_default_profiles_when_absent (AC 15)
 *  - it_skips_existing_rules_without_force_flag (idempotence)
 *  - it_overwrites_existing_rules_with_force_flag (force)
 */
class QuotaSeedFromLegacyCommandTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTables = false;

    private bool $createdLegacyConnection = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Neutraliser les observers AD (host sans LDAP).
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        $this->ensureMainTables();
        $this->setupLegacyConnection();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        if ($this->createdLegacyConnection) {
            try {
                Schema::connection('legacy_mysql')->dropIfExists('quotas');
            } catch (\Throwable $e) {
                // ignore
            }
            DB::purge('legacy_mysql');
        }
        if ($this->createdTables) {
            Schema::dropIfExists('user_groups');
            Schema::dropIfExists('quota_rules');
            Schema::dropIfExists('quota_audit_logs');
        }
        parent::tearDown();
    }

    private function ensureMainTables(): void
    {
        if (!Schema::hasTable('user_groups')) {
            Schema::create('user_groups', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('display_name')->nullable();
                $table->string('type')->default('role');
                $table->timestamps();
            });
            $this->createdTables = true;
        }

        if (!Schema::hasTable('quota_rules')) {
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

        if (!Schema::hasTable('quota_audit_logs')) {
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
    }

    private function setupLegacyConnection(): void
    {
        // Reconfiguration runtime : la connexion `legacy_mysql` pointe sur une
        // 2e BDD sqlite in-memory pour les tests. Pattern testable et léger.
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::purge('legacy_mysql');

        Schema::connection('legacy_mysql')->create('quotas', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->integer('quotasoft')->default(0);
            $table->integer('quotahard')->default(0);
            $table->string('partition');
        });
        $this->createdLegacyConnection = true;
    }

    private function insertLegacyRow(string $nom, int $softKb, int $hardKb, string $partition): void
    {
        DB::connection('legacy_mysql')->table('quotas')->insert([
            'nom' => $nom,
            'quotasoft' => $softKb,
            'quotahard' => $hardKb,
            'partition' => $partition,
        ]);
    }

    // =========================================================================
    // AC 11 — Import user rules
    // =========================================================================

    #[Test]
    public function it_imports_user_rules_from_legacy_table(): void
    {
        $this->insertLegacyRow('alice', 500_000, 600_000, '/home');

        $exit = $this->artisan('quota:seed-from-legacy')->run();
        $this->assertSame(0, $exit);

        $rule = QuotaRule::query()->where('type', QuotaRule::TYPE_USER)
            ->where('target', 'alice')
            ->where('partition', '/home')
            ->first();

        $this->assertNotNull($rule);
        $this->assertSame(488, $rule->quota_soft_mb); // round(500000/1024)
        $this->assertSame(586, $rule->quota_hard_mb); // round(600000/1024)
        $this->assertTrue($rule->is_active);

        // Audit log présent
        $this->assertSame(
            1,
            QuotaAuditLog::query()
                ->where('performed_by', 'quota:seed-from-legacy')
                ->where('target_name', 'alice')
                ->count()
        );
    }

    // =========================================================================
    // AC 12 — Discrimination user vs group
    // =========================================================================

    #[Test]
    public function it_discriminates_user_vs_group_via_eloquent_lookup(): void
    {
        // 'sixiemeA' est créé en tant que UserGroup → discriminé comme TYPE_GROUP.
        UserGroup::query()->create([
            'name' => 'sixiemeA',
            'display_name' => 'Sixième A',
            'type' => 'role',
        ]);

        $this->insertLegacyRow('sixiemeA', 2_000_000, 2_400_000, '/home');
        $this->insertLegacyRow('alice', 500_000, 600_000, '/home');

        $exit = $this->artisan('quota:seed-from-legacy')->run();
        $this->assertSame(0, $exit);

        $groupRule = QuotaRule::query()->where('target', 'sixiemeA')->first();
        $this->assertNotNull($groupRule);
        $this->assertSame(QuotaRule::TYPE_GROUP, $groupRule->type);

        $userRule = QuotaRule::query()->where('target', 'alice')->first();
        $this->assertNotNull($userRule);
        $this->assertSame(QuotaRule::TYPE_USER, $userRule->type);
    }

    // =========================================================================
    // AC 13 — Dry-run
    // =========================================================================

    #[Test]
    public function it_supports_dry_run_without_modifying_db(): void
    {
        $this->insertLegacyRow('alice', 500_000, 600_000, '/home');

        $exit = $this->artisan('quota:seed-from-legacy --dry-run')->run();
        $this->assertSame(0, $exit);

        $this->assertSame(0, QuotaRule::query()->count(), 'Aucune règle créée en dry-run.');
        $this->assertSame(0, QuotaAuditLog::query()->count(), 'Aucun audit en dry-run.');
    }

    // =========================================================================
    // AC 14 — Connexion absente
    // =========================================================================

    #[Test]
    public function it_returns_failure_when_legacy_connection_missing(): void
    {
        // Force la connexion à être en config "mysql" mais sans database/username
        // → la commande doit considérer la connexion comme non configurée et
        // retourner FAILURE.
        Config::set('database.connections.legacy_mysql', [
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => '',
            'username' => '',
            'password' => '',
            'unix_socket' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
            'engine' => null,
        ]);
        DB::purge('legacy_mysql');

        $exit = $this->artisan('quota:seed-from-legacy')->run();
        $this->assertSame(1, $exit, 'FAILURE quand legacy non configurée (AC 14).');
        $this->assertSame(0, QuotaRule::query()->count());
    }

    // =========================================================================
    // AC 15 — Init defaults profils
    // =========================================================================

    #[Test]
    public function it_initializes_default_profiles_when_absent(): void
    {
        // Aucune row legacy → mais on attend les 8 defaults (4 profils × 2 partitions).
        $exit = $this->artisan('quota:seed-from-legacy')->run();
        $this->assertSame(0, $exit);

        $defaults = QuotaRule::query()
            ->whereIn('type', [
                QuotaRule::TYPE_DEFAULT_ELEVE,
                QuotaRule::TYPE_DEFAULT_PROF,
                QuotaRule::TYPE_DEFAULT_ADMIN,
                QuotaRule::TYPE_DEFAULT_ITINERANT,
            ])
            ->get();

        $this->assertSame(8, $defaults->count(), 'Exactement 8 defaults (4 profils × 2 partitions).');

        // Vérification valeurs spécifiques (D4=A) :
        $itinerantHome = $defaults->where('type', QuotaRule::TYPE_DEFAULT_ITINERANT)
            ->where('partition', '/home')
            ->first();
        $this->assertNotNull($itinerantHome);
        $this->assertSame(200, $itinerantHome->quota_soft_mb);
        $this->assertSame(240, $itinerantHome->quota_hard_mb);

        $eleveHome = $defaults->where('type', QuotaRule::TYPE_DEFAULT_ELEVE)
            ->where('partition', '/home')
            ->first();
        $this->assertSame(500, $eleveHome->quota_soft_mb);
        $this->assertSame(600, $eleveHome->quota_hard_mb);
    }

    // =========================================================================
    // Idempotence — skip sans --force
    // =========================================================================

    #[Test]
    public function it_skips_existing_rules_without_force_flag(): void
    {
        $this->insertLegacyRow('alice', 500_000, 600_000, '/home');

        // 1ère exécution : crée la règle.
        $this->artisan('quota:seed-from-legacy')->run();
        $this->assertSame(1, QuotaRule::query()->where('target', 'alice')->count());

        $aliceRule = QuotaRule::query()->where('target', 'alice')->first();
        $originalSoft = $aliceRule->quota_soft_mb;

        // Mise à jour des valeurs côté legacy entre les deux runs.
        DB::connection('legacy_mysql')->table('quotas')
            ->where('nom', 'alice')
            ->update(['quotasoft' => 999_999, 'quotahard' => 1_200_000]);

        // 2ème exécution sans --force : skipped (pas de mise à jour).
        $this->artisan('quota:seed-from-legacy')->run();

        $aliceRule->refresh();
        $this->assertSame($originalSoft, $aliceRule->quota_soft_mb, 'La règle existante ne doit pas être écrasée sans --force.');
    }

    // =========================================================================
    // --force overwrite
    // =========================================================================

    #[Test]
    public function it_overwrites_existing_rules_with_force_flag(): void
    {
        $this->insertLegacyRow('alice', 500_000, 600_000, '/home');

        $this->artisan('quota:seed-from-legacy')->run();
        $aliceRule = QuotaRule::query()->where('target', 'alice')->first();
        $this->assertSame(488, $aliceRule->quota_soft_mb);

        // Mise à jour legacy + relance avec --force.
        DB::connection('legacy_mysql')->table('quotas')
            ->where('nom', 'alice')
            ->update(['quotasoft' => 1_024_000, 'quotahard' => 1_228_800]);

        $this->artisan('quota:seed-from-legacy --force')->run();

        $aliceRule->refresh();
        $this->assertSame(1000, $aliceRule->quota_soft_mb, 'Avec --force, la règle est mise à jour.');
        $this->assertSame(1200, $aliceRule->quota_hard_mb);
    }

    #[Test]
    public function it_counts_malformed_rows_as_errors(): void
    {
        // Row avec partition invalide → doit être comptée comme erreur, pas créée.
        $this->insertLegacyRow('alice', 500_000, 600_000, '/etc/wrong');
        $this->insertLegacyRow('bob', 100_000, 120_000, '/home');

        $exit = $this->artisan('quota:seed-from-legacy')->run();
        $this->assertSame(0, $exit);

        $this->assertNull(QuotaRule::query()->where('target', 'alice')->first());
        $this->assertNotNull(QuotaRule::query()->where('target', 'bob')->first());
    }
}
