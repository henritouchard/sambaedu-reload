<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\QuotaSnapshotCommand;
use App\Models\QuotaRule;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests unit de la commande `quota:snapshot` (story 5.1b — AC 8).
 *
 * Couvre :
 *   1. parsing ligne valide sans overflow
 *   2. parsing ligne over-soft (suffixe *) + grace period
 *   3. skip des lignes malformées (header, vides)
 *   4. fail-soft partition non-XFS (D3)
 *   5. batch UPDATE des users
 *   6. préservation du snapshot pour user absent du rapport (D2)
 *
 * Les tests utilisent `Process::fake()` pour simuler l'output de `xfs_quota`
 * sans dépendre du binaire sur l'hôte CI.
 */
class QuotaSnapshotCommandTest extends TestCase
{
    use DatabaseTransactions;

    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureUsersTable();
    }

    protected function tearDown(): void
    {
        if ($this->createdTable) {
            Schema::dropIfExists('users');
        }
        parent::tearDown();
    }

    private function ensureUsersTable(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('login')->unique();
            $table->string('password')->nullable();
            $table->string('fullname')->nullable();
            $table->string('firstname')->nullable();
            $table->string('lastname')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('description')->nullable();
            $table->string('dn')->nullable();
            $table->string('ad_guid')->nullable();
            $table->string('role')->default('eleve');
            $table->string('school_code')->nullable();
            $table->string('school_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('ad_right_profiles')->nullable();
            $table->integer('ad_rights_bitmask')->default(0);
            $table->timestamp('ad_synced_at')->nullable();
            $table->timestamp('pwd_reset_at')->nullable();
            $table->json('quota_snapshot')->nullable();
            $table->timestamps();
        });

        $this->createdTable = true;
    }

    #[Test]
    public function it_parses_a_valid_report_line_with_no_overflow(): void
    {
        $command = new QuotaSnapshotCommand();

        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: "alice        12345          500000         600000    00 [--------]\n",
            ),
        ]);

        $parsed = $command->parseReport(QuotaRule::PARTITION_HOME);

        $this->assertIsArray($parsed);
        $this->assertArrayHasKey('alice', $parsed);
        $this->assertSame(12345, $parsed['alice']['used_kb']);
        $this->assertSame(500000, $parsed['alice']['soft_kb']);
        $this->assertSame(600000, $parsed['alice']['hard_kb']);
        $this->assertFalse($parsed['alice']['is_over_soft']);
        $this->assertNull($parsed['alice']['grace_days']);
    }

    #[Test]
    public function it_parses_a_report_line_with_over_soft_flag(): void
    {
        $command = new QuotaSnapshotCommand();

        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: "bob          700000*        500000         600000    01 [6 days]\n",
            ),
        ]);

        $parsed = $command->parseReport(QuotaRule::PARTITION_HOME);

        $this->assertArrayHasKey('bob', $parsed);
        $this->assertSame(700000, $parsed['bob']['used_kb']);
        $this->assertTrue($parsed['bob']['is_over_soft']);
        $this->assertSame(6, $parsed['bob']['grace_days']);
    }

    #[Test]
    public function it_skips_malformed_lines(): void
    {
        $command = new QuotaSnapshotCommand();

        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: <<<TXT


User quota on /home (/dev/vda2)
                   Blocks                     Inodes
User ID       Used       Soft       Hard    Warn/Grace     Used   Soft   Hard   Warn/Grace
---------- -------------------------------- ----------------------- ------------------------
alice        12345          500000         600000    00 [--------]
malformed line without brackets at all
[orphan bracket line]

TXT,
            ),
        ]);

        $parsed = $command->parseReport(QuotaRule::PARTITION_HOME);

        // Seule la ligne alice valide doit être retenue.
        $this->assertCount(1, $parsed);
        $this->assertArrayHasKey('alice', $parsed);
    }

    #[Test]
    public function it_handles_partition_with_no_xfs(): void
    {
        $command = new QuotaSnapshotCommand();

        // Partition /home OK — snapshot remonte un user.
        // Partition /var/sambaedu → foreign filesystem → exit != 0, log error, skip.
        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: "alice        12345          500000         600000    00 [--------]\n",
            ),
            "sudo xfs_quota -x -c 'report -a -N' '/var/sambaedu' 2>&1" => Process::result(
                output: 'foreign filesystem. Invoke xfs_quota with -f to enable',
                exitCode: 1,
            ),
        ]);

        User::query()->create([
            'login' => 'alice',
            'role' => 'eleve',
            'is_active' => true,
        ]);

        $exitCode = $this->artisan('quota:snapshot')->run();

        $this->assertSame(0, $exitCode, 'Exit code SUCCESS attendu quand au moins une partition passe.');

        $alice = User::query()->where('login', 'alice')->first();
        $this->assertNotNull($alice->quota_snapshot);
        $this->assertArrayHasKey('home', $alice->quota_snapshot);
        // sambaedu doit être absent car la partition a échoué.
        $this->assertArrayNotHasKey('sambaedu', $alice->quota_snapshot);
    }

    #[Test]
    public function it_updates_users_in_batch(): void
    {
        User::query()->create(['login' => 'alice', 'role' => 'eleve', 'is_active' => true]);
        User::query()->create(['login' => 'bob', 'role' => 'eleve', 'is_active' => true]);
        User::query()->create(['login' => 'carol', 'role' => 'eleve', 'is_active' => true]);

        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: <<<TXT
alice        12345          500000         600000    00 [--------]
bob          450000         500000         600000    00 [--------]
carol        700000*        500000         600000    01 [6 days]
TXT,
            ),
            "sudo xfs_quota -x -c 'report -a -N' '/var/sambaedu' 2>&1" => Process::result(output: ''),
        ]);

        $this->artisan('quota:snapshot')->assertExitCode(0);

        foreach (['alice', 'bob', 'carol'] as $login) {
            $user = User::query()->where('login', $login)->first();
            $this->assertNotNull($user->quota_snapshot, "{$login} doit avoir un snapshot.");
            $this->assertArrayHasKey('home', $user->quota_snapshot);
        }

        $carol = User::query()->where('login', 'carol')->first();
        $this->assertTrue($carol->quota_snapshot['home']['is_over_soft']);
        $this->assertSame(6, $carol->quota_snapshot['home']['grace_days']);
    }

    #[Test]
    public function it_preserves_snapshot_for_absent_users(): void
    {
        // charlie a déjà un snapshot (run précédente).
        $existing = [
            'home' => [
                'used_kb' => 9999,
                'soft_kb' => 500000,
                'hard_kb' => 600000,
                'used_mb' => 10,
                'soft_mb' => 488,
                'hard_mb' => 586,
                'percent' => 2,
                'is_over_soft' => false,
                'is_over_hard' => false,
                'grace_days' => null,
            ],
            'captured_at' => '2026-04-22T03:00:05+02:00',
        ];

        User::query()->create([
            'login' => 'charlie',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => $existing,
        ]);
        User::query()->create(['login' => 'diana', 'role' => 'eleve', 'is_active' => true]);

        // La run ne mentionne QUE diana — charlie est absent du rapport.
        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: "diana        77777          500000         600000    00 [--------]\n",
            ),
            "sudo xfs_quota -x -c 'report -a -N' '/var/sambaedu' 2>&1" => Process::result(output: ''),
        ]);

        $this->artisan('quota:snapshot')->assertExitCode(0);

        $charlie = User::query()->where('login', 'charlie')->first();
        $this->assertSame($existing, $charlie->quota_snapshot, 'Le snapshot de charlie doit être intact.');

        $diana = User::query()->where('login', 'diana')->first();
        $this->assertNotNull($diana->quota_snapshot);
        $this->assertSame(77777, $diana->quota_snapshot['home']['used_kb']);
    }

    #[Test]
    public function it_returns_failure_when_all_partitions_fail(): void
    {
        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: 'foreign filesystem',
                exitCode: 1,
            ),
            "sudo xfs_quota -x -c 'report -a -N' '/var/sambaedu' 2>&1" => Process::result(
                output: 'foreign filesystem',
                exitCode: 1,
            ),
        ]);

        $exitCode = $this->artisan('quota:snapshot')->run();
        $this->assertSame(1, $exitCode, 'FAILURE attendu quand toutes les partitions échouent.');
    }

    #[Test]
    public function it_preserves_sambaedu_snapshot_when_report_is_empty(): void
    {
        // alice a déjà un snapshot sambaedu complet (run précédente).
        $previousSnapshot = [
            'home' => [
                'used_kb' => 12345,
                'soft_kb' => 500000,
                'hard_kb' => 600000,
                'used_mb' => 12,
                'soft_mb' => 488,
                'hard_mb' => 586,
                'percent' => 2,
                'is_over_soft' => false,
                'is_over_hard' => false,
                'grace_days' => null,
            ],
            'sambaedu' => [
                'used_kb' => 99999,
                'soft_kb' => 500000,
                'hard_kb' => 600000,
                'used_mb' => 98,
                'soft_mb' => 488,
                'hard_mb' => 586,
                'percent' => 20,
                'is_over_soft' => false,
                'is_over_hard' => false,
                'grace_days' => null,
            ],
            'captured_at' => '2026-04-22T03:00:05+02:00',
        ];

        User::query()->create([
            'login' => 'alice',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => $previousSnapshot,
        ]);

        // /home retourne alice — /var/sambaedu retourne succès mais output vide.
        // Comportement attendu : la clé home est mise à jour, la clé sambaedu
        // existante est PRÉSERVÉE intacte (pas effacée silencieusement).
        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: "alice        22222          500000         600000    00 [--------]\n",
            ),
            "sudo xfs_quota -x -c 'report -a -N' '/var/sambaedu' 2>&1" => Process::result(
                output: '',
            ),
        ]);

        $this->artisan('quota:snapshot')->assertExitCode(0);

        $alice = User::query()->where('login', 'alice')->first();

        // /home mis à jour.
        $this->assertSame(22222, $alice->quota_snapshot['home']['used_kb']);

        // /var/sambaedu conservé intact.
        $this->assertArrayHasKey('sambaedu', $alice->quota_snapshot);
        $this->assertSame(
            $previousSnapshot['sambaedu'],
            $alice->quota_snapshot['sambaedu'],
            'Le snapshot sambaedu doit être préservé quand le rapport est vide.',
        );
    }

    #[Test]
    public function it_logs_warning_for_user_absent_from_xfs_report(): void
    {
        // charlie a un snapshot précédent mais n'apparaît plus dans aucun rapport.
        $previousSnapshot = [
            'home' => [
                'used_kb' => 1000,
                'soft_kb' => 500000,
                'hard_kb' => 600000,
                'used_mb' => 1,
                'soft_mb' => 488,
                'hard_mb' => 586,
                'percent' => 1,
                'is_over_soft' => false,
                'is_over_hard' => false,
                'grace_days' => null,
            ],
            'captured_at' => '2026-04-22T03:00:05+02:00',
        ];

        User::query()->create([
            'login' => 'charlie',
            'role' => 'eleve',
            'is_active' => true,
            'quota_snapshot' => $previousSnapshot,
        ]);
        User::query()->create(['login' => 'diana', 'role' => 'eleve', 'is_active' => true]);

        Process::fake([
            "sudo xfs_quota -x -c 'report -a -N' '/home' 2>&1" => Process::result(
                output: "diana        500          500000         600000    00 [--------]\n",
            ),
            "sudo xfs_quota -x -c 'report -a -N' '/var/sambaedu' 2>&1" => Process::result(
                output: '',
            ),
        ]);

        \Illuminate\Support\Facades\Log::spy();

        $this->artisan('quota:snapshot')->assertExitCode(0);

        // Snapshot de charlie intact.
        $charlie = User::query()->where('login', 'charlie')->first();
        $this->assertSame($previousSnapshot, $charlie->quota_snapshot);

        // Log info émis pour charlie avec le contexte partitions_checked.
        \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
            ->with('QuotaService: user absent du rapport XFS', \Mockery::on(function ($ctx) {
                return ($ctx['login'] ?? null) === 'charlie'
                    && is_array($ctx['partitions_checked'] ?? null);
            }))
            ->atLeast()->once();
    }

    #[Test]
    public function it_builds_pre_calculated_mb_and_percent_fields(): void
    {
        $command = new QuotaSnapshotCommand();

        // 512000 KB = 500 MB ; 1024000 KB = 1000 MB ; 2048000 KB = 2000 MB
        $snapshot = $command->buildPartitionSnapshot([
            'used_kb' => 512000,
            'soft_kb' => 1024000,
            'hard_kb' => 2048000,
            'is_over_soft' => false,
            'grace_days' => null,
        ]);

        $this->assertSame(500, $snapshot['used_mb']);
        $this->assertSame(1000, $snapshot['soft_mb']);
        $this->assertSame(2000, $snapshot['hard_mb']);
        $this->assertSame(50, $snapshot['percent']); // 500/1000
    }
}
