<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Parc;

use App\Models\Capability;
use App\Models\CapabilityOverrideAuditLog;
use App\Models\CapabilityProjection;
use App\Models\ControlHubContractItem;
use App\Models\User;
use App\Models\WorkstationGroup;
use App\Observers\WorkstationGroupObserver;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Story 29.6 — Scoping de `app.customize` par WorkstationGroup sur l'onglet
 * « Options / Capacités » (override de capacité par parc) + figement de `groupId`.
 *
 * Couvre :
 *  - AC #1 (décision d'autorisation, niveau guard) : un délégué POSITIF de A
 *    accède au composant monté `groupId=A` (mount OK), mais est REFUSÉ (403, sans
 *    écriture ni trace) sur `groupId=B`. La décision scopée du guard est aussi
 *    prouvée à l'unité (WorkstationGroupPolicyCustomizeTest) ;
 *  - AC #1/#3 (write-through bout-en-bout) avec l'acteur de la menace M4 : un
 *    refnum disposant du droit GLOBAL `app.customize` ET d'une exclusion NÉGATIVE
 *    sur B écrit sur A mais est refusé sur B (sans écriture ni trace) ;
 *  - AC #2 : admin global autorisé sur A et B (fallback préservé) ;
 *  - AC #6 : `groupId` `#[Locked]` → tampering client → CannotUpdateLockedPropertyException ;
 *  - non-régression 29.2 (verrou amont) : un acteur AUTORISÉ par périmètre reste
 *    bloqué SERVEUR par `authorizeUpstream` sur une capacité verrouillée amont ;
 *  - non-régression 29.5 (audit) : une écriture autorisée trace acteur + périmètre.
 *
 * ⚠️ Interaction 29.2 (constat de dev, hors-scope 29.6) : `CapabilityPolicy::modify`
 * (gate `modify-capability`, appelé par `authorizeUpstream`) exige le droit GLOBAL
 * `app.customize` comme PLANCHER. Un délégué POSITIF-seul passe donc le guard
 * d'accès (mount/openAdd) mais ne peut PAS finaliser une écriture tant que ce
 * plancher reste global. Le write-through est donc prouvé avec l'acteur de la
 * menace M4 (droit global scopé par exclusion négative) — voir Dev Agent Record.
 *
 * Délégation = système EXISTANT (PermissionService) sur la permission Spatie
 * `app.customize` (aucune nouvelle permission). Parcs PHYSIQUES (la voie déléguée
 * n'opère que sur `is_physical = true`).
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`.
 */
class CapabilitiesTabCustomizeScopingTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'pages::parc.groups._partials.capabilities-tab';

    private WorkstationGroup $parcA;

    private WorkstationGroup $parcB;

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

        Permission::firstOrCreate(['name' => 'app.customize', 'guard_name' => 'web']);

        // Parcs PHYSIQUES : la délégation scopée n'opère que sur is_physical=true.
        $this->parcA = WorkstationGroup::factory()->create(['name' => 'Salle A', 'is_physical' => true]);
        $this->parcB = WorkstationGroup::factory()->create(['name' => 'Salle B', 'is_physical' => true]);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    /** Refnum avec une délégation POSITIVE de A (aucun droit global). */
    private function positiveDelegateOfA(string $login = 'refnum_a'): User
    {
        $user = User::factory()->create(['login' => $login]);
        app(PermissionService::class)->grantDelegation($user, 'app.customize', $this->parcA);
        $this->actingAs($user);

        return $user;
    }

    /**
     * Acteur de la menace M4 : droit GLOBAL `app.customize` (passe le plancher
     * `modify-capability` de 29.2) SCOPÉ par une exclusion NÉGATIVE sur B.
     */
    private function scopedCustomizerDeniedOnB(string $login = 'refnum_scoped'): User
    {
        $user = User::factory()->create(['login' => $login]);
        $user->givePermissionTo('app.customize');
        app(PermissionService::class)->negateDelegation($user, 'app.customize', $this->parcB);
        $this->actingAs($user);

        return $user;
    }

    /** Admin disposant du droit GLOBAL `app.customize` (aucune exclusion). */
    private function globalAdmin(string $login = 'admin'): User
    {
        $user = User::factory()->create(['login' => $login]);
        $user->givePermissionTo('app.customize');
        $this->actingAs($user);

        return $user;
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

    // ── AC #1 — décision d'autorisation scopée (niveau guard) ─────────────

    #[Test]
    public function positive_delegate_of_a_is_granted_access_on_a(): void
    {
        // Avant 29.6, guardCustomize vérifiait `app.customize` GLOBALEMENT : un
        // délégué positif-seul aurait été refusé (403) même sur SA salle. Après
        // 29.6, le guard scopé reconnaît la délégation → accès au composant de A.
        $this->positiveDelegateOfA();

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcA->id])
            ->assertStatus(200);
    }

    #[Test]
    public function positive_delegate_of_a_is_forbidden_on_b_without_write_or_audit_trace(): void
    {
        $this->positiveDelegateOfA();

        // Composant monté sur B refusé DÈS le mount (guardCustomize scopé).
        Livewire::test(self::COMPONENT, ['groupId' => $this->parcB->id])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('capability_assignments')->count(), 'aucune écriture hors-périmètre');
        $this->assertSame(0, CapabilityOverrideAuditLog::query()->count(), 'aucune trace d\'audit hors-périmètre');
    }

    // ── AC #1/#3 — write-through scopé (acteur menace M4) ──────────────────

    #[Test]
    public function scoped_customizer_can_save_override_on_a(): void
    {
        $this->scopedCustomizerDeniedOnB();
        $cap = $this->capabilityWithKey('show_hidden_files', 'HKCU', 'Software\\SHF', 'Hidden');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcA->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parcA->id,
            'value' => 'off',
        ]);
    }

    #[Test]
    public function scoped_customizer_can_open_edit_on_a(): void
    {
        // [review 29.6 P2] openEdit est listé par l'AC#1 parmi les mutations gardées :
        // on prouve qu'il passe pour l'acteur autorisé sur SON parc (même guard scopé
        // qu'openAdd, mais couverture explicite de la mutation).
        $this->scopedCustomizerDeniedOnB();
        $cap = $this->capabilityWithKey('show_clock', 'HKCU', 'Software\\Clock', 'Show');

        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parcA->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcA->id])
            ->call('openEdit', $cap->id)
            ->assertSet('isEditing', true)
            ->assertSet('editingCapabilityId', $cap->id);
    }

    #[Test]
    public function scoped_customizer_can_remove_override_on_a(): void
    {
        $this->scopedCustomizerDeniedOnB();
        $cap = $this->capabilityWithKey('show_extensions', 'HKCU', 'Software\\SFE', 'Show');

        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parcA->id,
            'value' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcA->id])
            ->call('removeOverride', $cap->id);

        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parcA->id,
        ]);
    }

    #[Test]
    public function scoped_customizer_is_forbidden_on_b_without_write_or_audit_trace(): void
    {
        $this->scopedCustomizerDeniedOnB();

        // Exclusion négative active sur B → guardCustomize 403 même avec le droit
        // global (le scoped guard honore la négative, contrairement à l'ancien
        // contrôle global aveugle — fix M4).
        Livewire::test(self::COMPONENT, ['groupId' => $this->parcB->id])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('capability_assignments')->count(), 'aucune écriture hors-périmètre');
        $this->assertSame(0, CapabilityOverrideAuditLog::query()->count(), 'aucune trace d\'audit hors-périmètre');
    }

    // ── AC #2 — admin global : autorisé partout (fallback préservé) ────────

    #[Test]
    public function global_admin_can_save_override_on_a_and_b(): void
    {
        $this->globalAdmin();
        $capA = $this->capabilityWithKey('cap_a', 'HKCU', 'Software\\A', 'V');
        $capB = $this->capabilityWithKey('cap_b', 'HKCU', 'Software\\B', 'V');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcA->id])
            ->call('openAdd', $capA->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcB->id])
            ->call('openAdd', $capB->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $capA->id,
            'assignable_id' => $this->parcA->id,
        ]);
        $this->assertDatabaseHas('capability_assignments', [
            'capability_id' => $capB->id,
            'assignable_id' => $this->parcB->id,
        ]);
    }

    // ── AC #6 — #[Locked] : tampering de groupId rejeté ───────────────────

    #[Test]
    public function tampering_group_id_throws_locked_property_exception(): void
    {
        // Monté légitimement sur A (admin global pour isoler le test du scoping).
        $this->globalAdmin();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcA->id])
            ->set('groupId', $this->parcB->id);
    }

    // ── Non-régression 29.2 — verrou amont prévaut même pour un acteur autorisé ─

    #[Test]
    public function authorized_actor_is_still_blocked_by_upstream_lock(): void
    {
        $this->globalAdmin();
        $cap = $this->capabilityWithKey('locked_cap', 'HKCU', 'Software\\LK', 'V');

        // Item amont VERROUILLÉ (factory défaut = Locked) matchant la clé.
        ControlHubContractItem::factory()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => 'HKCU|Software\\LK|V|REG_DWORD',
        ]);

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcA->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        // Le scope autorise, mais authorizeUpstream (29.2) refuse → aucune écriture.
        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parcA->id,
        ]);
    }

    // ── Non-régression 29.5 — audit tracé fidèlement sur écriture autorisée ─

    #[Test]
    public function authorized_save_is_audited_with_actor_and_scope(): void
    {
        $user = $this->scopedCustomizerDeniedOnB();
        $cap = $this->capabilityWithKey('audited_cap', 'HKCU', 'Software\\AU', 'V');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parcA->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'action' => 'create',
            'actor_user_id' => $user->id,
            'actor_login' => $user->login,
            'capability_id' => $cap->id,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parcA->id,
            'scope_label' => 'Salle A',
            'new_value' => 'off',
        ]);
    }
}
