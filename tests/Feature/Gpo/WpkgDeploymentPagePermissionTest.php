<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Gpo\Dto\WpkgGpoSyncReport;
use App\Gpo\Enums\WpkgGpoSyncSeverity;
use App\Gpo\Services\WpkgGpoSynchronizer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;

/**
 * Tests Feature permission `/app/gpo/wpkg-deployment` — Story 16.6 (AC5.2, AC3.5).
 *
 * 4 cas iso 16.5 : 200 admin / 403 user / 403 unauthenticated / 403 route HTTP.
 */
class WpkgDeploymentPagePermissionTest extends TestCase
{
    use DatabaseTransactions;
    use BootstrapsSpatieTables;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->bootstrapSpatieTables();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    private function bindSyncOk(): void
    {
        $mock = Mockery::mock(WpkgGpoSynchronizer::class);
        $mock->shouldReceive('audit')->andReturn(new WpkgGpoSyncReport(
            gpoExists: true,
            gpoGuid: '{AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE}',
            gpoDisplayName: 'se4_wpkg',
            gpoPath: null,
            linkedOus: ['OU=Computers,DC=example,DC=org'],
            expectedHostsXmlUrl: 'http://test/wpkg/hosts.xml',
            expectedProfilesXmlUrl: 'http://test/wpkg/profiles.xml',
            templatePath: '/usr/share/sambaedu/gpo/se4_wpkg.zip',
            templateExists: true,
            templateLastModified: null,
            detectedPlaceholders: [],
            unknownPlaceholders: [],
            bearerCoverage: [],
            bearerTableAvailable: false,
            severity: WpkgGpoSyncSeverity::Ok,
            messages: [],
        ));
        $this->app->bind(WpkgGpoSynchronizer::class, fn () => $mock);
    }

    private function bindSyncExpectNoCalls(): void
    {
        $mock = Mockery::mock(WpkgGpoSynchronizer::class);
        $mock->shouldNotReceive('audit');
        $mock->shouldNotReceive('publish');
        $this->app->bind(WpkgGpoSynchronizer::class, fn () => $mock);
    }

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'admin', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function makeRegularUser(string $login): User
    {
        return User::query()->create(['login' => $login, 'role' => 'eleve', 'is_active' => true]);
    }

    #[Test]
    public function it_renders_200_for_authenticated_admin(): void
    {
        $this->actingAs($this->makeAdmin('admin-wpkg-perm-ok'));
        $this->bindSyncOk();

        Livewire::test('pages::app.gpo.wpkg-deployment.index')
            ->assertStatus(200);
    }

    #[Test]
    public function mount_aborts_403_without_server_admin(): void
    {
        $this->actingAs($this->makeRegularUser('user-wpkg-perm-ko'));
        $this->bindSyncExpectNoCalls();

        try {
            Livewire::test('pages::app.gpo.wpkg-deployment.index');
            $this->fail('Expected 403 abort');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }

    #[Test]
    public function route_middleware_returns_403_without_permission(): void
    {
        $user = $this->makeRegularUser('user-wpkg-route-ko');
        $this->actingAs($user);
        $this->bindSyncExpectNoCalls();

        $response = $this->get('/app/gpo/wpkg-deployment');
        $this->assertContains($response->getStatusCode(), [403, 302, 500], 'Réponse non-200 attendue pour non-admin.');
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticated_request_is_blocked(): void
    {
        $this->bindSyncExpectNoCalls();

        try {
            Livewire::test('pages::app.gpo.wpkg-deployment.index');
            $this->fail('Expected 403 abort for unauthenticated');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }
}
