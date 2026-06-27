<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContractItem;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 29.3 (AC #3 contrepoint) — un item amont `permissive` NE verrouille PAS
 * l'override par parc : l'écriture/retrait `capability_assignments` reste
 * autorisée (le gate 29.2 ne refuse QUE `locked`). C'est le pendant côté ÉCRITURE
 * de la relaxation : 29.3 fait MORDRE l'override au compilé, et confirme ici qu'il
 * peut être POSÉ. Le refus du `locked` reste couvert par
 * {@see CapabilitiesTabUpstreamLockTest} (non-régression).
 */
class CapabilitiesTabPermissiveOverrideTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups._partials.capabilities-tab';

    private WorkstationGroup $parc;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        }
        $this->withoutVite();
        WorkstationGroupObserver::disableSync();

        DB::table('capability_assignments')->delete();
        DB::table('capability_projections')->delete();
        DB::table('capabilities')->delete();

        $this->parc = WorkstationGroup::factory()->logical()->create();
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        Mockery::close();
        parent::tearDown();
    }

    private function actAsCustomizer(): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('can')->with('app.customize')->andReturn(true);
        $user->shouldReceive('can')->andReturn(true);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);
    }

    private function capabilityWithKey(string $key, string $hive, string $path, string $name): Capability
    {
        $cap = Capability::factory()->create([
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'default_value' => 'on',
        ]);
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => $hive, 'path' => $path, 'name' => $name, 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        return $cap;
    }

    private function permissiveUpstream(string $hive, string $path, string $name): void
    {
        ControlHubContractItem::factory()->permissive()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => "{$hive}|{$path}|{$name}|REG_DWORD",
        ]);
    }

    #[Test]
    public function save_override_is_allowed_for_an_upstream_permissive_capability(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('remote_desktop_enabled', 'HKCU', 'Software\\RD', 'Enabled');
        $this->permissiveUpstream('HKCU', 'Software\\RD', 'Enabled');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        // L'override est ÉCRIT (permissif n'est PAS un verrou — FR4).
        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
        ]);
    }

    #[Test]
    public function permissive_capability_is_addable(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('show_file_extensions', 'HKCU', 'Software\\SFE', 'Show');
        $this->permissiveUpstream('HKCU', 'Software\\SFE', 'Show');

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $addableIds = array_column($component->instance()->addableCapabilities(), 'id');
        self::assertContains($cap->id, $addableIds, 'une capacité permissive amont reste proposée à l\'ajout (pas un verrou)');
    }

    #[Test]
    public function remove_override_is_allowed_for_an_upstream_permissive_capability(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('uac_enabled', 'HKLM', 'Software\\UAC', 'EnableLUA');
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->permissiveUpstream('HKLM', 'Software\\UAC', 'EnableLUA');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('removeOverride', $cap->id);

        // Le retrait est AUTORISÉ : le refnum reprend la baseline amont/défaut (FR4).
        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
        ]);
    }
}
