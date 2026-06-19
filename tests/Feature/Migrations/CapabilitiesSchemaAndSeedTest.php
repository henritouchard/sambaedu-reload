<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Story 27.12 (AC1, AC5, AC7) — schéma des tables capacités + seed du lot iso +
 * DROP de l'ancien modèle registre.
 *
 * RefreshDatabase joue TOUTES les migrations : création des 3 tables capabilities,
 * seed du lot iso (2026_06_18_100300), puis DROP des tables registry
 * (2026_06_18_100400). On vérifie l'état FINAL après migration.
 */
class CapabilitiesSchemaAndSeedTest extends TestCase
{
    use RefreshDatabase;

    // ── AC1 — Schéma ──────────────────────────────────────────────────────

    #[Test]
    public function the_three_capability_tables_exist_with_expected_columns(): void
    {
        self::assertTrue(Schema::hasTable('capabilities'));
        self::assertTrue(Schema::hasTable('capability_projections'));
        self::assertTrue(Schema::hasTable('capability_assignments'));

        self::assertTrue(Schema::hasColumns('capabilities', [
            'key', 'label', 'description', 'category', 'value_type', 'options',
            'default_value', 'warning', 'applies_to_os', 'is_active', 'overrides_locked',
        ]));

        self::assertTrue(Schema::hasColumns('capability_projections', [
            'capability_id', 'os', 'mechanism', 'spec',
        ]));

        self::assertTrue(Schema::hasColumns('capability_assignments', [
            'capability_id', 'assignable_id', 'assignable_type', 'value',
        ]));
    }

