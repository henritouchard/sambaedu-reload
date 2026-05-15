<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Jobs\GenerateWineImageJob;
use App\Gpo\Services\WineImageQueuer;
use App\Gpo\Services\WinePrefixScanner;
use App\Models\User;
use App\Services\ShortcutsService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;

/**
 * Tests Feature Livewire — Page admin Wine `/app/gpo/wine` (Story 16.3c AC6.1).
 */
class WinePageTest extends TestCase
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
        Cache::lock('gpo:wine:generate-image:firefox')->forceRelease();
    }

    protected function tearDown(): void
    {
        Cache::lock('gpo:wine:generate-image:__default__')->forceRelease();
        Cache::lock('gpo:wine:generate-image:firefox')->forceRelease();
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        $u = User::query()->create(['login' => 'wine-admin-' . bin2hex(random_bytes(3)), 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeUser(): User
    {
        return User::query()->create(['login' => 'wine-user-' . bin2hex(random_bytes(3)), 'role' => 'eleve', 'is_active' => true]);
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

    #[Test]
    public function it_redirects_unauthenticated_to_login(): void
    {
        $resp = $this->get('/app/gpo/wine');
        // sambaedu.auth middleware → redirect (302) ou 401
        $this->assertContains($resp->status(), [302, 401], 'Expected 302/401 for unauthenticated');
    }

    #[Test]
    public function it_returns_403_for_user_without_server_admin(): void
    {
        // Bypass sambaedu.auth (vérifie $_SESSION['login'], non touché par
        // `actingAs`) — on garde `can:server.admin` actif pour valider que
        // l'utilisateur eleve reçoit bien un 403 et pas un 302 d'auth.
        $this->mockScanner([]);
        $this->actingAs($this->makeUser());
        $this->withoutMiddleware(\App\Http\Middleware\Auth\SambaEduAuth::class);
        $resp = $this->get('/app/gpo/wine');
        $resp->assertForbidden();
    }

    #[Test]
    public function it_renders_page_with_prefix_select_for_admin(): void
    {
        $this->mockScanner(['firefox', 'libreoffice']);
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::app.gpo.wine.index')
            ->assertSee('Wine')
            ->assertSee('firefox')
            ->assertSee('libreoffice')
            ->assertSet('selectedApplication', '');
    }

    #[Test]
    public function it_lists_wine_prefixes_from_scanner_mock(): void
    {
        $this->mockScanner(['photoshop', 'autocad']);
        $this->actingAs($this->makeAdmin());

        $component = Livewire::test('pages::app.gpo.wine.index');
        $this->assertSame(['photoshop', 'autocad'], $component->get('prefixes'));
    }

    #[Test]
    public function it_dispatches_generate_image_job_with_valid_application(): void
    {
        Queue::fake();
        $this->mockScanner(['firefox']);
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::app.gpo.wine.index')
            ->set('selectedApplication', 'firefox')
            ->call('generateImage');

        Queue::assertPushed(GenerateWineImageJob::class, function (GenerateWineImageJob $job) {
            return $job->application === 'firefox';
        });
    }

    #[Test]
    public function it_rejects_invalid_application_input(): void
    {
        Queue::fake();
        $this->mockScanner(['firefox']);
        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::app.gpo.wine.index')
            ->set('selectedApplication', '; rm -rf /')
            ->call('generateImage');

        Queue::assertNothingPushed();
    }

    #[Test]
    public function it_calls_shortcuts_service_on_generate_shortcuts(): void
    {
        $this->mockScanner(['firefox']);
        $shortcuts = Mockery::mock(ShortcutsService::class);
        $shortcuts->shouldReceive('importWineShortcuts')->with('firefox')->once()->andReturn(3);
        $this->app->instance(ShortcutsService::class, $shortcuts);

        $this->actingAs($this->makeAdmin());

        Livewire::test('pages::app.gpo.wine.index')
            ->set('selectedApplication', 'firefox')
            ->call('generateShortcuts');
    }

    #[Test]
    public function it_warns_when_image_already_queued_for_same_application(): void
    {
        Queue::fake();
        $this->mockScanner(['firefox']);
        $this->actingAs($this->makeAdmin());

        // 1er dispatch.
        Livewire::test('pages::app.gpo.wine.index')
            ->set('selectedApplication', 'firefox')
            ->call('generateImage');

        // 2ᵉ dispatch consécutif → lock détenu → toast warning.
        Livewire::test('pages::app.gpo.wine.index')
            ->set('selectedApplication', 'firefox')
            ->call('generateImage')
            ->assertDispatched('toastMagic'); // toast warning émis

        // Un seul Job poussé (le second a échoué sur le lock).
        Queue::assertPushed(GenerateWineImageJob::class, 1);
    }
}
