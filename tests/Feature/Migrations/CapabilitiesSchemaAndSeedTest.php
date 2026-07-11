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
use App\Services\Agent\AgentTtlResolver;
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
            // Story 35.5 : la visionneuse expose un vrai off par suppression des 4
            // clés (marqueur $ensure). Seedée INACTIVE (gate) mais la DONNÉE porte
            // bien un off honnête — l'invariant s'applique à la spec, pas à is_active.
            'photo_viewer_restored',
            // Story 36.3 : lot Explorateur, tout opt-in, maps symétriques à
            // valeurs réelles (aucun $ensure dans ce lot).
            'explorer_sidebar_pins_hidden',
            'quick_access_hidden',
            'explorer_gallery_hidden',
            'quick_access_history_hidden',
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
            ], new AgentTtlResolver());

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

    // ── Story 35.3 (AC1) — borné des ruches par mécanisme ───────────────────

    #[Test]
    public function guard_refuses_a_registry_list_container_on_hku(): void
    {
        // HKU HORS scope registry_list (piège n°11) : violation NOMMÉE — le
        // fan-out d'une réconciliation de clé-conteneur multiplierait la
        // propriété de clé par N ruches sans consommateur connu.
        $guard = new \App\Services\Agent\Providers\CapabilitySpecCollisionGuard();

        $violations = $guard->violations([
            [
                'capability' => 'rogue_hku_list',
                'mechanism' => 'registry_list',
                'spec' => ['keys' => [[
                    'hive' => 'HKU',
                    'path' => 'Software\\Policies\\X\\List',
                    'entry_type' => 'REG_SZ',
                    'values' => ['on' => ['a']],
                ]]],
            ],
        ]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('rogue_hku_list', $violations[0]);
        self::assertStringContainsString('HKU non admise en registry_list', $violations[0]);
    }

    #[Test]
    public function guard_refuses_a_registry_scalar_with_an_unknown_hive(): void
    {
        // Borné registry ∈ {HKLM, HKCU, HKU} : une ruche inconnue ('HKX') ne
        // serait émise par AUCUN provider (clé silencieusement morte) → refus.
        $guard = new \App\Services\Agent\Providers\CapabilitySpecCollisionGuard();

        $violations = $guard->violations([
            [
                'capability' => 'typo_cap',
                'mechanism' => 'registry',
                'spec' => ['keys' => [[
                    'hive' => 'HKX',
                    'path' => 'Software\\X',
                    'name' => 'K',
                    'type' => 'REG_DWORD',
                    'value' => ['on' => 1],
                ]]],
            ],
        ]);

        self::assertCount(1, $violations);
        self::assertStringContainsString('typo_cap', $violations[0]);
        self::assertStringContainsString("ruche 'HKX' hors borné (HKLM|HKCU|HKU)", $violations[0]);
    }

    #[Test]
    public function hku_hkcu_twin_keys_on_the_same_path_name_are_not_a_violation(): void
    {
        // Piège n°5 (cas nominal numlock) : la double-clé HKU + HKCU sur le
        // MÊME {path|name} est VOULUE (SYSTEM couvre .DEFAULT/ruches, le
        // compagnon la session courante) — le guard ne la refuse PAS. Prouvé
        // sur la projection numlock RÉELLEMENT seedée (post-retrofit 35.3).
        $guard = new \App\Services\Agent\Providers\CapabilitySpecCollisionGuard();

        $numlock = array_values(array_filter(
            $this->seededWindowsProjections(),
            static fn (array $p): bool => $p['capability'] === 'numlock_on_logon',
        ));
        self::assertCount(1, $numlock, 'projection numlock présente');
        self::assertSame([], $guard->violations($numlock), 'double-clé HKU+HKCU = non-violation');
    }

    // ── Story 35.3 (AC3) — retrofit numlock : la clé HKU de l'écran de logon ─

    #[Test]
    public function numlock_on_logon_gains_the_hku_logon_screen_key(): void
    {
        // La spec passe à 2 clés : la clé HKCU du palier A INCHANGÉE + la clé
        // HKU miroir SYMÉTRIQUE (même path/name/type, même map on/off — si
        // l'UI propose off, off écrit une vraie valeur). Le path ne porte
        // JAMAIS `.DEFAULT\` (piège n°6 : le handler agent préfixe).
        $cap = Capability::query()->where('key', 'numlock_on_logon')->firstOrFail();

        $keys = $cap->projections()
            ->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec['keys'];
        self::assertCount(2, $keys, 'palier A (HKCU) + retrofit 35.3 (HKU)');

        // Clé HKCU du palier A : INCHANGÉE.
        self::assertSame('HKCU', $keys[0]['hive']);
        self::assertSame('Control Panel\\Keyboard', $keys[0]['path']);
        self::assertSame('InitialKeyboardIndicators', $keys[0]['name']);
        self::assertSame('REG_SZ', $keys[0]['type']);
        self::assertSame(['on' => '2', 'off' => '0'], $keys[0]['value']);

        // Clé HKU ajoutée : miroir symétrique, SANS préfixe .DEFAULT.
        self::assertSame('HKU', $keys[1]['hive']);
        self::assertSame('Control Panel\\Keyboard', $keys[1]['path'], 'path SANS .DEFAULT (le handler fan-out préfixe)');
        self::assertStringNotContainsString('.DEFAULT', $keys[1]['path']);
        self::assertSame('InitialKeyboardIndicators', $keys[1]['name']);
        self::assertSame('REG_SZ', $keys[1]['type']);
        self::assertSame($keys[0]['value'], $keys[1]['value'], 'maps HKU/HKCU jumelles VALEUR-CONSISTANTES (piège n°5)');
    }

    #[Test]
    public function numlock_hku_retrofit_migration_is_idempotent_and_reversible(): void
    {
        // Iso pattern retrofit_migration_is_idempotent_and_reversible (35.1) :
        // up() rejoué = aucun effet de bord ; down() restaure la spec 1-clé
        // (HKCU seule) du palier A ; up() après down() = état identique.
        $migration = require database_path('migrations/2026_07_03_160000_retrofit_numlock_hku_logon_screen.php');

        $snapshot = fn (): array => Capability::query()->where('key', 'numlock_on_logon')->firstOrFail()
            ->projections()->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec;

        // up() déjà joué par RefreshDatabase → le rejouer ne change RIEN.
        $before = $snapshot();
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() rejoué = aucun effet de bord');

        // down() restaure la spec 1-clé du palier A (HKCU intacte, HKU retirée).
        $migration->down();
        $reverted = $snapshot();
        self::assertCount(1, $reverted['keys'], 'down() restaure la spec 1-clé du palier A');
        self::assertSame('HKCU', $reverted['keys'][0]['hive']);
        self::assertSame($before['keys'][0], $reverted['keys'][0], 'la clé HKCU est byte-identique au palier A');

        // up() après down() = état retrofitté identique (rejouable).
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() après down() = état identique');
    }

    #[Test]
    public function numlock_on_logon_emits_hku_machine_and_hkcu_session_items_via_the_real_providers(): void
    {
        // Story 35.3 (AC3) — chaîne seed→retrofit→spec→expand→payload sur
        // données RÉELLES : effectif `on` ⇒ le provider Machine émet l'item
        // HKU ('2') ET le provider User émet l'item HKCU ('2') ; effectif
        // `off` (override de parc) ⇒ '0' des deux côtés.
        WorkstationGroupObserver::disableSync();

        try {
            $ws = Workstation::factory()->create();
            $parc = WorkstationGroup::factory()->logical()->create();
            $ws->groups()->attach($parc->id);
            $ctx = fn (): TargetContext => TargetContext::for($ws, null);

            $cap = Capability::query()->where('key', 'numlock_on_logon')->firstOrFail();
            self::assertSame('on', $cap->default_value, 'défaut seedé = on (broadcast flotte)');
            $forCap = fn ($items) => $items->filter(
                fn ($c): bool => (int) $c->sourceId === (int) $cap->id,
            )->values();

            // ── Effectif `on` (broadcast, aucun override) ───────────────────
            $machineOn = $forCap((new RegistryMachineCapabilityProvider())->itemsFor($ctx()));
            self::assertCount(1, $machineOn, 'le provider Machine émet la clé HKU');
            self::assertSame('HKU', $machineOn[0]->payload['hive']);
            self::assertSame('2', $machineOn[0]->payload['value']);

            $userOn = $forCap((new RegistryUserCapabilityProvider())->itemsFor($ctx()));
            self::assertCount(1, $userOn, 'le provider User émet la clé HKCU jumelle');
            self::assertSame('HKCU', $userOn[0]->payload['hive']);
            self::assertSame('2', $userOn[0]->payload['value']);

            // ── Effectif `off` (override de parc) : '0' des deux côtés ──────
            DB::table('capability_assignments')->insert([
                'capability_id' => $cap->id,
                'assignable_type' => WorkstationGroup::class,
                'assignable_id' => $parc->id,
                'value' => 'off',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $offMaille = fn ($items) => $forCap($items)->first(
                fn ($c): bool => $c->maille === \App\Enums\StateMaille::LogicalGroup,
            );
            $machineOff = $offMaille((new RegistryMachineCapabilityProvider())->itemsFor($ctx()));
            self::assertNotNull($machineOff);
            self::assertSame('HKU', $machineOff->payload['hive']);
            self::assertSame('0', $machineOff->payload['value'], 'off écrit une VRAIE valeur (map symétrique)');

            $userOff = $offMaille((new RegistryUserCapabilityProvider())->itemsFor($ctx()));
            self::assertNotNull($userOff);
            self::assertSame('HKCU', $userOff->payload['hive']);
            self::assertSame('0', $userOff->payload['value']);
        } finally {
            WorkstationGroupObserver::enableSync();
        }
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

        // FLIP 35.2 (FAIT) : support name:"" livré/prouvé par 35.2 (agent 2.4.0)
        // → gate levé par la migration 2026_07_03_150000 (is_active=true +
        // description réécrite, review 35.5 #3). L'assertion balisée a basculé.
        self::assertTrue(
            $cap->is_active,
            'gate levé : parseRegistrySpec (agent 2.4.0) accepte name=="" — '
            .'activation par la migration 2026_07_03_150000',
        );
        self::assertStringNotContainsString(
            'Inactive tant que',
            (string) $cap->description,
            'la migration de flip réécrit la description (le tooltip ne ment pas)',
        );

        // Preuve du gating par la MÉCANIQUE EXISTANTE : on RE-GATE via le down()
        // de la migration de flip (inverse exact), on prouve qu'armée `on` mais
        // INACTIVE le provider User n'émet RIEN (le filtre `is_active` est le
        // gate), puis on relève le gate (up()) — réversibilité du flip prouvée
        // au passage.
        $flip = require database_path('migrations/2026_07_03_150000_activate_capability_photo_viewer_restored.php');
        \App\Observers\WorkstationGroupObserver::disableSync();

        try {
            $flip->down();
            $gated = Capability::query()->where('key', 'photo_viewer_restored')->firstOrFail();
            self::assertFalse($gated->is_active, 'down() du flip re-gate la capacité');
            self::assertStringContainsString('Inactive tant que', (string) $gated->description);

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

            // Gate relevé : le flip est rejouable et l'état actif revient.
            $flip->up();
            self::assertTrue(Capability::query()->where('key', 'photo_viewer_restored')->firstOrFail()->is_active);
        } finally {
            \App\Observers\WorkstationGroupObserver::enableSync();
        }
    }

    #[Test]
    public function photo_viewer_restored_seed_is_idempotent_and_reversible(): void
    {
        // AC1 (idempotence/réversibilité) : la CHAÎNE seed (2026_07_03_130000) +
        // flip d'activation (2026_07_03_150000) rejouée = snapshot identique ;
        // down() du seed supprime capacité ET projection ; chaîne rejouée après
        // down() = identique. NB : le seed seul re-gate (updateOrInsert pose
        // is_active=false) — dans la réalité les migrations rejouent EN ORDRE,
        // le flip suit toujours le seed.
        $seed = require database_path('migrations/2026_07_03_130000_seed_capability_photo_viewer_restored.php');
        $flip = require database_path('migrations/2026_07_03_150000_activate_capability_photo_viewer_restored.php');

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

        // Chaîne déjà jouée par RefreshDatabase → la rejouer ne change RIEN
        // (updateOrInsert par key + par (capability_id, os, mechanism)).
        $before = $snapshot();
        $seed->up();
        $flip->up();
        self::assertSame($before, $snapshot(), 'chaîne seed+flip rejouée = aucun effet de bord');
        self::assertTrue($before['is_active'], 'état final = actif (flip 2026_07_03_150000)');

        // down() du seed : suppression par key → cascade FK sur la projection.
        $seed->down();
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

        // Chaîne rejouée après down() = état final identique.
        $seed->up();
        $flip->up();
        self::assertSame($before, $snapshot(), 'chaîne rejouée après down() = état identique');
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

    // ══════════════════════════════════════════════════════════════════════
    // Story 36.3 — Lot bibliothèque n°2 : capacités registre pures Explorateur
    // (zéro moteur — témoin de doctrine « capacité = donnée, coût marginal ≈ 0 »)
    // ══════════════════════════════════════════════════════════════════════

    #[Test]
    public function explorer_lot_is_seeded_with_expected_capabilities_and_keys(): void
    {
        // ── 1. explorer_sidebar_pins_hidden — portée Machine (HKLM, D3) ──────
        $sidebar = Capability::query()->where('key', 'explorer_sidebar_pins_hidden')->firstOrFail();
        self::assertSame('unmanaged', $sidebar->default_value, 'opt-in : rien en broadcast');
        self::assertSame(['unmanaged', 'on', 'off'], $sidebar->allowedOptionValues());
        self::assertSame('Non géré', $sidebar->optionLabel('unmanaged'));
        self::assertSame('Masqués', $sidebar->optionLabel('on'));
        self::assertSame('Affichés', $sidebar->optionLabel('off'));
        self::assertSame('Bureau', $sidebar->category);
        self::assertSame(['windows'], $sidebar->applies_to_os);
        self::assertFalse($sidebar->hasWarning());

        $sidebarKeys = $sidebar->projections()
            ->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec['keys'];
        self::assertCount(6, $sidebarKeys, 'les 6 dossiers utilisateur du volet');

        $expectedGuids = [
            '{f42ee2d3-909f-4907-8871-4c22fc0bf756}', // Documents
            '{0ddd015d-b06c-45d5-8c4c-f59713854639}', // Images
            '{a0c69a99-21c8-4671-8703-7934162fcf1d}', // Musique
            '{35286a68-3c57-41a1-bbb1-0eae73d76c95}', // Vidéos
            '{7d83ee9b-2244-4e70-b1f5-5393042af1e4}', // Téléchargements
            '{B4BFCC3A-DB2C-424C-B029-7FE99A87C641}', // Bureau
        ];
        foreach ($sidebarKeys as $i => $key) {
            self::assertSame('HKLM', $key['hive'], "clé {$i} : portée Machine (D3)");
            self::assertSame(
                'SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer\\FolderDescriptions\\'.$expectedGuids[$i].'\\PropertyBag',
                $key['path'],
                "clé {$i} : GUID exact (candidate décodage documentaire)",
            );
            self::assertSame('ThisPCPolicy', $key['name']);
            self::assertSame('REG_SZ', $key['type']);
            self::assertSame(['on' => 'Hide', 'off' => 'Show'], $key['value'], "clé {$i} : map symétrique, Show = défaut Windows");
        }

        // ── 2. quick_access_hidden — portées mixtes HKLM+HKCU (D4) ──────────
        $quickAccess = Capability::query()->where('key', 'quick_access_hidden')->firstOrFail();
        self::assertSame('unmanaged', $quickAccess->default_value);
        self::assertSame(['unmanaged', 'on', 'off'], $quickAccess->allowedOptionValues());
        self::assertSame('Masqué (volet réduit à Ce PC)', $quickAccess->optionLabel('on'));
        self::assertSame('Affiché', $quickAccess->optionLabel('off'));

        $quickAccessKeys = $quickAccess->projections()
            ->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec['keys'];
        self::assertCount(3, $quickAccessKeys, '1 clé HKLM (HubMode) + 2 clés HKCU (LaunchTo, CLSID Accueil)');

        self::assertSame('HKLM', $quickAccessKeys[0]['hive']);
        self::assertSame('SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Explorer', $quickAccessKeys[0]['path']);
        self::assertSame('HubMode', $quickAccessKeys[0]['name']);
        self::assertSame('REG_DWORD', $quickAccessKeys[0]['type']);
        self::assertSame(['on' => 1, 'off' => 0], $quickAccessKeys[0]['value']);

        self::assertSame('HKCU', $quickAccessKeys[1]['hive']);
        self::assertSame('Software\\Microsoft\\Windows\\CurrentVersion\\Explorer\\Advanced', $quickAccessKeys[1]['path']);
        self::assertSame('LaunchTo', $quickAccessKeys[1]['name']);
        self::assertSame('REG_DWORD', $quickAccessKeys[1]['type']);
        self::assertSame(['on' => 1, 'off' => 2], $quickAccessKeys[1]['value']);

        self::assertSame('HKCU', $quickAccessKeys[2]['hive']);
        self::assertSame('Software\\Classes\\CLSID\\{f874310e-b6b7-47dc-bc84-b9e6b38f5903}', $quickAccessKeys[2]['path']);
        self::assertSame('System.IsPinnedToNameSpaceTree', $quickAccessKeys[2]['name']);
        self::assertSame('REG_DWORD', $quickAccessKeys[2]['type']);
        self::assertSame(['on' => 0, 'off' => 1], $quickAccessKeys[2]['value']);
        self::assertNotSame(
            '{018D5C66-4533-4307-9B53-224DE2ED1FE6}',
            $quickAccessKeys[2]['path'],
            'CLSID Accueil DISTINCT du CLSID OneDrive (onedrive_hidden)',
        );

        // ── 3. explorer_gallery_hidden — portée Session (HKCU), candidat ────
        $gallery = Capability::query()->where('key', 'explorer_gallery_hidden')->firstOrFail();
        self::assertSame('unmanaged', $gallery->default_value);
        self::assertSame('Masquée', $gallery->optionLabel('on'));
        self::assertSame('Affichée', $gallery->optionLabel('off'));

        $galleryKeys = $gallery->projections()
            ->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec['keys'];
        self::assertCount(1, $galleryKeys);
        self::assertSame('HKCU', $galleryKeys[0]['hive']);
        self::assertSame('Software\\Classes\\CLSID\\{e88865ea-0e1c-4e20-9aa6-edcd0212c87c}', $galleryKeys[0]['path']);
        self::assertSame('System.IsPinnedToNameSpaceTree', $galleryKeys[0]['name']);
        self::assertSame('REG_DWORD', $galleryKeys[0]['type']);
        self::assertSame(['on' => 0, 'off' => 1], $galleryKeys[0]['value']);

        // ── 4. quick_access_history_hidden — portée Session (HKCU), candidat ─
        $history = Capability::query()->where('key', 'quick_access_history_hidden')->firstOrFail();
        self::assertSame('unmanaged', $history->default_value);
        self::assertSame('Masqué', $history->optionLabel('on'));
        self::assertSame('Affiché', $history->optionLabel('off'));

        $historyKeys = $history->projections()
            ->where('os', 'windows')->where('mechanism', 'registry')
            ->firstOrFail()->spec['keys'];
        self::assertCount(2, $historyKeys);
        self::assertSame('HKCU', $historyKeys[0]['hive']);
        self::assertSame('Software\\Microsoft\\Windows\\CurrentVersion\\Explorer', $historyKeys[0]['path']);
        self::assertSame('ShowRecent', $historyKeys[0]['name']);
        self::assertSame(['on' => 0, 'off' => 1], $historyKeys[0]['value']);
        self::assertSame('HKCU', $historyKeys[1]['hive']);
        self::assertSame('Software\\Microsoft\\Windows\\CurrentVersion\\Explorer', $historyKeys[1]['path']);
        self::assertSame('ShowFrequent', $historyKeys[1]['name']);
        self::assertSame(['on' => 0, 'off' => 1], $historyKeys[1]['value']);
    }

    #[Test]
    public function explorer_lot_migration_is_idempotent_and_reversible(): void
    {
        $migration = require database_path('migrations/2026_07_04_100000_seed_capabilities_explorer_lot.php');

        $keys = [
            'explorer_sidebar_pins_hidden',
            'quick_access_hidden',
            'explorer_gallery_hidden',
            'quick_access_history_hidden',
        ];

        $snapshot = fn (): array => Capability::query()
            ->whereIn('key', $keys)
            ->orderBy('key')
            ->get()
            ->map(fn (Capability $c): array => [
                'options' => $c->options,
                'default_value' => $c->default_value,
                'spec' => $c->projections()->firstOrFail()->spec,
            ])
            ->all();

        // up() déjà joué par RefreshDatabase → le rejouer ne change RIEN.
        $before = $snapshot();
        self::assertCount(4, $before);
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() rejoué = aucun effet de bord');

        // down() retire les 4 capacités (cascade projections/assignments)…
        $migration->down();
        self::assertSame([], $snapshot());

        // …et up() les re-seed à l'identique.
        $migration->up();
        self::assertSame($before, $snapshot(), 'up() après down() = état identique');
    }

    #[Test]
    public function explorer_lot_keys_do_not_collide_with_any_seeded_registry_key(): void
    {
        // AC2 — anti-collision structurel : identité normalisée `{hive|path|name}`
        // (iso AbstractCapabilityStateProvider::exclusiveKey) des clés `registry`
        // scalaires du lot 36.3, UNIQUE entre elles et DISJOINTE de toutes les
        // autres projections `registry`/`registry_list` du catalogue seedé.
        $lotKeys = [
            'explorer_sidebar_pins_hidden',
            'quick_access_hidden',
            'explorer_gallery_hidden',
            'quick_access_history_hidden',
        ];

        $normalize = fn (string $hive, string $path, string $name): string => strtolower($hive.'|'.$path.'|'.$name);

        /** @var array<string, list<string>> $identities identité → [capacité, …] */
        $identities = [];
        foreach ($this->seededWindowsProjections() as $projection) {
            if ($projection['mechanism'] === CapabilityProjection::MECHANISM_REGISTRY) {
                foreach ($projection['spec']['keys'] ?? [] as $key) {
                    $identity = $normalize((string) $key['hive'], (string) $key['path'], (string) ($key['name'] ?? ''));
                    $identities[$identity][] = $projection['capability'];
                }

                continue;
            }

            // registry_list : identité conteneur `{hive|path|}` (name vide —
            // insensible au name du scalaire, iso CapabilitySpecCollisionGuard).
            foreach ($projection['spec']['keys'] ?? [] as $key) {
                $identity = $normalize((string) $key['hive'], (string) $key['path'], '');
                $identities[$identity][] = $projection['capability'];
            }
        }

        // 1. Unicité INTERNE au lot : chaque identité du lot n'apparaît qu'une
        // fois PARMI LES CAPACITÉS DU LOT (les capacités hors-lot ne comptent
        // pas ici — testé au point 2).
        $lotOnly = array_filter(
            $identities,
            fn (array $capabilities): bool => count(array_unique($capabilities)) > 0
                && count(array_intersect($capabilities, $lotKeys)) > 0,
        );
        foreach ($lotOnly as $identity => $capabilities) {
            $lotCapabilities = array_values(array_intersect($capabilities, $lotKeys));
            self::assertCount(
                1,
                array_unique($lotCapabilities),
                "identité '{$identity}' : une seule capacité DU LOT ne doit la porter (trouvé : ".implode(', ', $lotCapabilities).')',
            );

            // Durcissement (review 36.3 #4) : aucune capacité DU LOT ne porte
            // DEUX FOIS la même identité `{hive|path|name}` (doublon
            // intra-capacité — invisible à `array_unique` ci-dessus). On compte
            // les occurrences BRUTES par capacité. Verrouille les futurs lots.
            foreach (array_count_values($lotCapabilities) as $capability => $occurrences) {
                self::assertSame(
                    1,
                    $occurrences,
                    "identité '{$identity}' : la capacité '{$capability}' la porte {$occurrences} fois (doublon intra-capacité)",
                );
            }

            // 2. Disjonction avec le RESTE du catalogue : aucune capacité
            // hors-lot ne partage cette identité avec une capacité du lot.
            $foreignCapabilities = array_values(array_diff(array_unique($capabilities), $lotKeys));
            self::assertSame(
                [],
                $foreignCapabilities,
                "identité '{$identity}' (lot 36.3) COLLISIONNE avec une capacité hors-lot : ".implode(', ', $foreignCapabilities),
            );
        }

        // 3. Le CLSID OneDrive n'apparaît dans AUCUNE clé du lot.
        foreach ($this->seededWindowsProjections() as $projection) {
            if (! in_array($projection['capability'], $lotKeys, true)) {
                continue;
            }
            foreach ($projection['spec']['keys'] ?? [] as $key) {
                self::assertStringNotContainsStringIgnoringCase(
                    '{018D5C66-4533-4307-9B53-224DE2ED1FE6}',
                    (string) $key['path'],
                    "{$projection['capability']} ne doit JAMAIS porter le CLSID OneDrive (possédé par onedrive_hidden)",
                );
            }
        }
    }

    #[Test]
    public function quick_access_hidden_emits_split_machine_and_session_items_via_the_real_providers(): void
    {
        // AC4 — chaîne seed→spec→expand→payload sur données RÉELLES (pattern
        // numlock_on_logon_emits_hku_machine_and_hkcu_session_items_via_the_real_providers) :
        // ruches mixtes HKLM+HKCU d'une même projection, chaque provider ne voit
        // que la sienne.
        WorkstationGroupObserver::disableSync();

        try {
            $ws = Workstation::factory()->create();
            $parc = WorkstationGroup::factory()->logical()->create();
            $ws->groups()->attach($parc->id);
            $ctx = fn (): TargetContext => TargetContext::for($ws, null);

            $cap = Capability::query()->where('key', 'quick_access_hidden')->firstOrFail();
            self::assertSame('unmanaged', $cap->default_value, 'défaut seedé = unmanaged (Broadcast n\'émet rien)');
            $forCap = fn ($items) => $items->filter(
                fn ($c): bool => (int) $c->sourceId === (int) $cap->id,
            )->values();

            // ── Défaut unmanaged (sans override) : AUCUN item des 4 capacités ──
            $machineProvider = new RegistryMachineCapabilityProvider();
            $userProvider = new RegistryUserCapabilityProvider();
            foreach ([
                'explorer_sidebar_pins_hidden',
                'quick_access_hidden',
                'explorer_gallery_hidden',
                'quick_access_history_hidden',
            ] as $key) {
                $otherCap = Capability::query()->where('key', $key)->firstOrFail();
                $forOther = fn ($items) => $items->filter(fn ($c): bool => (int) $c->sourceId === (int) $otherCap->id);
                self::assertCount(0, $forOther($machineProvider->itemsFor($ctx())), "{$key} : Machine muet par défaut");
                self::assertCount(0, $forOther($userProvider->itemsFor($ctx())), "{$key} : Session muette par défaut");
            }

            // ── Override `on` de parc ────────────────────────────────────────
            DB::table('capability_assignments')->insert([
                'capability_id' => $cap->id,
                'assignable_type' => WorkstationGroup::class,
                'assignable_id' => $parc->id,
                'value' => 'on',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $machineOn = $forCap($machineProvider->itemsFor($ctx()));
            self::assertCount(1, $machineOn, 'Machine : 1 item HubMode');
            self::assertSame(['hive', 'path', 'name', 'type', 'value'], array_keys($machineOn[0]->payload));
            self::assertSame('HKLM', $machineOn[0]->payload['hive']);
            self::assertSame('HubMode', $machineOn[0]->payload['name']);
            self::assertSame(1, $machineOn[0]->payload['value']);

            $userOn = $forCap($userProvider->itemsFor($ctx()));
            self::assertCount(2, $userOn, 'Session : 2 items (LaunchTo + CLSID Accueil)');
            foreach ($userOn as $c) {
                self::assertSame(['hive', 'path', 'name', 'type', 'value'], array_keys($c->payload));
                self::assertSame('HKCU', $c->payload['hive']);
            }
            $byName = $userOn->keyBy(fn ($c): string => $c->payload['name']);
            self::assertSame(1, $byName['LaunchTo']->payload['value']);
            self::assertSame(0, $byName['System.IsPinnedToNameSpaceTree']->payload['value']);

            // ── Override `off` : MÊMES identités de clé, valeurs réelles 0/2/1 ──
            DB::table('capability_assignments')
                ->where('capability_id', $cap->id)
                ->update(['value' => 'off']);

            $machineOff = $forCap($machineProvider->itemsFor($ctx()));
            self::assertCount(1, $machineOff);
            self::assertSame('HubMode', $machineOff[0]->payload['name']);
            self::assertSame(0, $machineOff[0]->payload['value'], 'off écrit une VRAIE valeur (map symétrique)');

            $userOff = $forCap($userProvider->itemsFor($ctx()))->keyBy(fn ($c): string => $c->payload['name']);
            self::assertSame(2, $userOff['LaunchTo']->payload['value']);
            self::assertSame(1, $userOff['System.IsPinnedToNameSpaceTree']->payload['value']);

            // ── explorer_gallery_hidden `on` : 0 item Machine, 1 item Session ──
            $gallery = Capability::query()->where('key', 'explorer_gallery_hidden')->firstOrFail();
            DB::table('capability_assignments')->insert([
                'capability_id' => $gallery->id,
                'assignable_type' => WorkstationGroup::class,
                'assignable_id' => $parc->id,
                'value' => 'on',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $forGallery = fn ($items) => $items->filter(fn ($c): bool => (int) $c->sourceId === (int) $gallery->id)->values();

            self::assertCount(0, $forGallery($machineProvider->itemsFor($ctx())), 'galerie : aucune clé HKLM → Machine muet');
            $galleryUser = $forGallery($userProvider->itemsFor($ctx()));
            self::assertCount(1, $galleryUser, 'galerie : 1 item Session');
            self::assertSame('HKCU', $galleryUser[0]->payload['hive']);
            self::assertSame('System.IsPinnedToNameSpaceTree', $galleryUser[0]->payload['name']);
            self::assertSame(0, $galleryUser[0]->payload['value']);
        } finally {
            WorkstationGroupObserver::enableSync();
        }
    }
}
