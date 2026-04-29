<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Config\SambaEduConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 5.1b — AC 11 : aucun shellout `xfs_quota` / `quota` ne doit
 * être déclenché par le rendu du listing /users. Tout passe par la
 * colonne `users.quota_snapshot`.
 *
 * On utilise `Process::fake()` + `Process::assertNothingRan()` : si un
 * composant enfant invoquait un shellout via la façade Process, le test
 * échouerait. Les appels PHP natifs `exec()` ne sont pas interceptés par
 * la façade mais le rendu du listing a été explicitement réécrit pour
 * ne plus invoquer XfsQuotaService.
 */
class UsersIndexPageNoShelloutTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    protected function setUp(): void
    {
        parent::setUp();

        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }

        // Le trait crée le schéma users + user_groups + workstation_groups +
        // les tables Spatie (roles, permissions, …) nécessaires au rendu du
        // <livewire:pages::users._partials.rights-drawer /> dont le mount()
        // interroge Role::where('guard_name', 'web').
        $this->createPermissionSchema();

        $seMock = Mockery::mock(SambaEduConfig::class);
        $seMock->shouldReceive('getCurrentEstablishmentCode')->andReturn(null);
        $this->app->instance(SambaEduConfig::class, $seMock);
    }

    protected function tearDown(): void
    {
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    public function test_no_xfs_shellout_is_triggered_when_rendering_users_listing(): void
    {
        // Seed 25 users (plus d'une page) avec un snapshot varié.
        for ($i = 0; $i < 25; $i++) {
            User::query()->create([
                'login' => "user-{$i}",
                'firstname' => "First{$i}",
                'lastname' => "Last{$i}",
                'role' => 'eleve',
                'is_active' => true,
                'quota_snapshot' => [
                    'home' => [
                        'used_kb' => 10000,
                        'soft_kb' => 100000,
                        'hard_kb' => 120000,
                        'used_mb' => 10,
                        'soft_mb' => 98,
                        'hard_mb' => 117,
                        'percent' => ($i * 4) % 100,
                        'is_over_soft' => false,
                        'is_over_hard' => false,
                        'grace_days' => null,
                    ],
                    'captured_at' => '2026-04-23T03:00:00+02:00',
                ],
            ]);
        }

        Process::fake();

        Livewire::test('pages::users.index');

        // Aucun shellout via la façade Process ne doit avoir été exécuté.
        Process::assertNothingRan();
    }
}
