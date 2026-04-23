<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\SambaPermission;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC10) — Calcul union groupe + individuel (FR29).
 *
 * Les permissions effectives d'un user sont l'union :
 *  - rôle(s) → leurs permissions
 *  - permissions directes (assignPermissionTo)
 *  - délégations scopées sur WorkstationGroup
 *
 * Les délégations négatives écrasent les positives pour le même groupe+permission.
 */
class PermissionServiceUnionTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private PermissionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
        Queue::fake();
        WorkstationGroupObserver::disableSync();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->service = app(PermissionService::class);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        $this->dropPermissionSchema();
        parent::tearDown();
    }

    private function makeUser(string $login): User
    {
        return User::create(['login' => $login, 'role' => 'prof', 'is_active' => true]);
    }

    public function test_get_all_permissions_returns_union_of_role_and_direct(): void
    {
        $user = $this->makeUser('alice');
        $user->assignRole('prof'); // user.read + user.password.init
        $user->givePermissionTo('user.modify');

        $all = $user->getAllPermissions()->pluck('name')->toArray();

        $this->assertContains('user.read', $all);
        $this->assertContains('user.password.init', $all);
        $this->assertContains('user.modify', $all);
    }

    public function test_can_on_workstation_group_grants_via_delegation(): void
    {
        $user = $this->makeUser('bob');
        $salleA = WorkstationGroup::create(['name' => 'salleA', 'is_physical' => true]);

        $this->service->grantDelegation($user, 'computer.view', $salleA);

        $this->assertTrue($this->service->canOnWorkstationGroup($user, 'computer.view', $salleA));
    }

    public function test_can_on_workstation_group_returns_false_outside_scope(): void
    {
        $user = $this->makeUser('charlie');
        $salleA = WorkstationGroup::create(['name' => 'salleA', 'is_physical' => true]);
        $salleB = WorkstationGroup::create(['name' => 'salleB', 'is_physical' => true]);

        $this->service->grantDelegation($user, 'computer.view', $salleA);

        $this->assertTrue($this->service->canOnWorkstationGroup($user, 'computer.view', $salleA));
        $this->assertFalse($this->service->canOnWorkstationGroup($user, 'computer.view', $salleB));
    }

    public function test_negative_delegation_overrides_positive(): void
    {
        $user = $this->makeUser('diane');
        $salleA = WorkstationGroup::create(['name' => 'salleA', 'is_physical' => true]);

        $this->service->grantDelegation($user, 'computer.view', $salleA);
        $this->assertTrue($this->service->canOnWorkstationGroup($user, 'computer.view', $salleA));

        $this->service->negateDelegation($user, 'computer.view', $salleA);
        $this->assertFalse($this->service->canOnWorkstationGroup($user, 'computer.view', $salleA));
    }

    public function test_global_permission_trumps_scoping(): void
    {
        $user = $this->makeUser('eve');
        $user->givePermissionTo('computer.view');
        $salleX = WorkstationGroup::create(['name' => 'salleX', 'is_physical' => true]);

        // Aucune délégation explicite, mais droit global.
        $this->assertTrue($this->service->canOnWorkstationGroup($user, 'computer.view', $salleX));
    }

    public function test_user_without_any_role_has_no_permissions(): void
    {
        $user = $this->makeUser('frank');
        $salleA = WorkstationGroup::create(['name' => 'salleA', 'is_physical' => true]);

        $this->assertTrue($user->getAllPermissions()->isEmpty());
        $this->assertFalse($this->service->canOnWorkstationGroup($user, 'computer.view', $salleA));
    }

    public function test_expired_delegation_is_ignored(): void
    {
        $user = $this->makeUser('grace');
        $salleA = WorkstationGroup::create(['name' => 'salleA', 'is_physical' => true]);

        $this->service->grantDelegation(
            $user,
            'computer.view',
            $salleA,
            null,
            new \DateTimeImmutable('-1 day')
        );

        $this->assertFalse($this->service->canOnWorkstationGroup($user, 'computer.view', $salleA));
    }

    public function test_union_includes_all_categories_for_super_admin(): void
    {
        $user = $this->makeUser('heidi');
        $user->assignRole('super-admin');

        $all = $user->getAllPermissions()->pluck('name')->toArray();

        // SuperAdmin doit avoir toutes les permissions de l'enum.
        foreach (SambaPermission::cases() as $perm) {
            $this->assertContains($perm->value, $all, "SuperAdmin doit avoir {$perm->value}");
        }
    }
}
