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
use App\Services\ControlHub\UpstreamLockResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Throwable;

/**
 * Story 29.5 (NFR5) — Audit append-only des overrides de capacité par parc.
 *
 * Couvre : `create`/`update`/`delete` (acteur/item/périmètre/old-new/statut/
 * horodatage) ; tag `permissive` vs `local` (AC#5) ; standalone → audit `local` +
 * 0 requête `controlhub_contract_items` (court-circuit NFR3, AC#7) ; atomicité
 * acte ↔ trace (échec d'audit → override NON persisté, rollback, AC#6).
 *
 * ⚠️ Contrairement aux tests sœurs 29.2/29.4 (mock Authenticatable pour éviter le
 * before-hook Spatie), ce test a besoin d'un VRAI {@see User} authentifié pour
 * prouver la capture acteur (id + login) et satisfaire la FK `actor_user_id`. On
 * seed donc la permission `app.customize` et on l'accorde à l'utilisateur.
 *
 * Tests HÔTE (php8.4 + pdo_sqlite), `RefreshDatabase`.
 */
class CapabilitiesOverrideAuditTest extends TestCase
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

        Permission::firstOrCreate(['name' => 'app.customize', 'guard_name' => 'web']);

        $this->parc = WorkstationGroup::factory()->logical()->create(['name' => 'Salle Info 1']);
    }

    protected function tearDown(): void
    {
        WorkstationGroupObserver::enableSync();
        parent::tearDown();
    }

    private function actAsRefnum(string $login = 'refnum01'): User
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

    private function permissiveUpstream(string $hive, string $path, string $name): void
    {
        ControlHubContractItem::factory()->permissive()->create([
            'type' => CapabilityProjection::MECHANISM_REGISTRY,
            'key' => "{$hive}|{$path}|{$name}|REG_DWORD",
        ]);
    }

    // ── AC#3 — create ─────────────────────────────────────────────────────

    #[Test]
    public function saving_a_new_override_logs_a_single_create_event(): void
    {
        $user = $this->actAsRefnum();
        $cap = $this->capabilityWithKey('show_hidden_files', 'HKCU', 'Software\\SHF', 'Hidden');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        self::assertSame(1, CapabilityOverrideAuditLog::query()->count(), 'un et un seul événement');

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'action' => 'create',
            'actor_user_id' => $user->id,
            'actor_login' => $user->login,
            'capability_id' => $cap->id,
            'capability_label' => $cap->label,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'scope_label' => 'Salle Info 1',
            'old_value' => null,
            'new_value' => 'off',
            'upstream_status' => 'local',
        ]);
    }

    // ── AC#3 — update (action dérivée de l'existence, old_value avant mutation) ─

    #[Test]
    public function re_saving_an_override_logs_update_with_previous_value_as_old(): void
    {
        $user = $this->actAsRefnum();
        $cap = $this->capabilityWithKey('show_file_extensions', 'HKCU', 'Software\\SFE', 'Show');

        // 1er override (create).
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'on')
            ->call('saveOverride');

        // 2e override (update) sur la même capacité.
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openEdit', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $update = CapabilityOverrideAuditLog::query()->where('action', 'update')->first();
        self::assertNotNull($update, 'l\'action update est dérivée de l\'existence en base (pas du flag client)');
        self::assertSame('on', $update->old_value, 'old_value = valeur précédente (lue avant la mutation)');
        self::assertSame('off', $update->new_value);
        self::assertSame(1, CapabilityOverrideAuditLog::query()->where('action', 'create')->count());

        // Review #2 — l'acteur, l'item et le périmètre sont capturés aussi sur update.
        $this->assertDatabaseHas('capability_override_audit_logs', [
            'action' => 'update',
            'actor_user_id' => $user->id,
            'actor_login' => $user->login,
            'capability_id' => $cap->id,
            'capability_label' => $cap->label,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'scope_label' => 'Salle Info 1',
            'upstream_status' => 'local',
        ]);
    }

    // ── AC#4 — delete ─────────────────────────────────────────────────────

    #[Test]
    public function removing_an_override_logs_a_delete_event_with_null_new_value(): void
    {
        $user = $this->actAsRefnum();
        $cap = $this->capabilityWithKey('remote_desktop_enabled', 'HKCU', 'Software\\RD', 'Enabled');

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

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'action' => 'delete',
            'actor_user_id' => $user->id,
            'actor_login' => $user->login,
            'capability_id' => $cap->id,
            'capability_label' => $cap->label,
            'assignable_type' => WorkstationGroup::class,
            'assignable_id' => $this->parc->id,
            'scope_label' => 'Salle Info 1',
            'old_value' => 'off',
            'new_value' => null,
            'upstream_status' => 'local',
        ]);
    }

    // ── AC#4 (review #4) — pas de trace fantôme : remove sans override ─────

    #[Test]
    public function removing_a_non_existent_override_logs_nothing(): void
    {
        $this->actAsRefnum();
        $cap = $this->capabilityWithKey('show_status_bar', 'HKCU', 'Software\\SBar', 'Show');

        // Aucun override n'existe pour ce périmètre — rejeu / appel direct.
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('removeOverride', $cap->id);

        self::assertSame(
            0,
            CapabilityOverrideAuditLog::query()->count(),
            'aucun acte → aucune trace fantôme (review #4)',
        );
    }

    // ── AC#5 — tag permissive vs local ────────────────────────────────────

    #[Test]
    public function override_on_a_permissive_capability_is_tagged_permissive(): void
    {
        $this->actAsRefnum();
        $cap = $this->capabilityWithKey('show_clock', 'HKCU', 'Software\\Clock', 'Show');
        $this->permissiveUpstream('HKCU', 'Software\\Clock', 'Show');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'action' => 'create',
            'capability_id' => $cap->id,
            'upstream_status' => 'permissive',
        ]);
    }

    #[Test]
    public function override_without_upstream_constraint_is_tagged_local(): void
    {
        $this->actAsRefnum();
        // Un contrat actif EXISTE (item permissif sur une AUTRE clé) mais la capacité
        // éditée n'est sous aucune contrainte amont → tag `local`.
        $cap = $this->capabilityWithKey('show_taskbar', 'HKCU', 'Software\\TB', 'Show');
        $this->permissiveUpstream('HKCU', 'Software\\Autre', 'Clef');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'capability_id' => $cap->id,
            'upstream_status' => 'local',
        ]);
    }

    // ── AC#7 — standalone : audit local + court-circuit NFR3 (0 requête items) ─

    #[Test]
    public function standalone_override_is_audited_local_without_extra_item_query(): void
    {
        $this->actAsRefnum();
        $cap = $this->capabilityWithKey('show_desktop_icons', 'HKCU', 'Software\\DI', 'Show');

        // Aucun contrat amont actif (standalone).
        $component = Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off');

        // Review #1 — le resolver (singleton) a été réchauffé au render du composant.
        // On le « refroidit » pour que `saveOverride` déclenche une résolution FRAÎCHE :
        // sans ce reset, la fenêtre mesurée serait vide et la preuve du court-circuit
        // vacuitement vraie. On prouve alors qu'on requête bien `controlhub_contracts`
        // (le contrat actif est cherché) MAIS JAMAIS `controlhub_contract_items`.
        app()->forgetInstance(UpstreamLockResolver::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $component->call('saveOverride');
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $itemQueries = count(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'controlhub_contract_items'),
        ));
        $contractQueries = count(array_filter(
            $log,
            static fn (array $q): bool => str_contains($q['query'], 'controlhub_contracts'),
        ));
        self::assertGreaterThanOrEqual(1, $contractQueries, 'le contrat actif est bien recherché (résolution fraîche, pas « rien résolu »)');
        self::assertSame(0, $itemQueries, 'standalone : aucune requête items (court-circuit NFR3 préservé)');

        $this->assertDatabaseHas('capability_override_audit_logs', [
            'capability_id' => $cap->id,
            'action' => 'create',
            'upstream_status' => 'local',
        ]);
    }

    // ── Story 29.7 — préservation de `created_at` du pivot (AC#4 story 29.7) ─

    #[Test]
    public function inserting_a_new_override_sets_created_at(): void
    {
        $this->actAsRefnum();
        $cap = $this->capabilityWithKey('show_ruler', 'HKCU', 'Software\\Ruler', 'Show');

        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        $row = DB::table('capability_assignments')
            ->where('capability_id', $cap->id)
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->parc->id)
            ->first();

        self::assertNotNull($row, 'la ligne pivot doit exister après un INSERT');
        self::assertNotNull($row->created_at, 'INSERT : created_at doit être posé (non nul)');
        self::assertNotNull($row->updated_at, 'INSERT : updated_at doit être posé (non nul) — AC#2');
        self::assertSame('off', $row->value, 'la valeur doit être enregistrée');
    }

    #[Test]
    public function re_editing_an_override_preserves_original_created_at(): void
    {
        // AC#4 (cœur) — manqué par sonnet en review 29.5, détecté par opus.
        // Technique : figer created_at dans le PASSÉ avant la ré-édition ;
        // si updateOrInsert réécrit created_at à now(), l'assertion échoue.
        $this->actAsRefnum();
        $cap = $this->capabilityWithKey('show_scrollbar', 'HKCU', 'Software\\SBar', 'Visible');

        // 1 — Premier override (INSERT).
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openAdd', $cap->id)
            ->set('formValue', 'on')
            ->call('saveOverride');

        // 2 — Figer `created_at` ET `updated_at` dans le passé (dates DISTINCTES) :
        //   - created_at -3j prouve la non-réécriture sur UPDATE ;
        //   - updated_at -2j (≠ now) prouve que l'UPDATE le fait réellement AVANCER
        //     (sinon l'assertion serait trivialement vraie, AC#1 non gardé — opus P1).
        $frozenDate = now()->subDays(3)->toDateTimeString();
        $frozenUpdatedAt = now()->subDays(2)->toDateTimeString();
        DB::table('capability_assignments')
            ->where('capability_id', $cap->id)
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->parc->id)
            ->update(['created_at' => $frozenDate, 'updated_at' => $frozenUpdatedAt]);

        // 3 — Ré-édition (chemin UPDATE).
        Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
            ->call('openEdit', $cap->id)
            ->set('formValue', 'off')
            ->call('saveOverride');

        // 4 — Vérifier que `created_at` est INCHANGÉ et que `updated_at` a avancé.
        $row = DB::table('capability_assignments')
            ->where('capability_id', $cap->id)
            ->where('assignable_type', WorkstationGroup::class)
            ->where('assignable_id', $this->parc->id)
            ->first();

        self::assertNotNull($row, 'la ligne pivot doit exister après un UPDATE');
        self::assertSame('off', $row->value, 'la valeur doit avoir été mise à jour');

        // `created_at` doit être strictement égal à la date figée.
        self::assertSame(
            $frozenDate,
            $row->created_at,
            'UPDATE ne doit PAS réécrire created_at (Story 29.7 — bug pré-existant 27.12)',
        );

        // `updated_at` doit avoir AVANCÉ par rapport à sa valeur figée (-2j) :
        // preuve réelle que la branche UPDATE pose bien `updated_at => now()`.
        self::assertGreaterThan(
            $frozenUpdatedAt,
            $row->updated_at,
            'UPDATE doit rafraîchir updated_at à now() (AC#1)',
        );
    }

    // ── AC#6 — atomicité : échec d'audit → override NON persisté (rollback) ─

    #[Test]
    public function audit_failure_rolls_back_the_override(): void
    {
        $this->actAsRefnum();
        $cap = $this->capabilityWithKey('show_search_box', 'HKCU', 'Software\\SB', 'Show');

        // Simule l'échec de l'écriture d'audit : table absente → l'INSERT lève.
        Schema::drop('capability_override_audit_logs');

        try {
            Livewire::test(self::COMPONENT, ['groupId' => $this->parc->id])
                ->call('openAdd', $cap->id)
                ->set('formValue', 'off')
                ->call('saveOverride');
        } catch (Throwable) {
            // L'exception d'audit propage (transaction rollback) — attendu.
        }

        // L'override n'est PAS confirmé : atomicité acte ↔ trace (AC#6).
        $this->assertDatabaseMissing('capability_assignments', [
            'capability_id' => $cap->id,
            'assignable_id' => $this->parc->id,
        ]);
    }
}
