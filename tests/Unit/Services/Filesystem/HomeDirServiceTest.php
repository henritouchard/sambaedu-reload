<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Filesystem;

use App\Services\Filesystem\HomeDirService;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Socle minimal de tests pour HomeDirService.
 *
 * Objectif : verrouiller la garde anti-injection (regex login) sur les 5
 * méthodes devenues publiques lors du refactor 5.1a. Les flux filesystem
 * (mkdir/mv/rm via sudo) sont couverts indirectement par UserServiceCreateTest
 * et les tests feature — pas de mock shell ici.
 */
class HomeDirServiceTest extends TestCase
{
    private HomeDirService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HomeDirService();
    }

    public static function maliciousLoginProvider(): array
    {
        return [
            'path traversal'      => ['../etc/passwd'],
            'command injection'   => ['; rm -rf /'],
            'backtick injection'  => ['`whoami`'],
            'dollar expansion'    => ['$(id)'],
            'pipe injection'      => ['foo|cat'],
            'null byte'           => ["foo\0bar"],
            'space'               => ['foo bar'],
            'leading slash'       => ['/etc'],
        ];
    }

    #[Test]
    #[DataProvider('maliciousLoginProvider')]
    public function create_home_directory_rejects_malicious_login(string $login): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::pattern('/login invalide/'), \Mockery::on(fn ($ctx) => $ctx['login'] === $login));
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();

        $this->service->createHomeDirectory($login);
    }

    #[Test]
    #[DataProvider('maliciousLoginProvider')]
    public function archive_home_directory_rejects_malicious_login(string $login): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::pattern('/login invalide/'), \Mockery::on(fn ($ctx) => $ctx['login'] === $login));

        $this->assertFalse($this->service->archiveHomeDirectory($login));
    }

    #[Test]
    #[DataProvider('maliciousLoginProvider')]
    public function restore_home_directory_rejects_malicious_login(string $login): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::pattern('/login invalide/'), \Mockery::on(fn ($ctx) => $ctx['login'] === $login));

        $this->assertFalse($this->service->restoreHomeDirectory($login));
    }

    #[Test]
    #[DataProvider('maliciousLoginProvider')]
    public function delete_home_directory_permanently_rejects_malicious_login(string $login): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with(\Mockery::pattern('/login invalide/'), \Mockery::on(fn ($ctx) => $ctx['login'] === $login));

        $this->assertFalse($this->service->deleteHomeDirectoryPermanently($login));
    }

    #[Test]
    #[DataProvider('maliciousLoginProvider')]
    public function has_archived_home_returns_false_for_malicious_login(string $login): void
    {
        $this->assertFalse($this->service->hasArchivedHome($login));
    }

    #[Test]
    public function has_archived_home_returns_false_when_trash_dir_missing(): void
    {
        $this->assertFalse($this->service->hasArchivedHome('nonexistent.user'));
    }
}
