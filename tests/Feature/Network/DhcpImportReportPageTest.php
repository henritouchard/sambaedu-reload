<?php

declare(strict_types=1);

namespace Tests\Feature\Network;

use App\Models\User;
use App\Services\Network\DhcpImportService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\CreatesDhcpSchema;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 8.1 — Review code #9 (Q4) : page rapport d'import CSV
 * `/app/network/dhcp/import/{uuid}` (Livewire SFC
 * `pages::network.dhcp.import.[uuid].index`).
 *
 * Couvre :
 *  - chargement OK avec UUID valide (rapport présent en cache) ;
 *  - 404 sur UUID inexistant / expiré ;
 *  - 403 pour utilisateur sans `viewAny-dhcp`.
 */
class DhcpImportReportPageTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesDhcpSchema;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        $this->createPermissionSchema();
        $this->createDhcpSchema();
        (new PermissionSeeder())->run();
    }

    protected function tearDown(): void
    {
        $this->dropDhcpSchema();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeAdmin(string $login): User
    {
        $u = User::query()->create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        $u->givePermissionTo('server.admin');
        return $u;
    }

    private function seedReport(string $uuid, int $ok = 2, int $errors = 0): void
    {
        Cache::put(
            DhcpImportService::CACHE_PREFIX . $uuid,
            [
                'uuid' => $uuid,
                'total' => $ok + $errors,
                'ok' => $ok,
                'updated' => 0,
                'errors' => $errors,
                'skipped' => 0,
                'created_at' => '2026-05-11T12:00:00+02:00',
                'rows' => [],
            ],
            DhcpImportService::CACHE_TTL_SECONDS,
        );
    }

    public function test_admin_can_view_report_with_valid_uuid(): void
    {
        $this->actingAs($this->makeAdmin('admin-report-1'));

        $uuid = (string) Str::uuid();
        $this->seedReport($uuid, ok: 3, errors: 1);

        Livewire::test('pages::network.dhcp.import.[uuid].index', ['uuid' => $uuid])
            ->assertSuccessful()
            ->assertSee('Rapport')
            ->assertSee($uuid);
    }

    public function test_returns_404_for_unknown_uuid(): void
    {
        $this->actingAs($this->makeAdmin('admin-report-2'));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        Livewire::test('pages::network.dhcp.import.[uuid].index', ['uuid' => 'not-a-real-uuid-12345']);
    }

    public function test_non_admin_cannot_view_report(): void
    {
        $user = User::query()->create(['login' => 'plain-report', 'role' => 'eleve', 'is_active' => true]);
        $this->actingAs($user);

        $uuid = (string) Str::uuid();
        $this->seedReport($uuid);

        Livewire::test('pages::network.dhcp.import.[uuid].index', ['uuid' => $uuid])
            ->assertStatus(403);
    }
}
