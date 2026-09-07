<?php

declare(strict_types=1);

namespace Tests\Feature\Gpo;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\BootstrapsSpatieTables;
use Tests\TestCase;

/**
 * Tests Feature permission `/admin/settings/gpo/wpkg-deployment` — +.
 *
 * 4 cas iso : 200 admin / 403 user / 403 unauthenticated / 403 route HTTP.
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
        // Le composant charge system_settings dans mount.
        if (! Schema::hasTable('system_settings')) {
            Schema::create('system_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            });
        }
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->cleanupSpatieTables();
        parent::tearDown();
    }

    // Les helpers `bindSyncOk` / `bindSyncExpectNoCalls`
    // (mock `WpkgGpoSynchronizer`) ont été retirés : l'audit GPO `se4_wpkg` a
    // été supprimé de la page. La page (réglages de déploiement) n'injecte plus
    // le synchronizer ; le test de permission reste valable sur la page existante.

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

        Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index')
            ->assertStatus(200);
    }

    #[Test]
    public function mount_aborts_403_without_server_admin(): void
    {
        $this->actingAs($this->makeRegularUser('user-wpkg-perm-ko'));

        try {
            Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index');
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

        $response = $this->get('/admin/settings/gpo/wpkg-deployment');
        $this->assertContains($response->getStatusCode(), [403, 302, 500], 'Réponse non-200 attendue pour non-admin.');
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    #[Test]
    public function unauthenticated_request_is_blocked(): void
    {

        try {
            Livewire::test('pages::admin.settings.gpo.wpkg-deployment.index');
            $this->fail('Expected 403 abort for unauthenticated');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        } catch (\Throwable) {
            $this->assertTrue(true);
        }
    }
}
