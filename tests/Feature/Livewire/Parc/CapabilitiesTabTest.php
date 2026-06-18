<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.12 (AC6, AC9) — onglet « Options / Capacités » d'un WorkstationGroup.
 *
 * Édite les OVERRIDES de VALEUR DE CAPACITÉ par parc (capability_assignments).
 * Couvre : n'affiche que les overrides, ajout/édition/retrait, validation serveur,
 * confirmation du warning, Gate app.customize.
 */
class CapabilitiesTabTest extends TestCase
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

    /** Authentifie un user avec/ sans `app.customize`. */
    private function actAs(bool $canCustomize): void
    {
        $user = Mockery::mock(
            \Illuminate\Contracts\Auth\Authenticatable::class,
            \Illuminate\Contracts\Auth\Access\Authorizable::class,
        );
        $user->shouldReceive('can')->with('app.customize')->andReturn($canCustomize);
        $user->shouldReceive('can')->andReturn($canCustomize);
        $user->shouldReceive('getAuthIdentifier')->andReturn(1);
        $user->shouldReceive('getAuthIdentifierName')->andReturn('id');
        $user->shouldReceive('getAuthPassword')->andReturn('');
        $user->shouldReceive('getRememberToken')->andReturn('');
        $user->shouldReceive('setRememberToken');
        $user->shouldReceive('getRememberTokenName')->andReturn('');
        $this->actingAs($user);
    }

    private function makeToggleCapability(string $key, string $default = 'on', ?string $warning = null): Capability
    {
        $cap = Capability::factory()->create([
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'default_value' => $default,
            'warning' => $warning,
        ]);
        CapabilityProjection::factory()->for($cap)->create();

        return $cap;
    }

    #[Test]
    public function gate_blocks_mount_without_app_customize(): void
    {
        // Livewire convertit l'abort(403) du mount() en réponse HTTP 403.
        $this->actAs(canCustomize: false);

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->assertStatus(403);
    }

    #[Test]
    public function tab_lists_only_overrides_not_the_whole_catalog(): void
    {
        $this->actAs(canCustomize: true);

        $overridden = $this->makeToggleCapability('show_file_extensions');
        $notOverridden = $this->makeToggleCapability('show_hidden_files');

        DB::table('capability_assignments')->insert([
            'capability_id' => $overridden->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $overrides = $component->instance()->overrides();
        self::assertCount(1, $overrides, 'seul l\'override est listé');
        self::assertSame($overridden->id, $overrides[0]['id']);

        $addable = $component->instance()->addableCapabilities();
        $addableIds = array_column($addable, 'id');
        self::assertContains($notOverridden->id, $addableIds);
        self::assertNotContains($overridden->id, $addableIds, 'une capacité déjà overridée n\'est plus proposée à l\'ajout');
    }

    #[Test]
    public function add_override_persists_value_on_pivot(): void
    {
        $this->actAs(canCustomize: true);
        $cap = $this->makeToggleCapability('remote_desktop_enabled');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
        ]);
    }

    #[Test]
    public function invalid_value_is_rejected_by_server_validation(): void
    {
        $this->actAs(canCustomize: true);
        $cap = $this->makeToggleCapability('show_file_extensions');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'not-a-valid-option')
            ->call('saveOverride')
            ->assertHasErrors('formValue');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'value' => 'not-a-valid-option',
        ]);
    }

    #[Test]
    public function warning_capability_requires_acknowledgement(): void
    {
        $this->actAs(canCustomize: true);
        $cap = $this->makeToggleCapability('uac_enabled', warning: 'Implications de sécurité.');

        // Sans confirmation → erreur, rien persisté.
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride')
            ->assertHasErrors('warningAcknowledged');

        $this->assertDatabaseMissing('capability_assignments', ['capability_id' => $cap->id]);

        // Avec confirmation → persisté.
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->set('warningAcknowledged', true)
            ->call('saveOverride');

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'value' => 'off',
        ]);
    }

    #[Test]
    public function remove_override_returns_to_default(): void
    {
        $this->actAs(canCustomize: true);
        $cap = $this->makeToggleCapability('show_hidden_files');

        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('removeOverride', $cap->id);

        // « Retirer » = supprimer la ligne (revenir au défaut), PAS un tombstone.
        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
        ]);
    }

    #[Test]
    public function locked_capability_is_not_addable(): void
    {
        $this->actAs(canCustomize: true);
        $cap = $this->makeToggleCapability('show_file_extensions');
        Capability::query()->whereKey($cap->id)->update(['overrides_locked' => true]);

        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id]);

        $addableIds = array_column($component->instance()->addableCapabilities(), 'id');
        self::assertNotContains($cap->id, $addableIds, 'une capacité gelée n\'est pas proposée à l\'ajout');
    }

    /**
     * Garde serveur (#1/#7) : un client peut muter directement `editingCapabilityId`
     * (propriété publique) et appeler `saveOverride` sans passer par `openAdd()`.
     * Le garde front (addableCapabilities) ne suffit pas — `saveOverride()` doit
     * refuser un NOUVEL override sur une capacité gelée.
     */
    #[Test]
    public function save_override_is_blocked_for_locked_capability_via_direct_call(): void
    {
        $this->actAs(canCustomize: true);
        $cap = $this->makeToggleCapability('show_file_extensions');
        Capability::query()->whereKey($cap->id)->update(['overrides_locked' => true]);

        // Bypass du front : on pose directement l'id (comme $wire.set) puis on save.
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->set('editingCapabilityId', $cap->id)
            ->set('isEditing', false)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
        ]);
    }

    /**
     * Garde serveur (#7) : aucun override ne doit être écrit sur une capacité
     * INACTIVE (la computed `editingCapability` ne filtrait pas `is_active`).
     */
    #[Test]
    public function save_override_is_blocked_for_inactive_capability(): void
    {
        $this->actAs(canCustomize: true);
        $cap = $this->makeToggleCapability('show_file_extensions');
        Capability::query()->whereKey($cap->id)->update(['is_active' => false]);

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->set('editingCapabilityId', $cap->id)
            ->set('isEditing', false)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
        ]);
    }
}
