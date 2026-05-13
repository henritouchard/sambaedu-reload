<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Wpkg;

use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Enums\WpkgGpoSyncSeverity;
use App\Gpo\Services\WpkgGpoSynchronizer;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Feature commande artisan `wpkg:gpo:sync` — Story 16.6 (AC3.4, AC5.4).
 */
class WpkgGpoSyncCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeReport(WpkgGpoSyncSeverity $severity = WpkgGpoSyncSeverity::Ok, array $overrides = []): WpkgGpoSyncReport
    {
        $defaults = [
            'gpoExists' => true,
            'gpoGuid' => '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            'gpoDisplayName' => 'se4_wpkg',
            'gpoPath' => null,
            'linkedOus' => ['OU=Computers,DC=example,DC=org'],
            'expectedHostsXmlUrl' => 'http://test/wpkg/hosts.xml',
            'expectedProfilesXmlUrl' => 'http://test/wpkg/profiles.xml',
            'templatePath' => '/usr/share/sambaedu/gpo/se4_wpkg.zip',
            'templateExists' => true,
            'templateLastModified' => null,
            'detectedPlaceholders' => ['SE4FS_NAME'],
            'unknownPlaceholders' => [],
            'bearerCoverage' => [],
            'bearerTableAvailable' => false,
            'severity' => $severity,
            'messages' => [],
            'operationId' => 'cmd-test-1',
        ];
        return new WpkgGpoSyncReport(...array_merge($defaults, $overrides));
    }

    /**
     * @param  array{audit?: callable|WpkgGpoSyncReport, publish?: callable|WpkgGpoSyncReport|\Throwable}  $stubs
     */
    private function bindSync(array $stubs): void
    {
        $mock = Mockery::mock(WpkgGpoSynchronizer::class);
        if (isset($stubs['audit'])) {
            if (is_callable($stubs['audit'])) {
                $mock->shouldReceive('audit')->andReturnUsing($stubs['audit']);
            } else {
                $mock->shouldReceive('audit')->andReturn($stubs['audit']);
            }
        } else {
            $mock->shouldNotReceive('audit');
        }
        if (isset($stubs['publish'])) {
            if ($stubs['publish'] instanceof \Throwable) {
                $mock->shouldReceive('publish')->andThrow($stubs['publish']);
            } elseif (is_callable($stubs['publish'])) {
                $mock->shouldReceive('publish')->andReturnUsing($stubs['publish']);
            } else {
                $mock->shouldReceive('publish')->andReturn($stubs['publish']);
            }
        } else {
            $mock->shouldNotReceive('publish');
        }
        $this->app->bind(WpkgGpoSynchronizer::class, fn () => $mock);
    }

    #[Test]
    public function default_mode_runs_audit_and_exits_with_severity_code(): void
    {
        $this->bindSync(['audit' => $this->makeReport(WpkgGpoSyncSeverity::Ok)]);
        $exit = Artisan::call('wpkg:gpo:sync');
        self::assertSame(0, $exit);
        $output = Artisan::output();
        self::assertStringContainsString('OK', $output);
        self::assertStringContainsString('se4_wpkg', $output);
    }

    #[Test]
    public function audit_only_does_not_call_publish(): void
    {
        $auditCalled = false;
        $this->bindSync([
            'audit' => function () use (&$auditCalled) {
                $auditCalled = true;
                return $this->makeReport(WpkgGpoSyncSeverity::Warning);
            },
        ]);

        $exit = Artisan::call('wpkg:gpo:sync', ['--audit-only' => true]);
        self::assertTrue($auditCalled);
        self::assertSame(1, $exit, 'severity=warning → exit code 1');
    }

    #[Test]
    public function force_calls_publish_with_force_true(): void
    {
        $forceCalledWith = null;
        $this->bindSync([
            'publish' => function (bool $force) use (&$forceCalledWith) {
                $forceCalledWith = $force;
                return $this->makeReport(WpkgGpoSyncSeverity::Ok);
            },
        ]);

        $exit = Artisan::call('wpkg:gpo:sync', ['--force' => true]);
        self::assertTrue($forceCalledWith);
        self::assertSame(0, $exit);
    }

    #[Test]
    public function json_outputs_serialized_dto(): void
    {
        $this->bindSync(['audit' => $this->makeReport(WpkgGpoSyncSeverity::Warning, [
            'linkedOus' => [],
            'messages' => ['GPO non liée'],
        ])]);

        $exit = Artisan::call('wpkg:gpo:sync', ['--json' => true]);
        self::assertSame(1, $exit);
        $output = trim(Artisan::output());
        $decoded = json_decode($output, true);
        self::assertIsArray($decoded);
        self::assertSame('warning', $decoded['severity']);
        self::assertSame([], $decoded['linkedOus']);
        self::assertSame('cmd-test-1', $decoded['operationId']);
    }

    #[Test]
    public function exits_with_code_2_on_error_severity(): void
    {
        $this->bindSync(['audit' => $this->makeReport(WpkgGpoSyncSeverity::Error)]);
        $exit = Artisan::call('wpkg:gpo:sync');
        self::assertSame(2, $exit);
    }

    #[Test]
    public function audit_only_and_force_are_mutually_exclusive(): void
    {
        $this->bindSync([]);
        $exit = Artisan::call('wpkg:gpo:sync', ['--audit-only' => true, '--force' => true]);
        self::assertSame(3, $exit, 'options incompatibles → exit 3');
    }

    #[Test]
    public function it_exits_3_on_exception_from_synchronizer(): void
    {
        $this->bindSync([
            'publish' => new \RuntimeException('lock indisponible'),
        ]);
        $exit = Artisan::call('wpkg:gpo:sync', ['--force' => true]);
        self::assertSame(3, $exit);
        self::assertStringContainsString('lock indisponible', Artisan::output());
    }
}
