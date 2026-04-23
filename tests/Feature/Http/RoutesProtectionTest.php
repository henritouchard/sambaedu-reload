<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC8, AC11) — Protection middleware `can:` sur routes sensibles.
 *
 * Vérifie :
 *  - accès direct URL sans permission → 403
 *  - accès avec permission → 200 / 302 (route existante)
 *
 * Les tests sont tolérants aux erreurs d'initialisation métier (observer AD,
 * composants Livewire qui crashent faute de state complet) : on vérifie
 * uniquement que le middleware `can:` fait son travail en amont.
 */
class RoutesProtectionTest extends TestCase
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

    /**
     * Provider : [url, permissionRequired]
     *
     * Note : on ne teste ici que la barrière middleware `can:`. L'exécution
     * réussie du composant Livewire est un autre niveau de test (listings,
     * etc.). Pour chaque route, on attend 403 sans permission.
     */
    public static function protectedRoutesProvider(): array
    {
        return [
            'users listing'     => ['/app/users', 'user.read'],
            'users show'        => ['/app/users/someone', 'user.read'],
            'users new'         => ['/app/users/new', 'user.modify'],
            'user group show'   => ['/app/users/groups/1', 'user.read'],
            'rights management' => ['/app/rights-management', 'user.assign.right'],
            'parc index'        => ['/app/parc', 'computer.view'],
            'parc group show'   => ['/app/parc/groups/1', 'computer.view'],
            'parc settings'     => ['/app/parc-settings', 'computer.install'],
            'sync from ad'      => ['/admin/sync-from-ad', 'server.admin'],
            // Review 7.2 #M4 : couverture explicite des 2 routes dont la perm a
            // été corrigée (#1 : computer.modify → computer.install).
            'parc groups new'   => ['/app/parc/groups/new', 'computer.install'],
            'parc groups edit'  => ['/app/parc/groups/1/edit', 'computer.install'],
        ];
    }

    /**
     *  dataProvider protectedRoutesProvider
     */
    public function test_route_returns_403_without_permission(string $url, string $permission): void
    {
        $user = $this->makeUser('noperm-' . md5($url));
        $this->actingAs($user);

        // On bypass le middleware sambaedu.auth (qui requiert une session LDAP
        // active non disponible en tests isolés) mais on garde `can:`.
        $response = $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ])->get($url);
        $response->assertStatus(403);
    }

    /**
     *  dataProvider protectedRoutesProvider
     */
    public function test_route_passes_permission_middleware_when_granted(string $url, string $permission): void
    {
        $user = $this->makeUser('perm-' . md5($url), [$permission]);
        $this->actingAs($user);

        $response = $this->withoutMiddleware([
            \App\Http\Middleware\Auth\SambaEduAuth::class,
            \App\Http\Middleware\RequireAdminRights::class,
        ])->get($url);
        // Le middleware `can:` est franchi ; on peut avoir 200 (liste vide),
        // 404 (cible absente pour /groups/1 etc.), ou 500 (composant Livewire
        // qui s'attend à des données LDAP) — mais pas 403.
        $this->assertNotEquals(403, $response->getStatusCode(), 'Le middleware can: ne doit pas bloquer quand la permission est accordée');
    }
}
