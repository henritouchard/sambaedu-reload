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
        // Décision Henri (review #2) : si l'UI propose « off », « off » doit faire
        // une VRAIE action, PAS être un no-op silencieux. Story 35.1 : une vraie
        // action = écrire une vraie valeur registre OU supprimer la clé via le
        // marqueur `{"$ensure": "absent"}` (l'agent supprime la valeur nommée,
        // Windows reprend son défaut). Chaque clé porte donc une map avec `on`
        // ET un `off` valide (valeur réelle ou marqueur).
        $withOff = [
            'windows_consumer_features_off',
            'offline_files_disabled',
            'windows_copilot_off',
            'onedrive_hidden',
            'remote_desktop_enabled',
            'show_file_extensions',
            'show_hidden_files',
            'uac_enabled',
            'windows_store_disabled',
            // Retrofit 35.1 : les deux ex-« Géré » on-only exposent désormais un
            // vrai off par suppression.
            'llmnr_disabled',
            'windows_updates_managed',
        ];

        foreach ($withOff as $key) {
            $cap = Capability::query()->where('key', $key)->firstOrFail();
            self::assertContains('off', $cap->allowedOptionValues(), "{$key} propose off");
            foreach ($cap->projections()->firstOrFail()->spec['keys'] as $regKey) {
                self::assertArrayHasKey('on', $regKey['value'], "{$key}/{$regKey['name']} a une valeur on");
                self::assertArrayHasKey('off', $regKey['value'], "{$key}/{$regKey['name']} a un off (pas un no-op)");

                $off = $regKey['value']['off'];
                $isRealValue = is_scalar($off);
                $isEnsureMarker = is_array($off) && ($off['$ensure'] ?? null) === 'absent';
                self::assertTrue(
                    $isRealValue || $isEnsureMarker,
                    "{$key}/{$regKey['name']} : off doit être une valeur réelle OU le marqueur \$ensure",
                );
            }
        }
    }

    #[Test]
    public function retrofitted_on_only_capabilities_expose_a_real_off_by_deletion(): void
    {
        // Story 35.1 (remplace `windows_update_is_managed_only_no_misleading_off`) :
        // les deux capacités on-only du parc sont RETROFITTÉES — leurs `options`
        // abandonnent le régime « Géré » on-only et exposent un vrai « off » dont
        // CHAQUE clé porte le marqueur de suppression. Le libellé n'est PAS
        // « Non géré » (réservé à la sentinelle UNMANAGED des capacités opt-in).
        foreach (['llmnr_disabled', 'windows_updates_managed'] as $key) {
            $cap = Capability::query()->where('key', $key)->firstOrFail();

            self::assertSame(['on', 'off'], $cap->allowedOptionValues(), "{$key} expose on ET off");
            self::assertStringNotContainsString(
                'Non géré',
                $cap->optionLabel('off'),
                "{$key} : le libellé off ne doit pas usurper « Non géré » (sentinelle)",
            );

            foreach ($cap->projections()->firstOrFail()->spec['keys'] as $regKey) {
                self::assertSame(
                    ['$ensure' => 'absent'],
                    $regKey['value']['off'],
                    "{$key}/{$regKey['name']} : off = marqueur de suppression",
                );
                self::assertArrayHasKey('on', $regKey['value'], "{$key}/{$regKey['name']} : la valeur on d'origine est conservée");
                self::assertIsNotArray($regKey['value']['on'], "{$key}/{$regKey['name']} : la valeur on reste une valeur réelle");
            }
        }

        // Périmètre exact du retrofit : LLMNR = 2 clés, WindowsUpdate = 6 clés.
        self::assertCount(2, Capability::query()->where('key', 'llmnr_disabled')->firstOrFail()
            ->projections()->firstOrFail()->spec['keys']);
        self::assertCount(6, Capability::query()->where('key', 'windows_updates_managed')->firstOrFail()
            ->projections()->firstOrFail()->spec['keys']);
    }

    #[Test]
    public function retrofit_migration_is_idempotent_and_reversible(): void
    {
        // Story 35.1 (AC4) : la migration de retrofit est REJOUABLE sans effet de
        // bord (update ciblé par `key`) et son down() restaure l'état on-only
        // d'origine des seeds.
        $migration = require database_path('migrations/2026_07_03_100000_retrofit_ensure_off_on_only_capabilities.php');

        $snapshot = fn (): array => Capability::query()
            ->whereIn('key', ['llmnr_disabled', 'windows_updates_managed'])
            ->orderBy('key')
            ->get()
            ->map(fn (Capability $c): array => [
                'options' => $c->options,
                'spec' => $c->projections()->firstOrFail()->spec,
            ])
            ->all();

        // up() déjà joué par RefreshDatabase → le rejouer ne change RIEN.
        $before = $snapshot();
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() rejoué = aucun effet de bord');

        // down() restaure on-only : plus de off (ni option ni entrée de map),
        // LIBELLÉ d'origine compris (review 35.1 #3 : les libellés font partie
        // de l'état restauré, pas seulement les valeurs).
        $migration->down();
        foreach (['llmnr_disabled', 'windows_updates_managed'] as $key) {
            $cap = Capability::query()->where('key', $key)->firstOrFail();
            self::assertSame(['on'], $cap->allowedOptionValues(), "{$key} redevient on-only");
            self::assertSame(
                [['value' => 'on', 'label' => 'Géré']],
                $cap->options,
                "{$key} : libellé d'origine « Géré » restauré",
            );
            foreach ($cap->projections()->firstOrFail()->spec['keys'] as $regKey) {
                self::assertArrayNotHasKey('off', $regKey['value'], "{$key}/{$regKey['name']} : off retiré");
                self::assertArrayHasKey('on', $regKey['value'], "{$key}/{$regKey['name']} : on conservé");
            }
        }

        // up() re-retrofitte à l'identique (rejouable après down()).
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() après down() = état retrofitté identique');
    }

    #[Test]
    public function llmnr_disabled_off_emits_ensure_absent_items_via_the_real_provider(): void
    {
        // Story 35.1 (AC4) — chaîne seed→retrofit→spec→expand→payload prouvée sur
        // données RÉELLES : un override de parc `off` sur `llmnr_disabled` fait
        // émettre par le provider machine 2 items de SUPPRESSION HKLM 4 clés
        // (EnableMulticast + NodeType), en plus du Broadcast `on` (écritures).
        \App\Observers\WorkstationGroupObserver::disableSync();

        try {
            $ws = \App\Models\Workstation::factory()->create();
            $parc = \App\Models\WorkstationGroup::factory()->logical()->create();
            $ws->groups()->attach($parc->id);

            $cap = Capability::query()->where('key', 'llmnr_disabled')->firstOrFail();
            DB::table('capability_assignments')->insert([
                'capability_id' => $cap->id,
                'assignable_type' => \App\Models\WorkstationGroup::class,
                'assignable_id' => $parc->id,
                'value' => 'off',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $items = (new \App\Services\Agent\Providers\RegistryMachineCapabilityProvider())
                ->itemsFor(\App\Services\Agent\TargetContext::for($ws, null));

            $absent = $items->filter(
                fn ($c): bool => (int) $c->sourceId === (int) $cap->id
                    && ($c->payload['ensure'] ?? null) === 'absent',
            )->values();

            self::assertCount(2, $absent, 'off → 2 items de suppression (EnableMulticast + NodeType)');
            $names = $absent->map(fn ($c): string => $c->payload['name'])->sort()->values()->all();
            self::assertSame(['EnableMulticast', 'NodeType'], $names);
            foreach ($absent as $c) {
                self::assertSame(['hive', 'path', 'name', 'ensure'], array_keys($c->payload));
                self::assertSame('HKLM', $c->payload['hive']);
            }
        } finally {
            \App\Observers\WorkstationGroupObserver::enableSync();
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
