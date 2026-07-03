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
            // Story 35.5 : la visionneuse expose un vrai off par suppression des 4
            // clés (marqueur $ensure). Seedée INACTIVE (gate) mais la DONNÉE porte
            // bien un off honnête — l'invariant s'applique à la spec, pas à is_active.
            'photo_viewer_restored',
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

    // ══════════════════════════════════════════════════════════════════════
    // Story 35.5 — Capacité `photo_viewer_restored` (seed sans évolution moteur)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Commande de réenregistrement iso-GPO CD95 (Registry.xml source, à l'octet
     * près). Guillemets DOUBLES littéraux, backslashes doublés en PHP. Quirk GPO :
     * `ImageView_Fullscreen` sur open ET print (PAS `ImageView_PrintTo`).
     */
    private const PHOTO_VIEWER_COMMAND = '%SystemRoot%\\System32\\rundll32.exe "%ProgramFiles%\\Windows Photo Viewer\\PhotoViewer.dll", ImageView_Fullscreen %1';

    #[Test]
    public function all_seeded_capability_strings_fit_their_postgres_varchar_columns(): void
    {
        // Review 35.5 #1 — `capabilities.label/description/category` sont des
        // varchar(255) sur Postgres ; SQLite (tests hôte) n'applique JAMAIS la
        // longueur (mémoire projet : overflow PG 22001 invisible). Ce test
        // structurel couvre TOUS les seeds, présents et futurs.
        foreach (Capability::query()->get() as $cap) {
            foreach (['label', 'description', 'category'] as $col) {
                self::assertLessThanOrEqual(
                    255,
                    mb_strlen((string) $cap->{$col}),
                    "capabilities.{$col} de « {$cap->key} » dépasse varchar(255) — casserait migrate sur /vm (PG 22001)",
                );
            }
        }
    }

    #[Test]
    public function photo_viewer_restored_is_seeded_iso_gpo_cd95_with_four_hkcr_keys_routed_hkcu(): void
    {
        // AC1 — capacité + projection iso-GPO : les 4 clés HKCR routées HKCU\Software\Classes.
        $cap = Capability::query()->where('key', 'photo_viewer_restored')->first();
        self::assertNotNull($cap, 'capacité photo_viewer_restored seedée');

        self::assertSame('Visionneuse de photos Windows', $cap->label, 'label = sujet neutre (convention sujet+état)');
        self::assertSame('Bureau', $cap->category);
        self::assertSame('toggle', $cap->value_type);
        self::assertSame('unmanaged', $cap->default_value, 'opt-in : rien n\'est émis en broadcast');
        self::assertSame(['windows'], $cap->applies_to_os);
        self::assertFalse($cap->hasWarning(), 'warning null');
        self::assertSame(['unmanaged', 'on', 'off'], $cap->allowedOptionValues());
        // « Non géré » RÉSERVÉ à la sentinelle unmanaged (convention libellés).
        self::assertSame('Non géré', $cap->optionLabel('unmanaged'));
        self::assertStringNotContainsString('Non géré', $cap->optionLabel('on'));
        self::assertStringNotContainsString('Non géré', $cap->optionLabel('off'));

        $keys = $cap->projections()
            ->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec['keys'];
        self::assertCount(4, $keys, 'EXACTEMENT les 4 clés HKCR de la GPO');

        // Toutes routées HKCU\Software\Classes\… (portée Session, iso onedrive_hidden).
        foreach ($keys as $k) {
            self::assertSame('HKCU', $k['hive'], 'HKCR routé HKCU (portée Session)');
            self::assertStringStartsWith('Software\\Classes\\Applications\\photoviewer.dll\\', $k['path']);
        }

        // #1 — open\command : name = valeur PAR DÉFAUT (''), REG_EXPAND_SZ, commande exacte.
        self::assertSame('Software\\Classes\\Applications\\photoviewer.dll\\shell\\open\\command', $keys[0]['path']);
        self::assertSame('', $keys[0]['name'], 'open\\command écrit la valeur PAR DÉFAUT (name="")');
        self::assertSame('REG_EXPAND_SZ', $keys[0]['type']);
        self::assertSame(self::PHOTO_VIEWER_COMMAND, $keys[0]['value']['on']);

        // #2 — print\command : name '', REG_EXPAND_SZ, MÊME commande (quirk print préservé).
        self::assertSame('Software\\Classes\\Applications\\photoviewer.dll\\shell\\print\\command', $keys[1]['path']);
        self::assertSame('', $keys[1]['name'], 'print\\command écrit la valeur PAR DÉFAUT (name="")');
        self::assertSame('REG_EXPAND_SZ', $keys[1]['type']);
        self::assertSame(self::PHOTO_VIEWER_COMMAND, $keys[1]['value']['on'], 'quirk GPO : ImageView_Fullscreen sur print AUSSI');

        // #3 — open\DropTarget : Clsid REG_SZ.
        self::assertSame('Software\\Classes\\Applications\\photoviewer.dll\\shell\\open\\DropTarget', $keys[2]['path']);
        self::assertSame('Clsid', $keys[2]['name']);
        self::assertSame('REG_SZ', $keys[2]['type']);
        self::assertSame('{FFE2A43C-56B9-4bf5-9A79-CC6D4285608A}', $keys[2]['value']['on']);

        // #4 — print\DropTarget : Clsid REG_SZ, GUID DISTINCT de open (source GPO fait foi).
        self::assertSame('Software\\Classes\\Applications\\photoviewer.dll\\shell\\print\\DropTarget', $keys[3]['path']);
        self::assertSame('Clsid', $keys[3]['name']);
        self::assertSame('REG_SZ', $keys[3]['type']);
        self::assertSame('{60fd46de-f830-4894-a628-6fa81bc0190d}', $keys[3]['value']['on']);

        self::assertNotSame(
            $keys[2]['value']['on'],
            $keys[3]['value']['on'],
            'les 2 DropTarget\\Clsid sont DISTINCTS (open ≠ print)',
        );
    }

    #[Test]
    public function photo_viewer_restored_is_gated_inactive_until_agent_supports_default_value_names(): void
    {
        // AC3 — gate d'honnêteté : la capacité est seedée is_active=false parce que
        // l'agent actuel (parseRegistrySpec) rejette `name == ""` (valeur par défaut
        // de clé) → une capacité armée écrirait les 2 Clsid mais pas les 2 command
        // (nœud à moitié enregistré, pire que rien). Le flip is_active=true est gated
        // par une micro-évolution agent hors story (migration d'activation postérieure).
        $cap = Capability::query()->where('key', 'photo_viewer_restored')->firstOrFail();

        // FLIP 35.2 : cette assertion basculera à assertTrue quand la micro-évolution
        // agent « name:'' = valeur par défaut » sera prouvée (migration d'activation
        // dédiée, à l'intégration de la vague). C'est la SEULE assertion à retoucher.
        self::assertFalse(
            $cap->is_active,
            'gate d\'honnêteté : inactive tant que parseRegistrySpec (agent) rejette name=="" '
            .'(valeur par défaut de clé) — activation via migration d\'une ligne hors story',
        );

        // Preuve du gating par la MÉCANIQUE EXISTANTE : armée `on` par override de
        // parc mais INACTIVE, le provider User n'émet AUCUN item pour cette capacité
        // (le filtre `is_active` du provider est le gate).
        \App\Observers\WorkstationGroupObserver::disableSync();

        try {
            $ws = \App\Models\Workstation::factory()->create();
            $parc = \App\Models\WorkstationGroup::factory()->logical()->create();
            $ws->groups()->attach($parc->id);

            DB::table('capability_assignments')->insert([
                'capability_id' => $cap->id,
                'assignable_type' => \App\Models\WorkstationGroup::class,
                'assignable_id' => $parc->id,
                'value' => 'on',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $items = (new \App\Services\Agent\Providers\RegistryUserCapabilityProvider())
                ->itemsFor(\App\Services\Agent\TargetContext::for($ws, null));

            $mine = $items->filter(fn ($c): bool => (int) $c->sourceId === (int) $cap->id)->values();
            self::assertCount(0, $mine, 'capacité inactive ⇒ provider n\'émet RIEN même armée on');
        } finally {
            \App\Observers\WorkstationGroupObserver::enableSync();
        }
    }

    #[Test]
    public function photo_viewer_restored_seed_is_idempotent_and_reversible(): void
    {
        // AC1 (idempotence/réversibilité) : up() rejoué = snapshot identique ;
        // down() supprime capacité ET projection ; up() re-seed à l'identique
        // (pattern retrofit_migration_is_idempotent_and_reversible, version seed).
        $migration = require database_path('migrations/2026_07_03_130000_seed_capability_photo_viewer_restored.php');

        $snapshot = function (): array {
            $cap = Capability::query()->where('key', 'photo_viewer_restored')->firstOrFail();

            return [
                'options' => $cap->options,
                'default_value' => $cap->default_value,
                'is_active' => $cap->is_active,
                'spec' => $cap->projections()
                    ->where('os', 'windows')->where('mechanism', 'registry')
                    ->firstOrFail()->spec,
            ];
        };

        // up() déjà joué par RefreshDatabase → le rejouer ne change RIEN
        // (updateOrInsert par key + par (capability_id, os, mechanism)).
        $before = $snapshot();
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() rejoué = aucun effet de bord');
        self::assertFalse($before['is_active'], 'is_active reste false à chaque rejeu (dernier seed fait foi)');

        // down() : suppression par key → cascade FK sur la projection.
        $migration->down();
        self::assertNull(
            Capability::query()->where('key', 'photo_viewer_restored')->first(),
            'down() supprime la capacité',
        );
        self::assertSame(
            0,
            DB::table('capability_projections')
                ->join('capabilities', 'capabilities.id', '=', 'capability_projections.capability_id')
                ->where('capabilities.key', 'photo_viewer_restored')
                ->count(),
            'down() supprime la projection (cascade FK)',
        );

        // up() re-seed à l'identique (rejouable après down()).
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() après down() = état seedé identique');
    }

    #[Test]
    public function photo_viewer_restored_emits_session_items_via_the_real_provider_once_activated(): void
    {
        // AC4 — chaîne seed→spec→expand→payload prouvée sur données RÉELLES (pattern
        // llmnr_disabled_off_emits_ensure_absent_items_via_the_real_provider). On
        // SIMULE le flip post-gate (`update(is_active=true)`) pour prouver que la
        // DONNÉE est correcte de bout en bout — le gate lui-même est prouvé par
        // photo_viewer_restored_is_gated_inactive_until_agent_supports_default_value_names.
        \App\Observers\WorkstationGroupObserver::disableSync();

        try {
            $cap = Capability::query()->where('key', 'photo_viewer_restored')->firstOrFail();
            $cap->update(['is_active' => true]); // simulation du flip post-gate (hors story)

            $ws = \App\Models\Workstation::factory()->create();
            $parc = \App\Models\WorkstationGroup::factory()->logical()->create();
            $ws->groups()->attach($parc->id);

            $assignmentId = DB::table('capability_assignments')->insertGetId([
                'capability_id' => $cap->id,
                'assignable_type' => \App\Models\WorkstationGroup::class,
                'assignable_id' => $parc->id,
                'value' => 'on',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $userCtx = \App\Services\Agent\TargetContext::for($ws, null);

            // ── override `on` → 4 items d'ÉCRITURE 5 clés, tous HKCU ────────────
            $onItems = (new \App\Services\Agent\Providers\RegistryUserCapabilityProvider())
                ->itemsFor($userCtx)
                ->filter(fn ($c): bool => (int) $c->sourceId === (int) $cap->id)
                ->values();
            self::assertCount(4, $onItems, 'on → 4 items d\'écriture (4 clés)');

            foreach ($onItems as $c) {
                self::assertSame(['hive', 'path', 'name', 'type', 'value'], array_keys($c->payload), 'item d\'écriture = 5 clés');
                self::assertSame('HKCU', $c->payload['hive']);
            }

            // 2 items name==='' / REG_EXPAND_SZ / commande exacte (open + print).
            $commands = $onItems->filter(fn ($c): bool => $c->payload['name'] === '')->values();
            self::assertCount(2, $commands, '2 command à name=="" (valeur par défaut)');
            foreach ($commands as $c) {
                self::assertSame('REG_EXPAND_SZ', $c->payload['type']);
                self::assertSame(self::PHOTO_VIEWER_COMMAND, $c->payload['value']);
            }

            // 2 items name==='Clsid' / REG_SZ / les 2 GUID distincts.
            $dropTargets = $onItems->filter(fn ($c): bool => $c->payload['name'] === 'Clsid')->values();
            self::assertCount(2, $dropTargets, '2 DropTarget à name=="Clsid"');
            $guids = $dropTargets->map(fn ($c): string => $c->payload['value'])->sort()->values()->all();
            self::assertSame(
                ['{60fd46de-f830-4894-a628-6fa81bc0190d}', '{FFE2A43C-56B9-4bf5-9A79-CC6D4285608A}'],
                $guids,
                'les 2 Clsid distincts sont émis',
            );
            foreach ($dropTargets as $c) {
                self::assertSame('REG_SZ', $c->payload['type']);
            }

            // Pas de fuite d'id/key de capacité dans le payload (invariant central).
            foreach ($onItems as $c) {
                self::assertArrayNotHasKey('capability_id', $c->payload);
                self::assertArrayNotHasKey('key', $c->payload);
            }

            // ── override `off` → 4 items de SUPPRESSION 4 clés {hive,path,name,ensure} ─
            DB::table('capability_assignments')->where('id', $assignmentId)->update(['value' => 'off']);

            $offItems = (new \App\Services\Agent\Providers\RegistryUserCapabilityProvider())
                ->itemsFor(\App\Services\Agent\TargetContext::for($ws, null))
                ->filter(fn ($c): bool => (int) $c->sourceId === (int) $cap->id)
                ->values();
            self::assertCount(4, $offItems, 'off → 4 items de suppression (4 clés)');
            foreach ($offItems as $c) {
                self::assertSame(['hive', 'path', 'name', 'ensure'], array_keys($c->payload), 'item de suppression = 4 clés');
                self::assertSame('HKCU', $c->payload['hive']);
                self::assertSame(
                    \App\Services\Agent\Providers\AbstractCapabilityStateProvider::ENSURE_ABSENT,
                    $c->payload['ensure'],
                );
            }
            // Mêmes identités de clé qu'en écriture (2 command à name="" + 2 Clsid).
            $offNames = $offItems->map(fn ($c): string => $c->payload['name'])->sort()->values()->all();
            self::assertSame(['', '', 'Clsid', 'Clsid'], $offNames);

            // ── RegistryMachineCapabilityProvider n'émet RIEN (aucune clé HKLM) ─
            $machineItems = (new \App\Services\Agent\Providers\RegistryMachineCapabilityProvider())
                ->itemsFor(\App\Services\Agent\TargetContext::for($ws, null))
                ->filter(fn ($c): bool => (int) $c->sourceId === (int) $cap->id)
                ->values();
            self::assertCount(0, $machineItems, 'aucune clé HKLM → provider machine muet');
        } finally {
            \App\Observers\WorkstationGroupObserver::enableSync();
        }
    }
}
