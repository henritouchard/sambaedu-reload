<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\Delegation;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Policies\DelegationPolicy;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC5) — DelegationPolicy.
 */
class DelegationPolicyTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private DelegationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
        $this->policy = new DelegationPolicy();
        WorkstationGroupObserver::disableSync();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
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

    public function test_admin_can_view_any_delegation(): void
    {
        $admin = $this->makeUser('admin', ['user.assign.right']);
        $this->assertTrue($this->policy->viewAny($admin));
    }

    public function test_non_admin_cannot_view_any_delegation(): void
    {
        $user = $this->makeUser('plain');
        $this->assertFalse($this->policy->viewAny($user));
    }

    public function test_user_can_view_own_delegation(): void
    {
        $user = $this->makeUser('u1');
        $group = WorkstationGroup::create(['name' => 'g1', 'is_physical' => true]);
        $perm = Permission::findByName('computer.view', 'web');
        $delegation = Delegation::create([
            'user_id' => $user->id,
            'workstation_group_id' => $group->id,
            'permission_id' => $perm->id,
            'is_negative' => false,
        ]);

        $this->assertTrue($this->policy->view($user, $delegation));
    }

    public function test_user_cannot_view_foreign_delegation(): void
    {
        $owner = $this->makeUser('owner');
        $other = $this->makeUser('other');
        $group = WorkstationGroup::create(['name' => 'g1', 'is_physical' => true]);
        $perm = Permission::findByName('computer.view', 'web');
        $delegation = Delegation::create([
            'user_id' => $owner->id,
            'workstation_group_id' => $group->id,
            'permission_id' => $perm->id,
            'is_negative' => false,
        ]);

        $this->assertFalse($this->policy->view($other, $delegation));
    }

    public function test_create_requires_assign_right_and_delegate(): void
    {
        $admin = $this->makeUser('a', ['user.assign.right', 'user.delegate']);
        $partial = $this->makeUser('p', ['user.assign.right']);
        $none = $this->makeUser('n');

        $this->assertTrue($this->policy->create($admin));
        $this->assertFalse($this->policy->create($partial));
        $this->assertFalse($this->policy->create($none));
    }

    public function test_delete_requires_assign_right(): void
    {
        $admin = $this->makeUser('a', ['user.assign.right']);
        $none = $this->makeUser('n');

        $this->assertTrue($this->policy->delete($admin));
        $this->assertFalse($this->policy->delete($none));
    }
}
