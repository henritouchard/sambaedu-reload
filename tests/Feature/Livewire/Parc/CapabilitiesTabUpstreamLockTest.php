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
 * Story 29.2 (AC #1, #5, #6) — verrou amont sur l'onglet « Options / Capacités »
 * d'un WorkstationGroup (override PAR PARC).
 *
 * Une capacité verrouillée amont → `saveOverride`/`openAdd`/`removeOverride`
 * refusés SERVEUR (aucune écriture `capability_assignments`) + non proposée à
 * l'ajout. Une capacité non verrouillée → écrit normalement (non-régression 27.12).
 */
class CapabilitiesTabUpstreamLockTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups._partials.capabilities-tab';

    private WorkstationGroup $parc;

    protected function setUp(): void
    {
        parent::setUp();
        if (! config('app.key')) {
            config(['app.key' => 'base64:' . base64_encode(random_bytes(32))]);
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

    private function lockUpstream(string $hive, string $path, string $name): void
    {
        ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => "{$hive}|{$path}|{$name}|REG_DWORD",
        ]);
    }

    #[Test]
    public function save_override_is_blocked_for_upstream_locked_capability(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('remote_desktop_enabled', 'HKCU', 'Software\\RD', 'Enabled');
        $this->lockUpstream('HKCU', 'Software\\RD', 'Enabled');

        // Contournement de l'UI : on pose directement l'id puis on save.
        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->set('editingCapabilityId', $cap->id)
            ->set('isEditing', false)
            ->set('formValue', 'off')
            ->call('saveOverride');

        // AC #1 : « message explicite, pas un échec silencieux » — le toast d'erreur
        // de verrou amont DOIT être émis (preuve que le refus n'est pas silencieux).
        $component->assertDispatched('toastMagic', fn ($event, $params): bool => ($params['status'] ?? null) === 'error');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
        ]);
    }

    #[Test]
    public function upstream_locked_capability_is_not_addable(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('show_file_extensions', 'HKCU', 'Software\\SFE', 'Show');
        $this->lockUpstream('HKCU', 'Software\\SFE', 'Show');

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $addableIds = array_column($component->instance()->addableCapabilities(), 'id');
        self::assertNotContains($cap->id, $addableIds, 'une capacité verrouillée amont n\'est pas proposée à l\'ajout');
    }

    #[Test]
    public function remove_override_is_blocked_for_upstream_locked_capability(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('uac_enabled', 'HKLM', 'Software\\UAC', 'EnableLUA');
        // Une ligne d'override préexiste (cas réel : verrou posé après coup).
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->lockUpstream('HKLM', 'Software\\UAC', 'EnableLUA');

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('removeOverride', $cap->id);

        // AC #1 : refus explicite (toast), pas silencieux.
        $component->assertDispatched('toastMagic', fn ($event, $params): bool => ($params['status'] ?? null) === 'error');

        // L'override n'est PAS retiré (refus explicite, le refnum ne touche pas un
        // item verrouillé).
        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
        ]);
    }

    #[Test]
    public function non_locked_capability_still_writes_non_regression(): void
    {
        $this->actAsCustomizer();
        $cap = $this->capabilityWithKey('show_hidden_files', 'HKCU', 'Software\\SHF', 'Hidden');
        // Pas de verrou amont sur cette clé.

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
        ]);
    }
}
