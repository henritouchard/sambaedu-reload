<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC11) — Accès refusé sur URL directe.
 *
 * Scénarios :
 *  - User non-admin tape `/app/users/new` → 403 (middleware can:user.modify).
 *  - User non-admin tape `/admin/sync-from-ad` → 403 (middleware can:server.admin).
 *  - Un log security est écrit par Laravel (implicite via Authorize middleware).
 */
class DirectAccessDeniedTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUser(string $login, array $perms = []): User
    {
        $user = User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
        foreach ($perms as $p) {
            $user->givePermissionTo($p);
        }
        return $user;
    }

    public function test_direct_get_users_new_without_permission_returns_403(): void
    {
        $user = $this->makeUser('noperm');
        $this->actingAs($user);

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ])->get('/app/users/new');

        $response->assertStatus(403);
    }

    public function test_direct_get_admin_sync_without_server_admin_returns_403(): void
    {
        $user = $this->makeUser('noperm2');
        $this->actingAs($user);

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ])->get('/admin/sync-from-ad');

        $response->assertStatus(403);
    }

    public function test_direct_get_rights_management_without_assign_right_returns_403(): void
    {
        $user = $this->makeUser('plain');
        $this->actingAs($user);

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ])->get('/app/rights-management');

        $response->assertStatus(403);
    }
}
