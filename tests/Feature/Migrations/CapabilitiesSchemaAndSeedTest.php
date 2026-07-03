<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Capability;
use App\Models\CapabilityProjection;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Workstation;
use App\Models\WorkstationGroup;
use App\Observers\UserGroupObserver;
use App\Observers\UserGroupUserPivotObserver;
use App\Observers\WorkstationGroupObserver;
use App\Services\Agent\Providers\RegistryMachineCapabilityProvider;
use App\Services\Agent\Providers\RegistryUserCapabilityProvider;
use App\Services\Agent\StateCompiler;
use App\Services\Agent\StateContract;
use App\Services\Agent\StateHasher;
use App\Services\Agent\TargetContext;
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
        // Story 35.2 : invariant ÉTENDU au mécanisme `registry_list` — un off
        // valide y est une LISTE (y compris VIDE : purge des entrées numérotées,
        // le « off » honnête d'une liste) ; le marqueur $ensure n'y existe pas.
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
            // Lot 35.2 : off combiné = flag supprimé (registry, marqueur) +
            // entrées purgées (registry_list, liste vide).
            'blocked_executables',
        ];

        foreach ($withOff as $key) {
            $cap = Capability::query()->where('key', $key)->firstOrFail();
            self::assertContains('off', $cap->allowedOptionValues(), "{$key} propose off");

            $projections = $cap->projections()->where('os', 'windows')->get();
            self::assertNotEmpty($projections, "{$key} a au moins une projection windows");

            foreach ($projections as $projection) {
                foreach ($projection->spec['keys'] as $regKey) {
                    if ($projection->mechanism === CapabilityProjection::MECHANISM_REGISTRY_LIST) {
                        $label = "{$key}/{$regKey['path']}";
                        self::assertArrayHasKey('on', $regKey['values'], "{$label} a une liste on");
                        self::assertArrayHasKey('off', $regKey['values'], "{$label} a un off (pas un no-op)");

                        $off = $regKey['values']['off'];
                        self::assertTrue(
                            is_array($off) && array_is_list($off),
                            "{$label} : off doit être une LISTE (y compris vide = purge), jamais \$ensure",
                        );

                        continue;
                    }

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

    // ── Story 35.4 — armement `registry_editing_disabled` par override UserGroup ─

    #[Test]
    public function registry_editing_disabled_override_on_a_user_group_compiles_for_members_only(): void
    {
        // Story 35.4 (AC5) — sur DONNÉES RÉELLES seedées (lot CD95) : un override `on`
        // de `registry_editing_disabled` posé sur un UserGroup fait émettre, pour un
        // user MEMBRE, l'item session `DisableRegistryTools = 1` (HKCU, Policies\System)
        // via le StateCompiler INTOUCHÉ ; pour un user NON-membre, AUCUN item pour cette
        // clé — le Broadcast `unmanaged` n'émet rien (piège #8).
        WorkstationGroupObserver::disableSync();
        UserGroupObserver::disableSync();
        UserGroupUserPivotObserver::disableSync();

        try {
            $cap = Capability::query()->where('key', 'registry_editing_disabled')->firstOrFail();
            self::assertSame('unmanaged', $cap->default_value, 'défaut seedé = unmanaged (Broadcast n\'émet rien)');

            $group = UserGroup::factory()->create();
            DB::table('capability_assignments')->insert([
                'capability_id' => $cap->id,
                'assignable_type' => UserGroup::class,
                'assignable_id' => $group->id,
                'value' => 'on',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $ws = Workstation::factory()->create();
            $member = User::factory()->create();
            $member->groups()->attach($group->id);
            $nonMember = User::factory()->create();

            $compiler = new StateCompiler(new StateHasher(), [
                new RegistryMachineCapabilityProvider(),
                new RegistryUserCapabilityProvider(),
            ]);

            // MEMBRE : DisableRegistryTools = 1 (HKCU, Policies\System).
            $sessionMember = $compiler->compile(TargetContext::for($ws, $member))[StateContract::SCOPE_SESSION];
            $item = collect($sessionMember)->first(
                fn ($i): bool => $i['type'] === 'registry' && ($i['payload']['name'] ?? null) === 'DisableRegistryTools',
            );
            self::assertNotNull($item, 'membre → DisableRegistryTools émis en session');
            self::assertSame(1, $item['payload']['value']);
            self::assertSame('HKCU', $item['payload']['hive']);
            self::assertStringContainsString('Policies\\System', $item['payload']['path']);

            // NON-MEMBRE : aucun item pour cette clé (Broadcast unmanaged n'émet rien).
            $sessionNon = $compiler->compile(TargetContext::for($ws, $nonMember))[StateContract::SCOPE_SESSION];
            $itemNon = collect($sessionNon)->first(
                fn ($i): bool => $i['type'] === 'registry' && ($i['payload']['name'] ?? null) === 'DisableRegistryTools',
            );
            self::assertNull($itemNon, 'non-membre → aucun item pour cette clé');
        } finally {
            WorkstationGroupObserver::enableSync();
            UserGroupObserver::enableSync();
            UserGroupUserPivotObserver::enableSync();
        }
    }

    #[Test]
    public function the_excluded_legacy_settings_are_not_seeded(): void
    {
        // Piège n°6/n°7 : verbe `delete` (telemetry-off) + substitution %SE4FS%
        // (point-and-print) EXCLUS du lot MVP.
        self::assertNull(Capability::query()->where('key', 'windows_telemetry_off')->first());
        self::assertNull(Capability::query()->where('key', 'printers_point_and_print')->first());
    }

    // ── Story 35.2 (AC5) — lot registry_list : pix + blocked_executables ──

    #[Test]
    public function pix_extension_forced_is_seeded_with_one_registry_list_projection(): void
    {
        $cap = Capability::query()->where('key', 'pix_extension_forced')->firstOrFail();

        self::assertSame('unmanaged', $cap->default_value, 'opt-in : rien en broadcast');
        self::assertSame(['unmanaged', 'on'], $cap->allowedOptionValues());
        self::assertSame('Non géré', $cap->optionLabel('unmanaged'));
        self::assertSame('Forcée', $cap->optionLabel('on'));
        self::assertSame(['windows'], $cap->applies_to_os);

        // UNE seule projection : registry_list (pas de bi-projection ici).
        $projections = $cap->projections()->where('os', 'windows')->get();
        self::assertCount(1, $projections);
        self::assertSame(CapabilityProjection::MECHANISM_REGISTRY_LIST, $projections[0]->mechanism);

        // DEUX conteneurs HKLM (Chrome + Edge), entry_type REG_SZ, valeurs
        // iso-GPO CD95 : Chrome = id SEUL, Edge = id;update_url CRX.
        $keys = $projections[0]->spec['keys'];
        self::assertCount(2, $keys);
        self::assertSame('HKLM', $keys[0]['hive']);
        self::assertSame('SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist', $keys[0]['path']);
        self::assertSame('REG_SZ', $keys[0]['entry_type']);
        self::assertSame(['pgpjajcmfbfdmcgjlbiengidaknopaok'], $keys[0]['values']['on']);
        self::assertSame('HKLM', $keys[1]['hive']);
        self::assertSame('SOFTWARE\\Policies\\Microsoft\\Edge\\ExtensionInstallForcelist', $keys[1]['path']);
        self::assertSame(
            ['pgpjajcmfbfdmcgjlbiengidaknopaok;https://clients2.google.com/service/update2/crx'],
            $keys[1]['values']['on'],
        );
    }

    #[Test]
    public function blocked_executables_is_seeded_as_the_first_bi_projection_capability(): void
    {
        $cap = Capability::query()->where('key', 'blocked_executables')->firstOrFail();

        self::assertSame('unmanaged', $cap->default_value, 'opt-in : la cible métier est un override UserGroup élèves (armement = donnée/35.4)');
        self::assertSame(['unmanaged', 'on', 'off'], $cap->allowedOptionValues());
        self::assertSame('Non géré', $cap->optionLabel('unmanaged'), 'réservé à la sentinelle');
        self::assertSame('Activé', $cap->optionLabel('on'));
        self::assertSame('Désactivé (valeurs supprimées)', $cap->optionLabel('off'));

        // Bi-projection D5 : DEUX lignes windows, mécanismes distincts.
        $projections = $cap->projections()->where('os', 'windows')->orderBy('mechanism')->get();
        self::assertCount(2, $projections, 'bi-projection = 2 lignes (registry + registry_list)');
        self::assertSame(
            [CapabilityProjection::MECHANISM_REGISTRY, CapabilityProjection::MECHANISM_REGISTRY_LIST],
            $projections->pluck('mechanism')->all(),
        );

        // Flag registry : tree restrictions user-writable (PAS HKCU\Software\
        // Policies), on=1, off=marqueur de suppression 35.1.
        $flag = $projections[0]->spec['keys'][0];
        self::assertSame('HKCU', $flag['hive']);
        self::assertSame('Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer', $flag['path']);
        self::assertSame('DisallowRun', $flag['name']);
        self::assertSame('REG_DWORD', $flag['type']);
        self::assertSame(1, $flag['value']['on']);
        self::assertSame(['$ensure' => 'absent'], $flag['value']['off']);

        // Conteneur registry_list : les 5 entrées ORDONNÉES (cmd.exe remplace
        // DisableCMD, iso-intention CD95), off = purge (liste vide).
        $container = $projections[1]->spec['keys'][0];
        self::assertSame('HKCU', $container['hive']);
        self::assertSame('Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer\\DisallowRun', $container['path']);
        self::assertSame('REG_SZ', $container['entry_type']);
        self::assertSame(
            ['powershell.exe', 'powershell_ise.exe', 'pwsh.exe', 'mstsc.exe', 'cmd.exe'],
            $container['values']['on'],
            'ordre significatif préservé',
        );
        self::assertSame([], $container['values']['off'], 'off = purge des entrées numérotées');
    }

    #[Test]
    public function registry_list_lot_migration_is_idempotent_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_07_03_110000_seed_capabilities_registry_list_lot.php');

        $snapshot = fn (): array => Capability::query()
            ->whereIn('key', ['pix_extension_forced', 'blocked_executables'])
            ->orderBy('key')
            ->get()
            ->map(fn (Capability $c): array => [
                'options' => $c->options,
                'specs' => $c->projections()->orderBy('mechanism')->pluck('spec')->all(),
            ])
            ->all();

        // up() déjà joué par RefreshDatabase → rejouer = aucun effet de bord.
        $before = $snapshot();
        self::assertCount(2, $before);
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() rejoué = idempotent');

        // down() retire les 2 capacités (cascade projections/assignments)…
        $migration->down();
        self::assertSame([], $snapshot());

        // …et up() les re-seed à l'identique.
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() après down() = état identique');
    }

    // ── Story 35.2 (AC3) — garde-fou d'authoring scalaire↔conteneur ────────

    /**
     * Projections windows du catalogue RÉELLEMENT seedé, au format du garde-fou.
     *
     * @return list<array{capability:string, mechanism:string, spec:mixed}>
     */
    private function seededWindowsProjections(): array
    {
        return CapabilityProjection::query()
            ->where('os', 'windows')
            ->whereIn('mechanism', [
                CapabilityProjection::MECHANISM_REGISTRY,
                CapabilityProjection::MECHANISM_REGISTRY_LIST,
            ])
            ->with('capability')
            ->get()
            ->map(fn (CapabilityProjection $p): array => [
                'capability' => (string) $p->capability->key,
                'mechanism' => (string) $p->mechanism,
                'spec' => $p->spec,
            ])
            ->all();
    }

    #[Test]
    public function no_container_is_targeted_by_both_registry_scalar_and_registry_list(): void
    {
        // AC3 — invariant sur les DONNÉES RÉELLEMENT SEEDÉES (authoring
        // catalogue-first) : aucune clé-conteneur registry_list n'est aussi la
        // clé d'un scalaire registry ; entry_type et values bien formés partout.
        $guard = new \App\Services\Agent\Providers\CapabilitySpecCollisionGuard();

        self::assertSame(
            [],
            $guard->violations($this->seededWindowsProjections()),
            'le catalogue seedé ne doit porter AUCUNE violation d\'authoring registre',
        );
    }

    #[Test]
    public function blocked_executables_parent_flag_vs_child_container_is_not_a_collision(): void
    {
        // AC3 (cas nominal, piège n°11) : le flag `…\Policies\Explorer` (name
        // DisallowRun) et le conteneur `…\Policies\Explorer\DisallowRun` sont
        // des paths PARENT/ENFANT distincts → PAS une collision. Prouvé sur le
        // sous-ensemble blocked_executables seul.
        $guard = new \App\Services\Agent\Providers\CapabilitySpecCollisionGuard();

        $blocked = array_values(array_filter(
            $this->seededWindowsProjections(),
            static fn (array $p): bool => $p['capability'] === 'blocked_executables',
        ));
        self::assertCount(2, $blocked, 'bi-projection présente');
        self::assertSame([], $guard->violations($blocked), 'parent/enfant ≠ collision');
    }

    #[Test]
    public function guard_refuses_a_scalar_key_equal_to_a_list_container(): void
    {
        // AC3 (cas refusé) : un scalaire dont le path ÉGALE le conteneur (peu
        // importe son name — il vivrait DANS la clé possédée par l'agent) est
        // une violation explicite nommant les deux capacités et le conteneur.
        $guard = new \App\Services\Agent\Providers\CapabilitySpecCollisionGuard();

        $violations = $guard->violations([
            [
                'capability' => 'blocked_executables',
                'mechanism' => 'registry_list',
                'spec' => ['keys' => [[
                    'hive' => 'HKCU',
                    'path' => 'Software\\Microsoft\\Windows\\CurrentVersion\\Policies\\Explorer\\DisallowRun',
                    'entry_type' => 'REG_SZ',
                    'values' => ['on' => ['cmd.exe']],
                ]]],
            ],
            [
                'capability' => 'rogue_scalar_cap',
                'mechanism' => 'registry',
                'spec' => ['keys' => [[
                    // MÊME clé que le conteneur (casse différente : identité
                    // normalisée), name numérique — vivrait DANS la clé possédée.
                    'hive' => 'hkcu',
                    'path' => 'software\\microsoft\\windows\\currentversion\\policies\\explorer\\disallowrun',
                    'name' => '6',
                    'type' => 'REG_SZ',
                    'value' => ['on' => 'rogue.exe'],
                ]]],
            ],
        ]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('blocked_executables', $violations[0]);
        self::assertStringContainsString('rogue_scalar_cap', $violations[0]);
        self::assertStringContainsString('disallowrun', $violations[0]);
    }

    #[Test]
    public function guard_reports_malformed_entry_type_and_values(): void
    {
        $guard = new \App\Services\Agent\Providers\CapabilitySpecCollisionGuard();

        $violations = $guard->violations([
            [
                'capability' => 'bad_list_cap',
                'mechanism' => 'registry_list',
                'spec' => ['keys' => [
                    // entry_type hors contrat.
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\A', 'entry_type' => 'REG_DWORD', 'values' => ['on' => ['1']]],
                    // map dont une valeur n'est pas une liste ($ensure interdit).
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\B', 'entry_type' => 'REG_SZ', 'values' => ['off' => ['$ensure' => 'absent']]],
                    // littéral avec entrée non scalaire.
                    ['hive' => 'HKLM', 'path' => 'SOFTWARE\\C', 'entry_type' => 'REG_SZ', 'values' => [['nested']]],
                ]],
            ],
        ]);

        self::assertCount(3, $violations);
        self::assertStringContainsString("entry_type 'REG_DWORD' hors contrat", $violations[0]);
        self::assertStringContainsString('$ensure', $violations[1]);
        self::assertStringContainsString('non scalaire', $violations[2]);
    }

    #[Test]
    public function guard_refuses_a_list_container_with_empty_hive_or_path(): void
    {
        // Review 35.2 #3 : un conteneur à hive/path vide passait l'authoring
        // puis devenait {status: error} silencieux côté agent → refus AMONT.
        $guard = new \App\Services\Agent\Providers\CapabilitySpecCollisionGuard();

        $violations = $guard->violations([
            [
                'capability' => 'empty_container_cap',
                'mechanism' => 'registry_list',
                'spec' => ['keys' => [
                    ['hive' => '', 'path' => 'SOFTWARE\\A', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['1']]],
                    ['hive' => 'HKLM', 'path' => '  ', 'entry_type' => 'REG_SZ', 'values' => ['on' => ['1']]],
                ]],
            ],
        ]);

        self::assertCount(2, $violations);
        self::assertStringContainsString('hive et path sont requis', $violations[0]);
        self::assertStringContainsString('hive et path sont requis', $violations[1]);
    }

    // ── Story 35.2 (AC5) — intégration providers sur données RÉELLES ───────

    #[Test]
    public function pix_extension_forced_on_emits_two_hklm_registry_list_items(): void
    {
        // Chaîne seed→spec→expand→payload sur données réelles : un override de
        // parc `on` fait émettre par le provider list MACHINE les 2 conteneurs
        // Forcelist (Chrome + Edge), payload 4 clés, jamais d'id de capacité.
        \App\Observers\WorkstationGroupObserver::disableSync();

        try {
            $ws = \App\Models\Workstation::factory()->create();
            $parc = \App\Models\WorkstationGroup::factory()->logical()->create();
            $ws->groups()->attach($parc->id);

            $cap = Capability::query()->where('key', 'pix_extension_forced')->firstOrFail();
            DB::table('capability_assignments')->insert([
                'capability_id' => $cap->id,
                'assignable_type' => \App\Models\WorkstationGroup::class,
                'assignable_id' => $parc->id,
                'value' => 'on',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $items = (new \App\Services\Agent\Providers\RegistryListMachineCapabilityProvider())
                ->itemsFor(\App\Services\Agent\TargetContext::for($ws, null));

            // Défaut unmanaged (sentinelle) : le Broadcast n'émet RIEN — seuls
            // les 2 conteneurs de l'override existent.
            self::assertCount(2, $items);
            foreach ($items as $c) {
                self::assertSame(\App\Enums\StateMaille::LogicalGroup, $c->maille);
                self::assertSame(['hive', 'path', 'entry_type', 'values'], array_keys($c->payload));
                self::assertSame('HKLM', $c->payload['hive']);
            }
            $byPath = $items->keyBy(fn ($c): string => $c->payload['path']);
            self::assertSame(
                ['pgpjajcmfbfdmcgjlbiengidaknopaok'],
                $byPath['SOFTWARE\\Policies\\Google\\Chrome\\ExtensionInstallForcelist']->payload['values'],
            );
            self::assertSame(
                ['pgpjajcmfbfdmcgjlbiengidaknopaok;https://clients2.google.com/service/update2/crx'],
                $byPath['SOFTWARE\\Policies\\Microsoft\\Edge\\ExtensionInstallForcelist']->payload['values'],
            );
        } finally {
            \App\Observers\WorkstationGroupObserver::enableSync();
        }
    }

    #[Test]
    public function blocked_executables_bi_projection_emits_flag_and_list_per_provider(): void
    {
        // Bi-projection D5 sur données réelles : chaque provider User ne voit
        // que SA projection — `on` ⇒ 1 flag (registry) + 1 conteneur 5 entrées
        // (registry_list) ; `off` ⇒ 1 item ensure:absent + 1 conteneur values:[] ;
        // `unmanaged` (défaut) ⇒ rien.
        \App\Observers\WorkstationGroupObserver::disableSync();
        \App\Observers\UserGroupObserver::disableSync();
        \App\Observers\UserGroupUserPivotObserver::disableSync();

        try {
            $ws = \App\Models\Workstation::factory()->create();
            $user = \App\Models\User::factory()->create();
            $group = \App\Models\UserGroup::factory()->create();
            $user->groups()->attach($group->id);

            $cap = Capability::query()->where('key', 'blocked_executables')->firstOrFail();
            $ctx = fn () => \App\Services\Agent\TargetContext::for($ws, $user);
            $registryProvider = new \App\Services\Agent\Providers\RegistryUserCapabilityProvider();
            $listProvider = new \App\Services\Agent\Providers\RegistryListUserCapabilityProvider();
            $forCap = fn ($items) => $items->filter(
                fn ($c): bool => (int) $c->sourceId === (int) $cap->id,
            )->values();

            // unmanaged (défaut, aucun override) ⇒ RIEN des deux providers.
            self::assertCount(0, $forCap($registryProvider->itemsFor($ctx())));
            self::assertCount(0, $forCap($listProvider->itemsFor($ctx())));

            // Override UserGroup `on` (la cible métier — élèves).
            DB::table('capability_assignments')->insert([
                'capability_id' => $cap->id,
                'assignable_type' => \App\Models\UserGroup::class,
                'assignable_id' => $group->id,
                'value' => 'on',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $flagItems = $forCap($registryProvider->itemsFor($ctx()));
            self::assertCount(1, $flagItems, 'le provider registry ne voit que le flag');
            self::assertSame(['hive', 'path', 'name', 'type', 'value'], array_keys($flagItems[0]->payload));
            self::assertSame('DisallowRun', $flagItems[0]->payload['name']);
            self::assertSame(1, $flagItems[0]->payload['value']);

            $listItems = $forCap($listProvider->itemsFor($ctx()));
            self::assertCount(1, $listItems, 'le provider list ne voit que le conteneur');
            self::assertSame(['hive', 'path', 'entry_type', 'values'], array_keys($listItems[0]->payload));
            self::assertSame(
                ['powershell.exe', 'powershell_ise.exe', 'pwsh.exe', 'mstsc.exe', 'cmd.exe'],
                $listItems[0]->payload['values'],
                'les 5 entrées, ordre préservé',
            );

            // Override `off` ⇒ action combinée : flag SUPPRIMÉ + entrées PURGÉES.
            DB::table('capability_assignments')
                ->where('capability_id', $cap->id)
                ->update(['value' => 'off']);

            $flagOff = $forCap($registryProvider->itemsFor($ctx()));
            self::assertCount(1, $flagOff);
            self::assertSame(['hive', 'path', 'name', 'ensure'], array_keys($flagOff[0]->payload));
            self::assertSame('absent', $flagOff[0]->payload['ensure']);

            $listOff = $forCap($listProvider->itemsFor($ctx()));
            self::assertCount(1, $listOff);
            self::assertSame([], $listOff[0]->payload['values'], 'off = purge (liste vide)');
        } finally {
            \App\Observers\WorkstationGroupObserver::enableSync();
            \App\Observers\UserGroupObserver::enableSync();
            \App\Observers\UserGroupUserPivotObserver::enableSync();
        }
    }
}
