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
 * Story 29.2 / 29.8 — Gate `modify-capability` ({@see CapabilityPolicy::modify}).
 *
 * ⚠️ CONTRAT UNITAIRE RÉVISÉ par 29.8 (changement VOULU, pas une régression de
 * sécurité). Le plancher de droit GLOBAL `app.customize` a été RETIRÉ de la policy :
 * la fermeture du droit a MIGRÉ vers les surfaces (`guardCustomize` scopé /
 * `guardAdmin` global), prouvée par les suites Feature
 * (CapabilitiesTabCustomizeScopingTest, ParcDefaultsUpstreamLockTest). Au niveau
 * policy, le VERROU AMONT est désormais le SEUL motif de refus :
 *  - verrouillé amont → `false` (indépendant du droit) ;
 *  - non verrouillé / permissif / standalone → `true` (le droit n'est plus évalué ici) ;
 *  - `null` (aucune capacité résolue) → `true` toujours.
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
    public function right_is_no_longer_enforced_at_policy_level(): void
    {
        // Story 29.8 — CHANGEMENT DE CONTRAT VOULU : avant 29.8 ce cas (user SANS
        // `app.customize`, capacité non verrouillée) renvoyait `false` à cause du
        // plancher de droit GLOBAL. Le plancher a été RETIRÉ : le droit est désormais
        // filtré PAR SURFACE en amont (guardCustomize/guardAdmin), pas par la policy.
        // Ce n'est PAS une régression de sécurité — cf. docblock de classe et suites
        // Feature CapabilitiesTabCustomizeScopingTest / ParcDefaultsUpstreamLockTest.
        $cap = $this->capabilityWithKey('HKCU', 'Software\\NoRight', 'Allow');
        self::assertTrue(
            $this->policy()->modify($this->user(false), $cap),
            'la policy ne gate plus le droit : le verrou amont est le seul motif de refus',
        );
    }

    #[Test]
    public function allow_when_not_locked(): void
    {
        $cap = $this->capabilityWithKey('HKCU', 'Software\\Ok', 'Allow');
        // Aucun contrat → non verrouillé. `user(true)` est désormais indifférent
        // (le droit n'est plus évalué par la policy depuis 29.8).
        self::assertTrue($this->policy()->modify($this->user(true), $cap));
    }

    #[Test]
    public function deny_when_capability_is_upstream_locked(): void
    {
        $cap = $this->capabilityWithKey('HKCU', 'Software\\Lk', 'Deny');
        ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\Lk|Deny|REG_DWORD',
        ]);

        self::assertFalse(
            $this->policy()->modify($this->user(true), $cap),
            'verrou amont = seul motif de refus de la policy (29.8)',
        );
    }

    #[Test]
    public function allow_when_permissive(): void
    {
        $cap = $this->capabilityWithKey('HKCU', 'Software\\Pm', 'Perm');
        ControlHubContractItem::factory()->permissive()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\Pm|Perm|REG_DWORD',
        ]);

        self::assertTrue($this->policy()->modify($this->user(true), $cap));
    }

    #[Test]
    public function null_capability_is_always_allowed(): void
    {
        // Story 29.8 — aucune capacité résolue ⇒ aucun verrou applicable ⇒ `true`
        // quel que soit le droit (avant 29.8, `user(false)` renvoyait `false` via le
        // plancher retiré). Le droit migre vers les surfaces.
        self::assertTrue($this->policy()->modify($this->user(true), null));
        self::assertTrue($this->policy()->modify($this->user(false), null));
    }
}
