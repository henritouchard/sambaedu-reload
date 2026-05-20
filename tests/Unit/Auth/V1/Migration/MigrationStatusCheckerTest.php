<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\V1\Migration;

use App\Auth\V1\Migration\Services\MigrationStatusChecker;
use App\Auth\V1\Models\WorkstationMigrationAttempt;
use App\Auth\V1\Models\WorkstationMigrationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\IssuesWorkstationJwt;
use Tests\TestCase;

/**
 * Story 16.13bis — Tests unitaires `MigrationStatusChecker`.
 *
 * Couvre `isMigrated()` (UUID vide / présent / absent) + `logAttempt()`
 * insertion DB + `extractDeclaredUuid()` regex validation.
 */
final class MigrationStatusCheckerTest extends TestCase
{
    use IssuesWorkstationJwt;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureAuthV1Tables();
    }

    #[Test]
    public function is_migrated_returns_false_when_uuid_null_or_empty(): void
    {
        $checker = app(MigrationStatusChecker::class);

        self::assertFalse($checker->isMigrated(null));
        self::assertFalse($checker->isMigrated(''));
        self::assertFalse($checker->isMigrated('   '));
    }

    #[Test]
    public function is_migrated_returns_true_when_status_row_exists(): void
    {
        $uuid = '11111111-1111-4111-8111-111111111111';
        WorkstationMigrationStatus::create([
            'workstation_uuid' => $uuid,
            'migrated_at' => now(),
            'os' => 'windows',
        ]);

        $checker = app(MigrationStatusChecker::class);

        self::assertTrue($checker->isMigrated($uuid));
        self::assertTrue($checker->isMigrated(strtoupper($uuid)), 'lookup case-insensitive');
    }

    #[Test]
    public function is_migrated_returns_false_when_status_row_missing(): void
    {
        $checker = app(MigrationStatusChecker::class);

        self::assertFalse($checker->isMigrated('22222222-2222-4222-8222-222222222222'));
    }

    #[Test]
    public function log_attempt_inserts_row_with_started_status(): void
    {
        $request = Request::create('/gpo/wallpaper_out.php', 'GET', ['os' => 'windows', 'uuid' => '33333333-3333-4333-8333-333333333333']);
        $request->headers->set('User-Agent', 'Mozilla/5.0 (Windows NT 10.0)');
        $request->server->set('REMOTE_ADDR', '192.168.1.42');

        $checker = app(MigrationStatusChecker::class);
        $checker->logAttempt($request, 'windows', '33333333-3333-4333-8333-333333333333');

        $attempt = WorkstationMigrationAttempt::query()
            ->where('workstation_uuid', '33333333-3333-4333-8333-333333333333')
            ->first();

        self::assertNotNull($attempt);
        self::assertSame(WorkstationMigrationAttempt::STATUS_STARTED, $attempt->status);
        self::assertSame('windows', $attempt->os);
        self::assertStringContainsString('192.168', $attempt->client_ip);
    }

    #[Test]
    public function extract_declared_uuid_accepts_valid_v4_lowercased(): void
    {
        $checker = app(MigrationStatusChecker::class);

        $request = Request::create('/gpo/wallpaper_out.php', 'GET', ['uuid' => 'AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA']);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $checker->extractDeclaredUuid($request));
    }

    #[Test]
    public function extract_declared_uuid_returns_null_on_garbage(): void
    {
        $checker = app(MigrationStatusChecker::class);

        self::assertNull($checker->extractDeclaredUuid(Request::create('/gpo/wallpaper_out.php', 'GET', ['uuid' => ''])));
        self::assertNull($checker->extractDeclaredUuid(Request::create('/gpo/wallpaper_out.php', 'GET', ['uuid' => 'not-a-uuid'])));
        self::assertNull($checker->extractDeclaredUuid(Request::create('/gpo/wallpaper_out.php', 'GET')));
    }
}
