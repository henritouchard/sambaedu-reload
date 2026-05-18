<?php

declare(strict_types=1);

namespace Tests\Unit\ScriptsOs\Console\Commands;

use App\ScriptsOs\Models\ScriptExecutionLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

class ArchiveScriptExecutionLogsCommandTest extends TestCase
{
    use IssuesWorkstationJwt;

    private string $tmpArchive = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();

        $this->tmpArchive = sys_get_temp_dir() . '/scriptsos-archive-' . uniqid('', true);
        File::makeDirectory($this->tmpArchive, 0775, true, true);
        config(['scriptsos.archive.path' => $this->tmpArchive]);
        config(['scriptsos.retention_days' => 90]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpArchive)) {
            File::deleteDirectory($this->tmpArchive);
        }
        parent::tearDown();
    }

    #[Test]
    public function retention_days_below_one_fails(): void
    {
        $exit = $this->artisan('script-logs:archive:rotate', ['--retention-days' => 0])
            ->run();

        self::assertSame(1, $exit);
    }

    #[Test]
    public function dry_run_does_not_write_or_delete(): void
    {
        ScriptExecutionLog::factory()->archived(100)->count(5)->create();
        ScriptExecutionLog::factory()->recent(1)->count(3)->create();

        $this->artisan('script-logs:archive:rotate', ['--dry-run' => true])
            ->assertExitCode(0);

        self::assertSame(8, ScriptExecutionLog::query()->count());

        $files = glob($this->tmpArchive . '/*.jsonl.gz') ?: [];
        self::assertEmpty($files);
    }

    #[Test]
    public function old_rows_are_archived_and_deleted(): void
    {
        // Seed 10 rows > 95j (archivables) sur le mois cible
        $oldDate = Carbon::now()->subDays(95);
        ScriptExecutionLog::factory()->count(10)->create([
            'started_at' => $oldDate,
            'reported_at' => $oldDate,
        ]);
        // Seed 3 rows récentes (à préserver)
        ScriptExecutionLog::factory()->recent(1)->count(3)->create();

        $this->artisan('script-logs:archive:rotate')
            ->assertExitCode(0);

        // DB : seules les 3 récentes restent
        self::assertSame(3, ScriptExecutionLog::query()->count());

        // Archive : un fichier mensuel créé
        $files = glob($this->tmpArchive . '/*.jsonl.gz') ?: [];
        self::assertNotEmpty($files);

        // Décompression et contrôle : 10 lignes JSONL
        $contents = '';
        foreach ($files as $file) {
            $contents .= (string) gzdecode((string) file_get_contents($file));
        }
        $lines = array_filter(explode("\n", $contents));
        self::assertCount(10, $lines);
    }

    #[Test]
    public function rerun_is_idempotent_no_duplicate_delete(): void
    {
        ScriptExecutionLog::factory()->count(5)->create([
            'started_at' => Carbon::now()->subDays(120),
            'reported_at' => Carbon::now()->subDays(120),
        ]);

        $this->artisan('script-logs:archive:rotate')->assertExitCode(0);
        self::assertSame(0, ScriptExecutionLog::query()->count());

        // 2ème exécution → pas de rows à archiver, exit 0 quand même
        $this->artisan('script-logs:archive:rotate')->assertExitCode(0);
        self::assertSame(0, ScriptExecutionLog::query()->count());
    }

    #[Test]
    public function recent_rows_are_preserved(): void
    {
        ScriptExecutionLog::factory()->recent(1)->count(7)->create();

        $this->artisan('script-logs:archive:rotate')->assertExitCode(0);

        self::assertSame(7, ScriptExecutionLog::query()->count());
        $files = glob($this->tmpArchive . '/*.jsonl.gz') ?: [];
        self::assertEmpty($files);
    }
}
