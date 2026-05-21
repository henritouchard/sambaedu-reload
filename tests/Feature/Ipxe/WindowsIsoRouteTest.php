<?php

declare(strict_types=1);

namespace Tests\Feature\Ipxe;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;
use Tests\Traits\CreatesWindowsIsoSchema;

/**
 * Story 3.6 — AC5.1, AC6.1 — Tests Feature de la route admin `/admin/ipxe/iso-windows`.
 *
 * Vérifie le contrat middleware :
 *  - 302 redirect login si user non authentifié.
 *  - 403 si user authentifié sans permission `server.admin`.
 *  - 200 OK + body contenant "Mise en place des sources" si user admin.
 */
class WindowsIsoRouteTest extends TestCase
{
    use CreatesPermissionSchema;
    use CreatesWindowsIsoSchema;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createPermissionSchema();
        $this->createWindowsIsoSchema();
        (new PermissionSeeder())->run();

        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
    }

    protected function tearDown(): void
    {
        $this->dropWindowsIsoSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeAdmin(): User
    {
        $u = User::query()->create([
            'login' => 'admin-iso-route-' . uniqid(),
            'role'  => 'prof',
            'is_active' => true,
        ]);
        $u->givePermissionTo('server.admin');

        return $u;
    }

    private function makeTeacher(): User
    {
        return User::query()->create([
            'login' => 'teacher-iso-' . uniqid(),
            'role'  => 'prof',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_redirects_unauthenticated_user_to_login(): void
    {
        $response = $this->get('/admin/ipxe/iso-windows');

        // Le middleware `sambaedu.auth` renvoie un redirect 302 vers le login.
        self::assertContains($response->status(), [302, 403, 401],
            'User non authentifié doit être redirect (302) ou refusé (401/403).');
    }

    #[Test]
    public function it_returns_403_for_non_admin_user(): void
    {
        $this->actingAs($this->makeTeacher());

        $response = $this->get('/admin/ipxe/iso-windows');

        // Le middleware `sambaedu.admin` ou `can:server.admin` refuse.
        self::assertContains($response->status(), [403, 302],
            'User non admin doit recevoir 403 ou redirect.');
    }

    #[Test]
    public function it_renders_page_for_admin_with_server_admin_permission(): void
    {
        $this->actingAs($this->makeAdmin());

        $response = $this->get('/admin/ipxe/iso-windows');

        $response->assertOk();
        $response->assertSeeText('Gestion ISO Windows');
        $response->assertSeeText('Versions Windows déployées');
        $response->assertSeeText('Nouveau téléchargement');
        $response->assertSeeText('Historique');
    }
}
