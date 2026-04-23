<?php

declare(strict_types=1);

namespace Tests\Feature\Policies;

use App\Models\User;
use App\Policies\SharePolicy;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;
use Tests\Traits\CreatesPermissionSchema;

/**
 * Story 7.2 (AC5) — SharePolicy.
 */
class SharePolicyTest extends TestCase
{
    use DatabaseTransactions;
    use CreatesPermissionSchema;

    private SharePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        if (!config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
        }
        $this->createPermissionSchema();
        (new PermissionSeeder())->run();
        $this->policy = new SharePolicy();
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

    public function test_view_requires_share_view(): void
    {
        $with = $this->makeUser('viewer', ['share.view']);
        $without = $this->makeUser('none');

        $this->assertTrue($this->policy->viewAny($with));
        $this->assertTrue($this->policy->view($with));
        $this->assertFalse($this->policy->viewAny($without));
    }

    public function test_refresh_requires_share_refresh(): void
    {
        $with = $this->makeUser('r', ['share.refresh']);
        $onlyView = $this->makeUser('v', ['share.view']);

        $this->assertTrue($this->policy->refresh($with));
        $this->assertFalse($this->policy->refresh($onlyView));
    }
}
