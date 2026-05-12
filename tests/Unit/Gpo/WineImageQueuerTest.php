<?php

declare(strict_types=1);

namespace Tests\Unit\Gpo;

use App\Gpo\Jobs\GenerateWineImageJob;
use App\Gpo\Services\WineImageAlreadyQueuedException;
use App\Gpo\Services\WineImageQueuer;
use App\Gpo\Services\WinePrefixScanner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests Unit `WineImageQueuer` — Story 16.3c AC6.2 / AC1.3.
 */
class WineImageQueuerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Reset locks pour isolation tests.
        Cache::lock('gpo:wine:generate-image:__default__')->forceRelease();
        Cache::lock('gpo:wine:generate-image:firefox')->forceRelease();
    }

    protected function tearDown(): void
    {
        Cache::lock('gpo:wine:generate-image:__default__')->forceRelease();
        Cache::lock('gpo:wine:generate-image:firefox')->forceRelease();
        Mockery::close();
        parent::tearDown();
    }

    private function makeQueuer(array $availablePrefixes = []): WineImageQueuer
    {
        Queue::fake();

        $scanner = Mockery::mock(WinePrefixScanner::class);
        $scanner->shouldReceive('exists')
            ->andReturnUsing(fn(string $app) => $app === '' || in_array($app, $availablePrefixes, true));

        return new WineImageQueuer(
            $this->app->make(\Illuminate\Contracts\Bus\Dispatcher::class),
            $scanner,
        );
    }

    #[Test]
    public function dispatch_pushes_job_to_queue_for_default_container(): void
    {
        Queue::fake();
        $queuer = $this->makeQueuer();

        $operationId = $queuer->dispatch('');

        $this->assertNotEmpty($operationId);
        Queue::assertPushed(GenerateWineImageJob::class, function (GenerateWineImageJob $job) {
            return $job->application === '';
        });
    }

    #[Test]
    public function dispatch_pushes_job_to_queue_for_named_prefix(): void
    {
        Queue::fake();
        $queuer = $this->makeQueuer(['firefox']);

        $queuer->dispatch('firefox');

        Queue::assertPushed(GenerateWineImageJob::class, function (GenerateWineImageJob $job) {
            return $job->application === 'firefox';
        });
    }

    #[Test]
    public function dispatch_throws_invalid_argument_for_application_violating_regex(): void
    {
        $queuer = $this->makeQueuer(['firefox']);

        $this->expectException(\InvalidArgumentException::class);
        $queuer->dispatch('; rm -rf /');
    }

    #[Test]
    public function dispatch_throws_invalid_argument_when_prefix_not_in_scanner(): void
    {
        $queuer = $this->makeQueuer(['firefox']);

        $this->expectException(\InvalidArgumentException::class);
        $queuer->dispatch('absent-app');
    }

    #[Test]
    public function dispatch_throws_already_queued_when_lock_held(): void
    {
        Queue::fake();
        $queuer = $this->makeQueuer(['firefox']);

        $queuer->dispatch('firefox');

        $this->expectException(WineImageAlreadyQueuedException::class);
        $queuer->dispatch('firefox');
    }

    #[Test]
    public function dispatch_logs_via_gpo_channel(): void
    {
        Queue::fake();
        $queuer = $this->makeQueuer();

        Log::shouldReceive('channel')
            ->with('gpo')
            ->andReturnSelf()
            ->shouldReceive('log')
            ->atLeast()->once();

        $queuer->dispatch('');
    }
}
