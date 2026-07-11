<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 43.2 (AC6, D5/D6) — badge de temporalité d'effet sur la section
 * « Capacités » de la page d'un groupe d'utilisateurs.
 *
 * Patron {@see GroupCapabilitiesSectionTest}.
 */
class GroupCapabilitiesSectionEffectTimingBadgeTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::users.groups.[id]._partials.capabilities-section';

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();

        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();
        WorkstationGroupObserver::disableSync();
        Queue::fake();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        Permission::firstOrCreate(['name' => 'app.customize', 'guard_name' => 'web']);
    }

    protected function tearDown(): void
    {
        UserGroupObserver::enableSync();
        UserGroupUserPivotObserver::enableSync();
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    private function makeAdmin(string $login = 'admin'): User
    {
        $u = User::factory()->create(['login' => $login]);
        $u->givePermissionTo('app.customize');
        $this->actingAs($u);

        return $u;
    }

    private function makeGroup(): UserGroup
    {
        return UserGroup::create(['name' => 'classe-6a', 'type' => 'classe', 'display_name' => 'Classe 6A']);
    }

    private function makeCapability(string $key, array $spec): Capability
    {
        $cap = Capability::factory()->create(['key' => $key, 'label' => ucfirst(str_replace('_', ' ', $key)), 'default_value' => 'off']);
        CapabilityProjection::factory()->for($cap)->create([
            'mechanism' => CapabilityProjection::MECHANISM_REGISTRY,
            'spec' => $spec,
        ]);

        return $cap;
    }

    #[Test]
    public function listing_shows_the_immediate_badge_for_a_retrofitted_capability(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('show_file_extensions', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\X', 'name' => 'HideFileExt', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
            'refresh' => 'shell_notify',
        ]);

        Livewire::test(self::COMPONENT, ['groupId' => $group->id])
            ->assertSeeHtml('data-testid="effect-timing-'.$cap->id.'"')
            ->assertSeeHtml('Immédiat');
    }

    #[Test]
    public function listing_shows_the_next_session_badge_without_a_hint(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('no_hint_cap', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\Y', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
        ]);

        Livewire::test(self::COMPONENT, ['groupId' => $group->id])
            ->assertSeeHtml('data-testid="effect-timing-'.$cap->id.'"')
            ->assertSeeHtml('À la prochaine session');
    }

    #[Test]
    public function machine_only_capabilities_are_excluded_from_the_listing_and_never_show_a_badge(): void
    {
        // Piège #6 (déjà en place) : une capacité 100% HKLM n'est même PAS
        // listée sur cette surface (assignabilité HKCU) — a fortiori pas de badge.
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('machine_only_cap', [
            'keys' => [['hive' => 'HKLM', 'path' => 'SOFTWARE\\Z', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
        ]);

        Livewire::test(self::COMPONENT, ['groupId' => $group->id])
            ->assertDontSeeHtml('data-testid="effect-timing-'.$cap->id.'"')
            ->assertDontSee('Machine only cap');
    }

    #[Test]
    public function an_explorer_restart_hint_shows_the_restart_wording(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('restart_cap', [
            'keys' => [['hive' => 'HKCU', 'path' => 'Software\\R', 'name' => 'K', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]]],
            'refresh' => 'explorer_restart',
        ]);

        Livewire::test(self::COMPONENT, ['groupId' => $group->id])
            ->assertSeeHtml('data-testid="effect-timing-'.$cap->id.'"')
            ->assertSeeHtml('Immédiat (le bureau redémarre)');
    }
}
