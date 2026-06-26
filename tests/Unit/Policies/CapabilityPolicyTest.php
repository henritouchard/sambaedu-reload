<?php

declare(strict_types=1);

namespace Tests\Unit\Policies;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContractItem;
use App\Policies\CapabilityPolicy;
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.2 — Gate `modify-capability` ({@see CapabilityPolicy::modify}).
 *
 * deny si verrouillé amont (même avec `app.customize`) ; allow sinon (avec le
 * droit) ; deny sans `app.customize` ; `null` = droit seul.
 */
class CapabilityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** User mocké avec/sans `app.customize`. */
    private function user(bool $canCustomize): Authenticatable
    {
        $user = Mockery::mock(Authenticatable::class, Authorizable::class);
        $user->shouldReceive('can')->with('app.customize')->andReturn($canCustomize);
        $user->shouldReceive('can')->andReturn($canCustomize);

        return $user;
    }

    private function policy(): CapabilityPolicy
    {
        return new CapabilityPolicy(new UpstreamLockResolver());
    }

    private function capabilityWithKey(string $hive, string $path, string $name): Capability
    {
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => $hive, 'path' => $path, 'name' => $name, 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        return $cap;
    }

    #[Test]
    public function deny_without_app_customize(): void
    {
        $cap = Capability::factory()->create();
        self::assertFalse($this->policy()->modify($this->user(false), $cap));
    }

    #[Test]
    public function allow_when_not_locked_with_right(): void
    {
        $cap = $this->capabilityWithKey('HKCU', 'Software\\Ok', 'Allow');
        // Aucun contrat → non verrouillé.
        self::assertTrue($this->policy()->modify($this->user(true), $cap));
    }

    #[Test]
    public function deny_when_capability_is_upstream_locked_even_with_right(): void
    {
        $cap = $this->capabilityWithKey('HKCU', 'Software\\Lk', 'Deny');
        ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\Lk|Deny|REG_DWORD',
        ]);

        self::assertFalse(
            $this->policy()->modify($this->user(true), $cap),
            'verrou amont prévaut même avec app.customize',
        );
    }

    #[Test]
    public function allow_when_permissive_with_right(): void
    {
        $cap = $this->capabilityWithKey('HKCU', 'Software\\Pm', 'Perm');
        ControlHubContractItem::factory()->permissive()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\Pm|Perm|REG_DWORD',
        ]);

        self::assertTrue($this->policy()->modify($this->user(true), $cap));
    }

    #[Test]
    public function null_capability_falls_back_to_right_only(): void
    {
        self::assertTrue($this->policy()->modify($this->user(true), null));
        self::assertFalse($this->policy()->modify($this->user(false), null));
    }
}
