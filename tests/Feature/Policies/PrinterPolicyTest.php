<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Policies\PrinterPolicy;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC5) — PrinterPolicy.
 */
class PrinterPolicyTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private PrinterPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
        $this->policy = new PrinterPolicy();
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

    public function test_server_admin_can_view_and_manage(): void
    {
        $admin = $this->makeUser('srv', ['server.admin']);
        $this->assertTrue($this->policy->viewAny($admin));
        $this->assertTrue($this->policy->manage($admin));
    }

    public function test_non_admin_is_denied(): void
    {
        $user = $this->makeUser('plain');
        $this->assertFalse($this->policy->viewAny($user));
        $this->assertFalse($this->policy->manage($user));
    }

    public function test_null_user_is_denied(): void
    {
        $this->assertFalse($this->policy->viewAny(null));
        $this->assertFalse($this->policy->manage(null));
    }
}