    #[Test]
    public function capability_key_is_unique(): void
    {
        Capability::factory()->create(['key' => 'dup_key']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Capability::factory()->create(['key' => 'dup_key']);
    }

    #[Test]
    public function projection_is_unique_per_capability_os_mechanism(): void
    {
        $cap = Capability::factory()->create();
        CapabilityProjection::factory()->for($cap)->create(['os' => 'windows', 'mechanism' => 'registry']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        CapabilityProjection::factory()->for($cap)->create(['os' => 'windows', 'mechanism' => 'registry']);
    }

    #[Test]
    public function assignment_value_is_nullable(): void
    {
        // value null = repli sur le défaut (D4). Doit être accepté par le schéma.
        $cap = Capability::factory()->create();

        DB::table('capability_assignments')->insert([
            'capability_id' => $cap->id,
            'assignable_type' => \App\Models\WorkstationGroup::class,
            'assignable_id' => 1,
            'value' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertDatabaseCount('capability_assignments', 1);
    }

    // ── AC7 — DROP de l'ancien modèle registre ────────────────────────────

    #[Test]
    public function the_old_registry_tables_are_dropped(): void
    {
        self::assertFalse(Schema::hasTable('registry_settings'), 'registry_settings doit être droppée (27.12 D7)');
        self::assertFalse(Schema::hasTable('registry_setting_assignables'), 'registry_setting_assignables doit être droppée');
    }

    // ── AC5 — Seed du lot iso + migration des 3 existants ─────────────────

    #[Test]
    public function the_iso_lot_is_seeded_with_windows_registry_projections(): void
    {
        $expected = [
            'show_file_extensions',
            'show_hidden_files',
            'uac_enabled',
            'windows_consumer_features_off',
            'windows_updates_managed',
            'offline_files_disabled',
            'remote_desktop_enabled',
            'windows_copilot_off',
            'onedrive_hidden',
            'windows_store_disabled',
        ];

        foreach ($expected as $key) {
            $cap = Capability::query()->where('key', $key)->first();
            self::assertNotNull($cap, "capacité {$key} seedée");
            self::assertSame(['windows'], $cap->applies_to_os, "{$key} applies_to_os = windows");

            $projection = $cap->projections()
                ->where('os', 'windows')
                ->where('mechanism', 'registry')
                ->first();
            self::assertNotNull($projection, "{$key} a une projection registry windows");
            self::assertArrayHasKey('keys', $projection->spec, "{$key} spec.keys présent");
            self::assertNotEmpty($projection->spec['keys'], "{$key} spec.keys non vide");
        }
    }

    #[Test]
    public function uac_default_is_on_safe_posture_with_warning(): void
    {
        // Migration des 3 existants : EnableLUA défaut on (UAC ACTIVÉ, 27.3ter D6)
        // + warning conservé.
        $uac = Capability::query()->where('key', 'uac_enabled')->firstOrFail();

        self::assertSame('on', $uac->default_value, 'EnableLUA défaut on (posture sûre)');
        self::assertTrue($uac->hasWarning(), 'le warning UAC est conservé');

        // La projection mappe on→1 (UAC activé) / off→0.
        $key = $uac->projections()->firstOrFail()->spec['keys'][0];
        self::assertSame('EnableLUA', $key['name']);
        self::assertSame('HKLM', $key['hive']);
        self::assertSame(1, $key['value']['on']);
        self::assertSame(0, $key['value']['off']);
    }

    #[Test]
    public function migrated_three_existing_have_expected_keys(): void
    {
        $extKey = Capability::query()->where('key', 'show_file_extensions')->firstOrFail()
            ->projections()->firstOrFail()->spec['keys'][0];
        self::assertSame('HideFileExt', $extKey['name']);
        self::assertSame('HKCU', $extKey['hive']);

        $hiddenKey = Capability::query()->where('key', 'show_hidden_files')->firstOrFail()
            ->projections()->firstOrFail()->spec['keys'][0];
        self::assertSame('Hidden', $hiddenKey['name']);
        self::assertSame('HKCU', $hiddenKey['hive']);
    }

    #[Test]
    public function on_off_capabilities_emit_a_real_value_for_off(): void
    {
        // Décision Henri (review #2) : si l'UI propose « off », « off » doit écrire
        // une vraie valeur registre (réactivant la fonctionnalité), PAS être un
        // no-op silencieux. Toutes les capacités à 2 états portent donc une map
        // SYMÉTRIQUE {on, off} sur CHAQUE clé.
        $symmetric = [
            'windows_consumer_features_off',
            'offline_files_disabled',
            'windows_copilot_off',
            'onedrive_hidden',
            'remote_desktop_enabled',
            'show_file_extensions',
            'show_hidden_files',
            'uac_enabled',
            'windows_store_disabled',
        ];

        foreach ($symmetric as $key) {
            $cap = Capability::query()->where('key', $key)->firstOrFail();
            self::assertContains('off', $cap->allowedOptionValues(), "{$key} propose off");
            foreach ($cap->projections()->firstOrFail()->spec['keys'] as $regKey) {
                self::assertArrayHasKey('on', $regKey['value'], "{$key}/{$regKey['name']} a une valeur on");
                self::assertArrayHasKey('off', $regKey['value'], "{$key}/{$regKey['name']} a une valeur off (pas un no-op)");
            }
        }
    }

    #[Test]
    public function windows_update_is_managed_only_no_misleading_off(): void
    {
        // Vraie exception on-only : « ne plus gérer » Windows Update = retirer les
        // clés (verbe `delete`, hors MVP). On n'expose donc PAS d'« off » trompeur.
        $wu = Capability::query()->where('key', 'windows_updates_managed')->firstOrFail();

        self::assertSame(['on'], $wu->allowedOptionValues(), 'Windows Update n\'expose que « on » (géré)');
        foreach ($wu->projections()->firstOrFail()->spec['keys'] as $regKey) {
            self::assertArrayNotHasKey('off', $regKey['value'], "{$regKey['name']} reste on-only (clé non émise si off)");
        }
    }

    #[Test]
    public function windows_copilot_off_is_machine_scope_hklm(): void
    {
        // Fix : HKCU\Software\Policies\* est en lecture seule pour l'utilisateur
        // standard (le companion de session échoue « Accès refusé »). La capacité
        // est donc projetée en HKLM (machine/SYSTEM), équivalent Copilot supporté.
        $cap = Capability::query()->where('key', 'windows_copilot_off')->firstOrFail();

        $key = $cap->projections()
            ->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec['keys'][0];

        self::assertSame('HKLM', $key['hive'], 'Copilot doit être en HKLM (pas HKCU\\Software\\Policies)');
        self::assertSame('SOFTWARE\\Policies\\Microsoft\\Windows\\WindowsCopilot', $key['path']);
        self::assertSame('TurnOffWindowsCopilot', $key['name']);
        self::assertSame(1, $key['value']['on']);
        self::assertSame(0, $key['value']['off']);
    }

    #[Test]
    public function windows_store_disabled_blocks_store_via_remove_windows_store(): void
    {
        // Capacité neuve : policy « Désactiver l'application Store ».
        // HKLM\SOFTWARE\Policies\Microsoft\WindowsStore\RemoveWindowsStore = 1 (bloqué).
        $cap = Capability::query()->where('key', 'windows_store_disabled')->firstOrFail();

        self::assertSame('on', $cap->default_value, 'Store bloqué par défaut sur tout le parc');
        self::assertSame(['windows'], $cap->applies_to_os);

        $key = $cap->projections()
            ->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec['keys'][0];

        self::assertSame('HKLM', $key['hive'], 'scope machine');
        self::assertSame('SOFTWARE\\Policies\\Microsoft\\WindowsStore', $key['path']);
        self::assertSame('RemoveWindowsStore', $key['name']);
        self::assertSame('REG_DWORD', $key['type']);
        self::assertSame(1, $key['value']['on'], 'on = Store bloqué');
        self::assertSame(0, $key['value']['off'], 'off = Store accessible (défaut Windows)');
    }

    #[Test]
    public function the_excluded_legacy_settings_are_not_seeded(): void
    {
        // Piège n°6/n°7 : verbe `delete` (telemetry-off) + substitution %SE4FS%
        // (point-and-print) EXCLUS du lot MVP.
        self::assertNull(Capability::query()->where('key', 'windows_telemetry_off')->first());
        self::assertNull(Capability::query()->where('key', 'printers_point_and_print')->first());
    }
}
