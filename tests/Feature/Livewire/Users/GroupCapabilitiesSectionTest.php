<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Users;

use App\Models\Capability;
use App\Models\CapabilityOverrideAuditLog;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContractItem;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 35.4 — Section « Capacités » de la page d'un GROUPE D'UTILISATEURS
 * (override de capacité par UserGroup). Transposition disciplinée des tests de la
 * surface parc (`CapabilitiesOverrideAuditTest` / `CapabilitiesTabCustomizeScopingTest`)
 * à la maille UserGroup.
 *
 * Couvre : listing (assignabilité HKCU + « Suit le défaut »), pose/édition/retrait,
 * préservation `created_at`, validation (options + warning), gel `overrides_locked`,
 * audit `capability_override_audit_logs`, scoping (403 sans droit global + refus du
 * délégué par-salle, anti-piège 29.1) et figement `#[Locked]`.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase` (comme la surface parc : les
 * tables `capabilities`/`capability_projections`/`capability_assignments` +
 * `capability_override_audit_logs` sont créées par les migrations, jamais par
 * `CreatesPermissionSchema`). Observers AD désactivés.
 */
class GroupCapabilitiesSectionTest extends TestCase
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

        // Table de seed vidée : on maîtrise entièrement les capacités des tests.
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

    // ── Helpers ───────────────────────────────────────────────────────────

    private function componentPath(): string
    {
        return self::COMPONENT;
    }

    /** Admin détenant le droit GLOBAL Spatie `app.customize`. */
    private function makeAdmin(string $login = 'admin'): User
    {
        $u = User::factory()->create(['login' => $login]);
        $u->givePermissionTo('app.customize');
        $this->actingAs($u);

        return $u;
    }

    private function makeGroup(string $name = 'classe-6a', string $type = 'classe', ?string $displayName = 'Classe 6A'): UserGroup
    {
        return UserGroup::create(['name' => $name, 'type' => $type, 'display_name' => $displayName]);
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function makeCapability(string $key, string $hive = 'HKCU', array $attrs = []): Capability
    {
        $cap = Capability::factory()->create(array_merge([
            'key' => $key,
            'label' => ucfirst(str_replace('_', ' ', $key)),
            'default_value' => 'off',
        ], $attrs));

        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => $hive, 'path' => 'Software\\'.$key, 'name' => 'V', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        return $cap;
    }

    private function insertOverride(Capability $cap, UserGroup $group, string $value): void
    {
        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => $value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── AC1 — listing : assignabilité HKCU + « Suit le défaut » ────────────

    #[Test]
    public function it_lists_assignable_hkcu_capabilities_with_follows_default(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();

        $this->makeCapability('hkcu_active', 'HKCU');       // assignable
        $this->makeCapability('hklm_machine', 'HKLM');      // machine-only → inerte → exclue
        $this->makeCapability('hkcu_inactive', 'HKCU', ['is_active' => false]); // inactive → exclue

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertStatus(200)
            ->assertSee('Hkcu active')
            ->assertSee('Suit le défaut')
            // libellé d'option du défaut (`off` → « Désactivé »).
            ->assertSee('Désactivé')
            ->assertDontSee('Hklm machine')
            ->assertDontSee('Hkcu inactive');
    }

    #[Test]
    public function it_shows_the_override_value_when_one_exists(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_over', 'HKCU');
        $this->insertOverride($cap, $group, 'on');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSee('Activé')          // valeur d'override (on → Activé)
            ->assertSee('edit-override-'.$cap->id, escape: false)
            ->assertSee('remove-override-'.$cap->id, escape: false);
    }

    // ── AC1 — pose d'un override ───────────────────────────────────────────

    #[Test]
    public function it_saves_a_new_override_on_the_user_group(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('registry_editing_disabled', 'HKCU');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'on')
            ->call('saveOverride')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'on',
        ]);
    }

    // ── AC1 / 29.7 — édition : valeur mise à jour, created_at préservé ─────

    #[Test]
    public function it_updates_an_override_and_preserves_created_at(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_edit', 'HKCU');
        $this->insertOverride($cap, $group, 'on');

        $frozenCreatedAt = now()->subDays(3)->toDateTimeString();
        $frozenUpdatedAt = now()->subDays(2)->toDateTimeString();
        DB::table('capability_assignments')
            ->where('capability_id', $cap->id)
            ->where('assignable_type', UserGroup::class)
            ->where('assignable_id', $group->id)
            ->update(['created_at' => $frozenCreatedAt, 'updated_at' => $frozenUpdatedAt]);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openEdit', $cap->id)
            ->assertSet('isEditing', true)
            ->set('formValue', 'off')
            ->call('saveOverride')
            ->assertHasNoErrors();

        $row = DB::table('capability_assignments')
            ->where('capability_id', $cap->id)
            ->where('assignable_type', UserGroup::class)
            ->where('assignable_id', $group->id)
            ->first();

        self::assertSame('off', $row->value);
        self::assertSame($frozenCreatedAt, $row->created_at, 'UPDATE ne réécrit PAS created_at (29.7)');
        self::assertGreaterThan($frozenUpdatedAt, $row->updated_at, 'UPDATE fait avancer updated_at');
    }

    // ── AC1 — retrait : pivot supprimé + pas de trace fantôme ─────────────

    #[Test]
    public function it_removes_an_override_and_returns_to_default(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_remove', 'HKCU');
        $this->insertOverride($cap, $group, 'on');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('removeOverride', $cap->id);

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
        ]);
    }

    #[Test]
    public function removing_a_non_existent_override_writes_no_audit(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_ghost', 'HKCU');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('removeOverride', $cap->id);

        self::assertSame(0, CapabilityOverrideAuditLog::query()->count(), 'aucun acte → aucune trace fantôme');
    }

    // ── AC1 — validation serveur ───────────────────────────────────────────

    #[Test]
    public function it_rejects_a_value_outside_the_options(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_valid', 'HKCU');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'bogus')
            ->call('saveOverride')
            ->assertHasErrors('formValue');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $group->id,
        ]);
    }

    #[Test]
    public function it_rejects_persistence_when_warning_is_not_acknowledged(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_warn', 'HKCU', ['warning' => 'Réglage sensible.']);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'on')
            ->call('saveOverride')
            ->assertHasErrors('warningAcknowledged');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $group->id,
        ]);
    }

    #[Test]
    public function it_persists_when_warning_is_acknowledged(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_warn_ok', 'HKCU', ['warning' => 'Réglage sensible.']);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'on')
            ->set('warningAcknowledged', true)
            ->call('saveOverride')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $group->id,
            'value' => 'on',
        ]);
    }

    // ── AC2 — overrides_locked ─────────────────────────────────────────────

    #[Test]
    public function it_refuses_a_new_override_on_a_frozen_capability(): void
    {
        // Le bouton « Dévier » n'est pas rendu pour une capacité gelée sans override ;
        // on prouve la garde SERVEUR (rejeu Livewire direct) : saveOverride refuse un
        // NOUVEL override (dérivé de l'existence en base), toast + zéro écriture.
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_frozen', 'HKCU', ['overrides_locked' => true]);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->set('editingCapabilityId', $cap->id)
            ->set('formValue', 'on')
            ->call('saveOverride')
            ->assertDispatched('toastMagic', fn ($event, $params): bool => ($params['status'] ?? null) === 'error'
                && str_contains($params['message'] ?? '', 'gelée'));

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $group->id,
        ]);
    }

    #[Test]
    public function an_existing_override_on_a_frozen_capability_stays_editable(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('hkcu_frozen_over', 'HKCU', ['overrides_locked' => true]);
        $this->insertOverride($cap, $group, 'on');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openEdit', $cap->id)
            ->assertSet('isEditing', true)
            ->set('formValue', 'off')
            ->call('saveOverride')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $group->id,
            'value' => 'off',
        ]);
    }

    // ── AC3 — audit create / update / delete ───────────────────────────────

    #[Test]
    public function saving_a_new_override_logs_a_create_event(): void
    {
        $user = $this->makeAdmin();
        $group = $this->makeGroup(displayName: 'Direction');
        $cap = $this->makeCapability('outlook_disable', 'HKCU');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'on')
            ->call('saveOverride');

        self::assertSame(1, CapabilityOverrideAuditLog::query()->count());
        $this->assertDatabaseHas('capability_override_audit_logs', [
            'action' => 'create',
            'actor_user_id' => $user->id,
            'actor_login' => $user->login,
            'capability_id' => $cap->id,
            'capability_label' => $cap->label,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'scope_label' => 'Direction',
            'old_value' => null,
            'new_value' => 'on',
            'upstream_status' => 'local',
        ]);
    }

    #[Test]
    public function re_saving_logs_update_with_previous_value_as_old(): void
    {
        $user = $this->makeAdmin();
        $group = $this->makeGroup(displayName: 'Vie scolaire');
        $cap = $this->makeCapability('hkcu_upd', 'HKCU');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $cap->id)->set('formValue', 'on')->call('saveOverride');
        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openEdit', $cap->id)->set('formValue', 'off')->call('saveOverride');

        $update = CapabilityOverrideAuditLog::query()->where('action', 'update')->first();
        self::assertNotNull($update);
        self::assertSame('on', $update->old_value);
        self::assertSame('off', $update->new_value);
        self::assertSame('Vie scolaire', $update->scope_label);
        self::assertSame(UserGroup::class, $update->assignable_type);
        self::assertSame($user->id, $update->actor_user_id);
    }

    #[Test]
    public function removing_logs_a_delete_event_with_null_new_value(): void
    {
        $user = $this->makeAdmin();
        $group = $this->makeGroup(displayName: 'Élèves 6A');
        $cap = $this->makeCapability('hkcu_del', 'HKCU');
        $this->insertOverride($cap, $group, 'on');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('removeOverride', $cap->id);

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'action' => 'delete',
            'actor_user_id' => $user->id,
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'scope_label' => 'Élèves 6A',
            'old_value' => 'on',
            'new_value' => null,
            'upstream_status' => 'local',
        ]);
    }

    #[Test]
    public function scope_label_falls_back_to_name_when_display_name_is_null(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup(name: 'bare-group', displayName: null);
        $cap = $this->makeCapability('hkcu_bare', 'HKCU');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $cap->id)->set('formValue', 'on')->call('saveOverride');

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'action' => 'create',
            'assignable_id' => $group->id,
            'scope_label' => 'bare-group',
        ]);
    }

    // ── AC4 — scoping : gate instance-wide, pas de fuite délégation ────────

    #[Test]
    public function it_forbids_a_user_without_global_customize(): void
    {
        $u = User::factory()->create(['login' => 'no-right']);
        $this->actingAs($u);
        $group = $this->makeGroup();

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertStatus(403);
    }

    #[Test]
    public function it_forbids_a_room_scoped_delegate_only(): void
    {
        // Anti-piège 29.1 (inversé) : un refnum ne détenant `app.customize` QUE par
        // délégation scopée sur une salle (aucun droit GLOBAL) est REFUSÉ — la
        // délégation par-salle ne fuite pas sur les groupes d'utilisateurs.
        $salle = WorkstationGroup::factory()->create(['is_physical' => true]);
        $u = User::factory()->create(['login' => 'room-delegate']);
        app(PermissionService::class)->grantDelegation($u, 'app.customize', $salle);
        $this->actingAs($u);
        $group = $this->makeGroup();

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertStatus(403);

        self::assertSame(0, DB::table('capability_assignments')->count(), 'aucune écriture hors-périmètre');
    }

    #[Test]
    public function tampering_group_id_throws_locked_property_exception(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $other = $this->makeGroup(name: 'other-group', displayName: 'Autre');

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->set('groupId', $other->id);
    }

    // ── Review 35.4 #1 (piège #6) — l'assignabilité HKCU est re-validée SERVEUR ─

    #[Test]
    public function machine_only_capability_cannot_receive_an_override_via_direct_livewire_calls(): void
    {
        // Rejeu Livewire direct (le bouton n'existe pas dans l'UI, mais les
        // actions restent appelables) : openAdd refuse, et saveOverride refuse
        // même en poussant editingCapabilityId à la main.
        $this->makeAdmin();
        $group = $this->makeGroup();
        $hklm = $this->makeCapability('hklm_only', 'HKLM');

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $hklm->id)
            ->assertSet('showOverrideModal', false)
            ->set('editingCapabilityId', $hklm->id)
            ->set('formValue', 'on')
            ->call('saveOverride');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $hklm->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
        ]);
    }

    // ── Review 35.4 #3 — bi-projection : ≥1 clé HKCU parmi plusieurs suffit ─

    #[Test]
    public function mixed_hive_capability_is_listed_as_assignable(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();

        $cap = Capability::factory()->create([
            'key' => 'mixed_hives',
            'label' => 'Mixed hives',
            'default_value' => 'off',
        ]);
        CapabilityProjection::factory()->for($cap)->keys([
            ['hive' => 'HKLM', 'path' => 'Software\\MixedM', 'name' => 'V', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
            ['hive' => 'HKCU', 'path' => 'Software\\MixedU', 'name' => 'V', 'type' => 'REG_DWORD', 'value' => ['on' => 1, 'off' => 0]],
        ])->create();

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->assertSee('Mixed hives');
    }

    // ── Review 35.4 #2 / AC5.4 — verrou amont refusé à l'écriture (29.2) ────

    #[Test]
    public function save_override_is_blocked_by_an_upstream_lock(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('locked_upstream', 'HKCU');

        // Item amont VERROUILLÉ (factory défaut = Locked) matchant la clé de la
        // projection (patron CapabilitiesTabCustomizeScopingTest).
        ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\locked_upstream|V|REG_DWORD',
        ]);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'on')
            ->call('saveOverride');

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
        ]);
    }

    #[Test]
    public function remove_override_is_blocked_by_an_upstream_lock(): void
    {
        $this->makeAdmin();
        $group = $this->makeGroup();
        $cap = $this->makeCapability('locked_removal', 'HKCU');
        $this->insertOverride($cap, $group, 'on');

        ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\locked_removal|V|REG_DWORD',
        ]);

        Livewire::test($this->componentPath(), ['groupId' => $group->id])
            ->call('removeOverride', $cap->id);

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_type' => UserGroup::class,
            'assignable_id' => $group->id,
            'value' => 'on',
        ]);
    }
}
